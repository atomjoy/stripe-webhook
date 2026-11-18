<?php

namespace App\Services;

use App\Enums\PaymentStatus;
use App\Models\Order;
use Illuminate\Support\Facades\URL;
use Stripe\Checkout\Session;
use Stripe\Customer;
use Stripe\Exception\InvalidRequestException;
use Stripe\Stripe;
use Throwable;

class StripePaymentService
{
    public function __construct()
    {
        Stripe::setApiKey(config('shop.stripe.secret'));
    }

    public function createCheckoutSession(Order $order, ?string $successUrl = null): string
    {
        $order->loadMissing('items');

        // 1. Pozycje koszyka (z uwzględnieniem rabatu z kuponu dla całej linii)
        $lineItems = $order->items->map(function ($item) {
            $name = $item->quantity > 1
                ? "{$item->product_name} (× {$item->quantity})"
                : $item->product_name;

            return [
                'price_data' => [
                    'currency'     => 'pln',
                    'product_data' => [
                        'name'        => $name,
                        'description' => "SKU: {$item->sku}",
                    ],
                    'unit_amount' => (int) $item->total,
                ],
                'quantity' => 1,
            ];
        })->values()->toArray();

        // 2. Opakowania (jeśli występują)
        if ($order->pack_amount > 0) {
            $lineItems[] = [
                'price_data' => [
                    'currency'     => 'pln',
                    'product_data' => [
                        'name' => 'Opakowania',
                    ],
                    'unit_amount' => (int) $order->pack_amount,
                ],
                'quantity' => 1,
            ];
        }

        // 3. Koszt dostawy (jeśli płatna)
        if ($order->shipping_amount > 0) {
            $lineItems[] = [
                'price_data' => [
                    'currency'     => 'pln',
                    'product_data' => [
                        'name' => "Dostawa ({$order->shipping_method})",
                    ],
                    'unit_amount' => (int) $order->shipping_amount,
                ],
                'quantity' => 1,
            ];
        }

        // 4. Podpisany URL powrotu (działa zarówno dla gości, jak i zalogowanych)
        $resolvedSuccessUrl = $successUrl ?? URL::temporarySignedRoute(
            'checkout.success',
            now()->addHours(24),
            ['order' => $order->uuid]
        );

        // 5. Dane klienta Stripe (powiązanie z kontem User jeśli istnieje, w przeciwnym razie e-mail gościa)
        $customerId = $this->getOrCreateCustomer($order);
        $customerPayload = $customerId
            ? ['customer' => $customerId]
            : ['customer_email' => $order->customer_email];

        $session = Session::create([
            ...$customerPayload,
            'locale'              => 'pl',
            'mode'                => 'payment',
            'expires_at'          => now()->addMinutes(60)->timestamp,
            'line_items'          => $lineItems,
            'metadata'            => [
                'order_id'     => (string) $order->id,
                'order_uuid'   => (string) $order->uuid,
                'order_number' => (string) ($order->order_number ?? $order->id),
            ],
            'payment_intent_data' => [
                'description' => "Zamówienie #" . ($order->order_number ?? $order->id),
                'metadata'    => [
                    'order_id'     => (string) $order->id,
                    'order_uuid'   => (string) $order->uuid,
                    'order_number' => (string) ($order->order_number ?? $order->id),
                ],
            ],
            'success_url'         => $resolvedSuccessUrl,
            'cancel_url'          => $resolvedSuccessUrl,
        ]);

        $order->update(['payment_session_id' => $session->id]);

        return $session->url;
    }

    public function getOrCreateCheckoutUrl(Order $order, ?string $successUrl = null): string
    {
        if ($order->payment_session_id) {
            try {
                $existingSession = Session::retrieve($order->payment_session_id);

                // Jeśli sesja jest nadal otwarta i nie wygasła używa tej samej transakcji!
                if ($existingSession->status === 'open' && !empty($existingSession->url)) {
                    return $existingSession->url;
                }

                // Jeśli w międzyczasie została już opłacona synchronizuje status i nie tworzy nowej
                if ($existingSession->payment_status === 'paid') {
                    $this->syncPaymentStatus($order);

                    return $successUrl ?? URL::temporarySignedRoute(
                        'checkout.success',
                        now()->addHours(24),
                        ['order' => $order->uuid]
                    );
                }
            } catch (Throwable) {
                // Jeśli sesja nie istnieje po stronie Stripe, tworzymy nową poniżej
            }
        }

        return $this->createCheckoutSession($order, $successUrl);
    }

    // Przy retry zawsze nowa płatność
    public function getOrCreateCheckoutUrlWithNewSession(Order $order, ?string $successUrl = null): string
    {
        $oldSessionId = $order->payment_session_id;

        // Najpierw tworzymy nową sesję i zapisujemy jej nowe ID w bazie
        $newUrl = $this->createCheckoutSession($order, $successUrl);

        // Dopiero teraz unieważniamy starą sesję — webhook zobaczy już nowe ID w bazie i ją zignoruje
        if ($oldSessionId) {
            try {
                $existingSession = Session::retrieve($oldSessionId);

                if ($existingSession->status === 'open') {
                    $existingSession->expire();
                }
            } catch (Throwable) {
                // Ignorujemy błąd, jeśli stara sesja już wygasła
            }
        }

        return $newUrl;
    }

    public function getOrCreateCustomer(Order $order): ?string
    {
        $user = $order->user;

        if (!$user) {
            return null;
        }

        if ($user->stripe_customer_id) {
            try {
                $customer = Customer::retrieve($user->stripe_customer_id);

                if (!isset($customer->deleted) || !$customer->deleted) {
                    return $user->stripe_customer_id;
                }
            } catch (InvalidRequestException) {
                // Klient nie istnieje w obecnym środowisku (Test vs Live)
            }
        }

        $customer = Customer::create([
            'email' => $user->email ?? $order->customer_email,
            'name'  => $order->customer_name,
        ]);

        $user->update(['stripe_customer_id' => $customer->id]);

        return $customer->id;
    }

    public function syncPaymentStatus(Order $order): void
    {
        if ($order->payment_status !== PaymentStatus::PENDING || !$order->payment_session_id) {
            return;
        }

        try {
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
