<?php

namespace App\Http\Controllers;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\StockMovementType;
use App\Mail\OrderPaidMail;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Checkout\Session;
use Stripe\Stripe;
use Stripe\Webhook;
use Throwable;
use UnexpectedValueException;

class StripeWebhookController extends Controller
{
    public function handle(Request $request): Response
    {
        $payload = $request->getContent();
        $sigHeader = $request->header('Stripe-Signature');
        $webhookSecret = config('shop.stripe.webhook_secret');

        try {
            $event = Webhook::constructEvent($payload, $sigHeader, $webhookSecret);
        } catch (UnexpectedValueException $e) {
            return response('Invalid payload', 400);
        } catch (SignatureVerificationException $e) {
            return response('Invalid signature', 400);
        }

        try {
            match ($event->type) {
                'checkout.session.completed',
                'checkout.session.async_payment_succeeded' => $this->handleCompleted($event->data->object),
                'checkout.session.expired',
                'checkout.session.async_payment_failed' => $this->handleExpired($event->data->object),
                'charge.refunded' => $this->handleRefunded($event->data->object),
                'charge.dispute.created' => $this->handleDispute($event->data->object),
                default => null,
            };

            return response('Webhook handled', 200);
        } catch (Throwable $e) {
            $this->logStripeError($e->getMessage(), [
                'event_id'   => $event->id ?? null,
                'event_type' => $event->type ?? null,
            ]);

            return response('Server error', 500);
        }
    }

    private function handleCompleted(object $session): void
    {
        $orderUuid = $this->extractOrderUuid($session);

        if (!$orderUuid || $session->payment_status !== 'paid') {
            return;
        }

        DB::transaction(function () use ($orderUuid, $session) {
            $order = Order::where('uuid', $orderUuid)->lockForUpdate()->first();

            if (!$order || $order->payment_session_id !== $session->id) {
                return;
            }

            if ($order->payment_status === PaymentStatus::PENDING) {
                $order->update([
                    'payment_status' => PaymentStatus::PAID,
                    'status' => $order->status,
                    'paid_at' => now(),
                    'payment_intent_id' => $session->payment_intent ?? null,
                ]);

                DB::afterCommit(function () use ($order) {
                    // Z kolejkowaniem w klasie koniecznie !!!
                    Mail::to($order->customer_email)->send(new OrderPaidMail($order));
                });
            }
        });
    }

    private function handleExpired(object $session): void
    {
        $orderUuid = $this->extractOrderUuid($session);

        if (!$orderUuid) {
            return;
        }

        DB::transaction(function () use ($orderUuid, $session) {
            $order = Order::with('items.variant')
                ->where('uuid', $orderUuid)
                ->lockForUpdate()
                ->first();

            if (!$order || $order->payment_status !== PaymentStatus::PENDING || $order->payment_session_id !== $session->id) {
                return;
            }

            // Zwrot stanów magazynowych
            foreach ($order->items as $item) {
                if ($item->variant) {
                    $item->variant->updateStock(
                        $item->quantity,
                        StockMovementType::EXPIRED,
                        "Order payment expired #{$order->order_number}"
                    );
                }
            }

            // Zwrot limitu użyć kuponu (jeśli był przypisany do zamówienia)
            if ($order->coupon_code) {
                Coupon::where('code', $order->coupon_code)
                    ->where('used_count', '>', 0)
                    ->decrement('used_count');
            }

            // Aktualizacja statusów zamówienia
            $order->update([
                'payment_status' => PaymentStatus::EXPIRED,
                'status'         => OrderStatus::CANCELLED,
            ]);
        });
    }

    private function handleRefunded(object $charge): void
    {
        $paymentIntentId = $charge->payment_intent ?? null;

        if (!$paymentIntentId) {
            $this->logStripeError('Missing payment_intent in charge.refunded', [
                'charge_id' => $charge->id ?? null,
            ]);
            return;
        }

        DB::transaction(function () use ($paymentIntentId, $charge) {
            $order = Order::where('payment_intent_id', $paymentIntentId)
                ->lockForUpdate()
                ->first();

            if (!$order) {
                $this->logStripeError('Order not found for refund', [
                    'payment_intent_id' => $paymentIntentId,
                    'charge_id' => $charge->id ?? null,
                ]);
                return;
            }

            // Zwrot możliwy tylko dla opłaconych lub częściowo zwróconych zamówień
            if (!in_array($order->payment_status, [PaymentStatus::PAID, PaymentStatus::PARTIALLY_REFUNDED], true)) {
                return;
            }

            if ($charge->amount_refunded === $charge->amount) {
                $order->update([
                    'payment_status' => PaymentStatus::REFUNDED,
                    'refunded_at' => now(),
                ]);
                return;
            }

            if ($charge->amount_refunded > 0 && $order->payment_status !== PaymentStatus::PARTIALLY_REFUNDED) {
                $order->update([
                    'payment_status' => PaymentStatus::PARTIALLY_REFUNDED,
                ]);
            }
        });
    }

    private function handleDispute(object $dispute): void
    {
        $paymentIntentId = $dispute->payment_intent ?? null;

        if (!$paymentIntentId) {
            return;
        }

        Order::where('payment_intent_id', $paymentIntentId)
            ->whereIn('payment_status', [PaymentStatus::PAID, PaymentStatus::PARTIALLY_REFUNDED])
            ->update(['payment_status' => PaymentStatus::DISPUTED]);
    }

    private function extractOrderUuid(object $session): ?string
    {
        $orderUuid = $session->metadata?->order_uuid ?? null;

        if (!$orderUuid) {
            $this->logStripeError('Missing order_uuid in session metadata', [
                'session_id' => $session->id ?? null,
            ]);
        }

        return $orderUuid;
    }

    private function logStripeError(string $message, array $context = []): void
    {
        Log::build([
            'driver' => 'daily',
            'path' => storage_path('logs/webhook/stripe/error.log'),
            'days' => 30,
        ])->error("[Webhook Stripe Error] {$message}", $context);
    }

    // Pobieranie danych sesji z Stripe API
    public static function retrieveSession(object $event): Session
    {
        Stripe::setApiKey(config('shop.stripe.secret'));

        return Session::retrieve($event->data->object->id);
    }

    // Aktualizacja statusu z Stripe API
    public static function syncPaymentStatus(Order $order): void
    {
        if ($order->payment_status !== PaymentStatus::PENDING || !$order->payment_session_id) {
            return;
        }

        try {
            Stripe::setApiKey(config('shop.stripe.secret'));

            $session = Session::retrieve($order->payment_session_id);

            if ($session->payment_status === 'paid') {
                $order->update([
                    'payment_status'    => PaymentStatus::PAID,
                    'paid_at'           => now(),
                    'payment_intent_id' => $session->payment_intent ?? null,
                ]);
            }
        } catch (Throwable) {
            // Jeśli chwilowy błąd sieciowy, webhook lub usePoll dokończy aktualizację
        }
    }
}
