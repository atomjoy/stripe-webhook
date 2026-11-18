<?php

namespace App\Traits;

use App\Enums\PaymentStatus;
use App\Mail\OrderPaidMail;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

// Przykład tylko nie używany
trait HandlesStripeWebhooks
{
	/**
	 * Główny dyspozytor zdarzeń ze Stripe.
	 */
	protected function dispatchStripeEvent(object $event): void
	{
		match ($event->type) {
			'checkout.session.completed',
			'checkout.session.async_payment_succeeded' => $this->handleSessionCompleted($event->data->object),

			'checkout.session.expired',
			'checkout.session.async_payment_failed' => $this->handleSessionExpiredOrFailed($event->data->object),

			'payment_intent.succeeded' => $this->handlePaymentIntentSucceeded($event->data->object),
			'payment_intent.payment_failed' => $this->handlePaymentIntentFailed($event->data->object),
			'payment_intent.canceled' => $this->handlePaymentIntentCanceled($event->data->object),

			default => null,
		};
	}

	/**
	 * Obsługa zakończenia sesji Checkout (zarówno manual capture, jak i natychmiastowej płatności).
	 */
	private function handleSessionCompleted(object $session): void
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

			if (!in_array($order->payment_status, [PaymentStatus::PENDING, PaymentStatus::FAILED], true)) {
				return;
			}

			// Przypadek 1: Płatność pobrana od razu
			if ($session->payment_status === 'paid') {
				$this->markOrderAsPaid($order, $session->payment_intent ?? null);
				return;
			}

			// Przypadek 2: Środki tylko zablokowane (manual capture)
			$order->update([
				'payment_status' => PaymentStatus::WAITING_FOR_CONFIRMATION,
				'payment_intent_id' => $session->payment_intent ?? null,
			]);
		});
	}

	/**
	 * Obsługa wygaśnięcia sesji Checkout lub nieudanej płatności asynchronicznej.
	 */
	private function handleSessionExpiredOrFailed(object $session): void
	{
		$orderUuid = $this->extractOrderUuid($session);

		if (!$orderUuid) {
			return;
		}

		DB::transaction(function () use ($orderUuid, $session) {
			$order = Order::where('uuid', $orderUuid)->lockForUpdate()->first();

			if (!$order || $order->payment_session_id !== $session->id) {
				return;
			}

			if ($order->payment_status === PaymentStatus::PENDING) {
				$order->update([
					'payment_status' => PaymentStatus::FAILED,
				]);
			}
		});
	}

	/**
	 * Obsługa faktycznego pobrania środków (np. po kliknięciu "Zaakceptuj" / capture).
	 */
	private function handlePaymentIntentSucceeded(object $paymentIntent): void
	{
		DB::transaction(function () use ($paymentIntent) {
			$order = $this->findOrderByPaymentIntent($paymentIntent);

			if (!$order) {
				return;
			}

			$allowedStatuses = [
				PaymentStatus::WAITING_FOR_CONFIRMATION,
				PaymentStatus::PENDING,
				PaymentStatus::FAILED,
			];

			if (in_array($order->payment_status, $allowedStatuses, true)) {
				$this->markOrderAsPaid($order, $paymentIntent->id);
			}
		});
	}

	/**
	 * Obsługa błędu karty / odrzucenia przez bank klienta.
	 */
	private function handlePaymentIntentFailed(object $paymentIntent): void
	{
		DB::transaction(function () use ($paymentIntent) {
			$order = $this->findOrderByPaymentIntent($paymentIntent);

			if (!$order) {
				return;
			}

			if ($order->payment_status === PaymentStatus::PENDING) {
				$order->update([
					'payment_status' => PaymentStatus::FAILED,
					'payment_intent_id' => $paymentIntent->id,
				]);
			}
		});
	}

	/**
	 * Obsługa anulowania blokady (kliknięcie "Odrzuć" lub wygaśnięcie blokady po 7 dniach).
	 */
	private function handlePaymentIntentCanceled(object $paymentIntent): void
	{
		DB::transaction(function () use ($paymentIntent) {
			$order = $this->findOrderByPaymentIntent($paymentIntent);

			if (!$order) {
				return;
			}

			if ($order->payment_status === PaymentStatus::WAITING_FOR_CONFIRMATION) {
				$order->update([
					'payment_status' => PaymentStatus::REJECTED,
				]);
			}
		});
	}

	/**
	 * Wspólna metoda oznaczająca zamówienie jako opłacone i wysyłająca e-mail po commicie.
	 */
	private function markOrderAsPaid(Order $order, ?string $paymentIntentId): void
	{
		$order->update([
			'payment_status' => PaymentStatus::PAID,
			'paid_at' => now(),
			'payment_intent_id' => $paymentIntentId ?? $order->payment_intent_id,
		]);

		DB::afterCommit(function () use ($order) {
			Mail::to($order->customer_email)->send(new OrderPaidMail($order));
		});
	}

	/**
	 * Wyszukuje zamówienie z blokadą wiersza po payment_intent_id lub uuid z metadanych.
	 */
	private function findOrderByPaymentIntent(object $paymentIntent): ?Order
	{
		$orderUuid = $paymentIntent->metadata?->order_uuid ?? null;

		return Order::where('payment_intent_id', $paymentIntent->id)
			->when($orderUuid, fn($query) => $query->orWhere('uuid', $orderUuid))
			->lockForUpdate()
			->first();
	}

	/**
	 * Wyciąga UUID zamówienia z metadanych dowolnego obiektu Stripe (Session lub PaymentIntent).
	 */
	private function extractOrderUuid(object $stripeObject): ?string
	{
		$orderUuid = $stripeObject->metadata?->order_uuid ?? null;

		if (!$orderUuid) {
			$this->logStripeError('Missing order_uuid in Stripe object metadata', [
				'object_type' => $stripeObject->object ?? null,
				'object_id' => $stripeObject->id ?? null,
			]);
		}

		return $orderUuid;
	}

	/**
	 * Logowanie błędów webhooków.
	 */
	private function logStripeError(string $message, array $context = []): void
	{
		Log::error('[Stripe Webhook] ' . $message, $context);
	}
}

/*
// How to use

namespace App\Http\Controllers;

use App\Traits\HandlesStripeWebhooks;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;
use UnexpectedValueException;

class StripeWebhookController extends Controller
{
    use HandlesStripeWebhooks;

    public function __invoke(Request $request): Response
    {
        $payload = $request->getContent();
        $sigHeader = $request->header('Stripe-Signature');
        $webhookSecret = config('cashier.webhook.secret'); // lub env('STRIPE_WEBHOOK_SECRET')

        try {
            $event = Webhook::constructEvent($payload, $sigHeader, $webhookSecret);
        } catch (UnexpectedValueException|SignatureVerificationException $e) {
            return response('Invalid payload or signature', 400);
        }

        $this->dispatchStripeEvent($event);

        return response('Webhook handled', 200);
    }
}
*/
