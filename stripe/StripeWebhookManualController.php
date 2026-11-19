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

// Dizała dla mode: payment checkout.session z manual_capture nie zmieniaj!
class StripeWebhookManualController extends Controller
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
                // Formularz Checkout (powiązanie sesji z PaymentIntent lub zamówienia 0 zł)
                'checkout.session.completed' => $this->handleCompleted($event->data->object),
                'checkout.session.expired' => $this->handleExpired($event->data->object),
                // Zwroty i spory (chargebacks)
                'charge.refunded' => $this->handleRefunded($event->data->object),
                'charge.dispute.created' => $this->handleDispute($event->data->object),
                // Główny cykl życia płatności (PaymentIntent)
                'payment_intent.amount_capturable_updated' => $this->handlePaymentIntentManualCapture($event->data->object),
                'payment_intent.succeeded' => $this->handlePaymentIntentSucceeded($event->data->object),
                'payment_intent.canceled' => $this->handlePaymentIntentCanceled($event->data->object),
                'payment_intent.payment_failed' => $this->handlePaymentIntentFailed($event->data->object),
                // Dla reszty potwierdzasz (nieostotne)
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

    // Webhook: checkout.session.completed (klient zakończył formularz Stripe Checkout, zapisuje powiązanie z PaymentIntent)
    private function handleCompleted(object $session): void
    {
        $orderUuid = $this->extractOrderUuid($session);

        if (!$orderUuid || $session->status !== 'complete') {
            return;
        }

        DB::transaction(function () use ($orderUuid, $session) {
            $order = Order::where('uuid', $orderUuid)->lockForUpdate()->first();

            if (!$order || $order->payment_session_id !== $session->id) {
                return;
            }

            // Wyjątek: zamówienie darmowe (np. kod rabatowy 100%, brak PaymentIntent)
            if ($session->payment_status === 'no_payment_required' && in_array($order->payment_status, [PaymentStatus::PENDING, PaymentStatus::FAILED], true)) {
                $order->update([
                    'payment_status' => PaymentStatus::PAID,
                    'payment_error' => null,
                    'paid_at' => now(),
                ]);

                DB::afterCommit(fn() => Mail::to($order->customer_email)->send(new OrderPaidMail($order)));

                return;
            }

            // Dla wszystkich płatnych zamówień zapisujemy tylko ID intencji (jeśli jeszcze nie ma)
            if (!$order->payment_intent_id && $session->payment_intent) {
                $order->update(['payment_intent_id' => $session->payment_intent]);
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

            if (!$order || !in_array($order->payment_status, [PaymentStatus::PENDING, PaymentStatus::FAILED], true) || $order->payment_session_id !== $session->id) {
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
                    'status' => OrderStatus::REFUNDED,
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

    // Webhook: payment_intent.canceled (wykona się po Twoim "Odrzuć" lub po 7 dniach bez decyzji)
    private function handlePaymentIntentCanceled(object $paymentIntent): void
    {
        DB::transaction(function () use ($paymentIntent) {
            $order = $this->findOrderForPaymentIntent($paymentIntent, ['items.variant']);

            if (!$order) {
                return;
            }

            if ($order->payment_status === PaymentStatus::WAITING_FOR_CONFIRMATION) {
                // Zwrot stanów magazynowych (tak samo jak przy expired)
                foreach ($order->items as $item) {
                    if ($item->variant) {
                        $item->variant->updateStock(
                            $item->quantity,
                            StockMovementType::EXPIRED,
                            "Order payment rejected/canceled #{$order->order_number}"
                        );
                    }
                }

                // Zwrot limitu użyć kuponu
                if ($order->coupon_code) {
                    Coupon::where('code', $order->coupon_code)
                        ->where('used_count', '>', 0)
                        ->decrement('used_count');
                }

                $order->update([
                    'payment_status' => PaymentStatus::REJECTED,
                    'status'         => OrderStatus::CANCELLED,
                ]);
            }
        });
    }

    // Webhook: payment_intent.succeeded Jedyne miejsce ustawiające PAID dla płatnych zamówień (wykona się po kliknięciu "Akceptuj")
    private function handlePaymentIntentSucceeded(object $paymentIntent): void
    {
        DB::transaction(function () use ($paymentIntent) {
            $order = $this->findOrderForPaymentIntent($paymentIntent);

            if ($order && in_array($order->payment_status, [
                PaymentStatus::PENDING,
                PaymentStatus::WAITING_FOR_CONFIRMATION,
                PaymentStatus::FAILED,
            ], true)) {
                $order->update([
                    'payment_status' => PaymentStatus::PAID,
                    'payment_intent_id' => $paymentIntent->id,
                    'payment_error' => null,
                    'paid_at' => now(),
                ]);

                DB::afterCommit(fn() => Mail::to($order->customer_email)->send(new OrderPaidMail($order)));
            }
        });
    }

    // Webhook: payment_intent.payment_failed (Bląd przy płatności)
    private function handlePaymentIntentFailed(object $paymentIntent): void
    {
        DB::transaction(function () use ($paymentIntent) {
            $order = $this->findOrderForPaymentIntent($paymentIntent);

            if (!$order) {
                return;
            }

            if (in_array($order->payment_status, [
                PaymentStatus::PENDING, 
                PaymentStatus::WAITING_FOR_CONFIRMATION,
                PaymentStatus::FAILED
            ], true)) {
                $order->update([
                    'payment_status'    => PaymentStatus::FAILED,
                    'payment_intent_id' => $paymentIntent->id,
                    'payment_error'     => $paymentIntent->last_payment_error->message ?? null,
                ]);
            }
        });
    }

    // Webhook: payment_intent.amount_capturable_updated Ustawia WAITING_FOR_CONFIRMATION (odpala się TYLKO przy manual capture)
    private function handlePaymentIntentManualCapture(object $paymentIntent): void
    {
        if ($paymentIntent->status !== 'requires_capture' || $paymentIntent->amount_capturable <= 0) {
            return;
        }

        DB::transaction(function () use ($paymentIntent) {
            $order = $this->findOrderForPaymentIntent($paymentIntent);

            if ($order && in_array($order->payment_status, [PaymentStatus::PENDING, PaymentStatus::FAILED], true)) {
                $order->update([
                    'payment_status' => PaymentStatus::WAITING_FOR_CONFIRMATION,
                    'payment_intent_id' => $paymentIntent->id,
                    'payment_error' => null,
                ]);
            }
        });
    }

    private function extractOrderUuid(object $stripeObject): ?string
    {
        $orderUuid = $stripeObject->metadata?->order_uuid ?? null;

        if (!$orderUuid) {
            $this->logStripeError('Missing order_uuid in session metadata', [
                'object_type' => $stripeObject->object ?? null,
                'object_id'   => $stripeObject->id ?? null,
            ]);
        }

        return $orderUuid;
    }

    private function findOrderForPaymentIntent(object $paymentIntent, array $with = []): ?Order
    {
        $orderUuid = $this->extractOrderUuid($paymentIntent);

        return Order::with($with)
            ->where(function ($query) use ($paymentIntent, $orderUuid) {
                $query->where('payment_intent_id', $paymentIntent->id)
                    ->when($orderUuid, fn($q) => $q->orWhere('uuid', $orderUuid));
            })
            ->lockForUpdate()
            ->first();
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
