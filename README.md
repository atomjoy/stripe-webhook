# Stripe Webhook

Jak dodać webhook do Stripe i testować lokalnie.

## Webhooks

Dodaj webhook: **`https://example.com/webhooks/stripe`** i eventy w panelu. 
Przełącz na sandbox w pasku wyszukiwania na górze: **Stripe Api** i potem 
**Developer** do przejścia na webhooks panel. 

```php
$events = [
  'payment_intent.payment_failed',
  'payment_intent.succeeded',
  'payment_intent.canceled',
  'payment_intent.requires_action',
  'checkout.session.completed',
  'checkout.session.async_payment_succeeded',
  'checkout.session.expired',
  'checkout.session.async_payment_failed',
  'charge.refunded',
  'charge.dispute.created',
  'customer.subscription.created',
  'customer.subscription.deleted',
  'customer.subscription.updated',
];
```

## Pobierz klucz i secrety

Zapisz wszystko w pliku .env na samym końcu. Klucze sandbox w Stripe mają słowo **_test_** i wtedy wiadomo że to sandbox!

```sh
# Stripe
STRIPE_MANUAL_CAPTURE=false
STRIPE_KEY=pk_test_...
STRIPE_SECRET=sk_test_...
STRIPE_WEBHOOK_SECRET=whsec_...
```

## Plik z konfiguracją

config/shop.php

```php
<?php

return [
	// Waluta
	'currency' => 'PLN',

	// Minimalna wartość zamówienia w groszach (40,00 zł)
	'min_order_total' => (int) env('SHOP_MIN_ORDER_TOTAL', 4000),

	// Koszt dostawy 20 PLN
	'min_shipping_cost' => env('SHOP_MIN_SHIPPING_COST', 2000),

	// Bezpłatna dostawa od kwoty 100 PLN
	'free_shipping_threshold' => env('SHOP_FREE_SHIPPING_THRESHOLD', 10000),

	// Płatności
	'stripe' => [
		'lang' => 'pl',
		'currency' => 'PLN',
		'key' => env('STRIPE_KEY'),
		'secret' => env('STRIPE_SECRET'),
		'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
		'manual_capture' => env('STRIPE_MANUAL_CAPTURE', false), // Potwierdzanie płatności dla kart
	],

	// Kurier
	'inpost' => [
		'sandbox'         => env('INPOST_SANDBOX', true), // true dla testów, false na produkcję
		'token'           => env('INPOST_SHIPX_TOKEN'),
		'organization_id' => env('INPOST_ORGANIZATION_ID'),
		'geowidget_token' => env('INPOST_GEOWIDGET_TOKEN'),
	],

	// Dane do faktury proforma
	'invoice_seller'  => [
		'name'    => 'Twoja Firma Sp. z o.o.',
		'address' => 'ul. Przykładowa 12, 00-001 Warszawa',
		'nip'     => '123-456-78-90',
		'bank'    => 'PL 12 3456 7890 1234 5678 9012 3456',
	]
];
```

## Wyłącz ochronę Csrf w Laravel

Dodaj koniecznie wykluczenie w **bootstrap/app.php** wymagane.

```php
$middleware->preventRequestForgery(except: [
    'webhooks/stripe',
]);
```

## Local tests Win

Zostaw okno terminala ze stripe listen otwarte. Upewnij się, że w bootstrap/app.php dodałeś wykluczenie dla webhooka **$middleware->preventRequestForgery(except: ['webhooks/stripe'])** (bez ukośnika na początku), inaczej terminal stripe listen pokaże status 419. Instalujesz klienta w **powershell** a uruchamiasz w **cmd** następnie pobierasz tymczasowy klucz z cmd i dodajesz do .env.

```sh
# W Powershell zainstaluj client Stripe
winget install Stripe.StripeCli

# Zaloguj w cmd (naciskasz Enter przekieruje na stronę z logowaniem stripe)
stripe login

# Uruchom i nie zamykaj cmd (pobierz klucz i dodaj do .env)
stripe listen --forward-to http://127.0.0.1:8000/webhooks/stripe --all-snapshot

# Skopiuj tymczasowy klucz whsec_... z cmd do pliku .env do STRIPE_WEBHOOK_SECRET

# Uruchon w vscode
php artisan config:clear
php artisan serve

# Złóż zamówienie z http://127.0.0.1:8000 przez koszyk z płatnością Stripe i obserwuj statusy na cmd i w aplikacji!
```
