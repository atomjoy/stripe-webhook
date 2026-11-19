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

# Lub te z listy
stripe listen --forward-to http://127.0.0.1:8000/webhooks/stripe --events checkout.session.completed,checkout.session.expired,payment_intent.amount_capturable_updated,payment_intent.succeeded,payment_intent.payment_failed,payment_intent.canceled,charge.refunded,charge.dispute.created

# Skopiuj tymczasowy klucz whsec_... z cmd do pliku .env do STRIPE_WEBHOOK_SECRET

# Uruchon w vscode
php artisan config:clear
php artisan serve

# Złóż zamówienie z http://127.0.0.1:8000 przez koszyk z płatnością Stripe i obserwuj statusy na cmd i w aplikacji!
```

## Jak Stripe naprawdę oznacza manual capture?

Gdy używasz capture_method => 'manual' w Stripe Checkout:

1. W evencie **checkout.session.completed** pole **$session->payment_status** ma wartość **'paid'**
   mimo że środki są tylko zablokowane, a nie pobrane.

```php
$stripe = new \Stripe\StripeClient('sk_test_TWOJ_KLUCZ_PRYWATNY');

// Zakładamy, że odebrałeś i zweryfikowałeś obiekt $event z webhooka
if ($event->type === 'checkout.session.completed') {

    // Obiekt sesji jest od razu dostępny w payloadzie webhooka
    $session = $event->data->object;

    // Bezpośredni odczyt payment_status (paid | unpaid | no_payment_required)
    $paymentStatus = $session->payment_status;

    switch ($paymentStatus) {
        case 'paid':
            // Płatność opłacona LUB zablokowana (requires_capture).
            // Jeśli używasz ręcznego przechwytywania, pobierz sesję z 'expand', aby upewnić się co do statusu:
            $expandedSession = $stripe->checkout->sessions->retrieve(
                $session->id,
                ['expand' => ['payment_intent']]
            );

            if (
               $expandedSession->payment_intent
               && $expandedSession->payment_intent->status === 'requires_capture'
            ) {
                // Środki tylko zablokowane (autoryzowane) — zapisz ID do późniejszego capture
                // Zapisz $paymentIntentId w bazie danych przy zamówieniu.
                $piId = $expandedSession->payment_intent->id;
            } else {
                // Środki już fizycznie pobrane (status 'succeeded')
                // Zapisz $paymentIntentId w bazie danych przy zamówieniu.
            }
            break;

        case 'no_payment_required':
            // Zamówienie za 0 zł (np. kupon 100% lub darmowy trial) — możesz od razu zrealizować
            // Brak paymentIntent tutaj
            break;

        case 'unpaid':
            // Metoda asynchroniczna (np. przelew SEPA) — środki jeszcze nie dotarły.
            // Czekaj na osobny webhook: 'checkout.session.async_payment_succeeded'
            break;
    }
}
```

2. Prawdziwa informacja o tym, że środki czekają na Twoje ręczne zatwierdzenie, przychodzi w obiekcie
   **PaymentIntent** tam pole **status** ma wartość **requires_capture** a Stripe wysyła event
   **payment_intent.amount_capturable_updated** a object paymentIntent ma atrybut **amount_capturable**.

3. Zdarzenie payment_intent.amount_capturable_updated jest wysyłane przez Stripe dokładnie wtedy,
   gdy środki na karcie klienta zostały pomyślnie zablokowane i czekają na ręczne zatwierdzenie (capture).
   W tym momencie obiekt **PaymentIntent** ma status **requires_capture** (Status: succeeded, processing or requires_capture).

### Session checkout

Zdarzenie checkout.session.completed zawiera obiekt sesji, który może mieć następujące statusy:

- payment_status: paid – płatność została pomyślnie zrealizowana ale może czekać na potwierdzenie! (np. karta kredytowa, portfel cyfrowy)
- payment_status: unpaid – płatność jeszcze nie została zrealizowana – dotyczy metod płatności z opóźnionym potwierdzeniem (np. przelewy bankowe, BLIK)
- payment_status: no_payment_required – nie wymagano płatności (np. przy 100% zniżce lub darmowym okresie próbnym)

Dlatego przy obsłudze zdarzenia checkout.session.completed warto sprawdzić pole payment_status, zanim zrealizujesz zamówienie.

Samo sprawdzenie payment_intent.succeeded nie jest wystarczające w przypadku Checkout, ponieważ:

- Sesja Checkout może zawierać płatności subskrypcyjne (mode: subscription), które używają obiektów Invoice zamiast PaymentIntent
- Sesja może mieć payment_status: unpaid nawet po wywołaniu checkout.session.completed (dla asynchronicznych metod płatności)
- W przypadku no_payment_required nie ma w ogóle powiązanego PaymentIntent

Zalecane podejście:

- Nasłuchuj checkout.session.completed i sprawdzaj pole payment_status na obiekcie sesji
- Dla asynchronicznych metod płatności dodatkowo nasłuchuj:
  checkout.session.async_payment_succeeded – gdy płatność zostanie potwierdzona
  checkout.session.async_payment_failed – gdy płatność nie powiedzie się

Sprawdzanie payment_intent.succeeded może być pomocne jako uzupełnienie, ale nie powinno zastępować obsługi zdarzeń na poziomie sesji Checkout.

### Payment mode session

mode: payment – tak, będzie powiązany PaymentIntent
mode: subscription – nie ma PaymentIntent bezpośrednio na sesji, zamiast tego mamy Invoice → PaymentIntent
mode: setup – nie ma PaymentIntent, jest SetupIntent

Prościej sprawdzić czy pole payment_intent na obiekcie sesji jest null czy nie:

jeśli payment_intent → nie null – masz do czynienia z PaymentIntent
jeśli subscription → nie null – masz do czynienia z subskrypcją
jeśli setup_intent → nie null – masz do czynienia z SetupIntent

### Manual capture Stripe AI

1. Przy użyciu manual capture (capture_method: manual) sytuacja jest bardziej złożona:

- checkout.session.completed odpala się po autoryzacji płatności, nie po jej przechwyceniu
- payment_status będzie miał wartość paid mimo że środki nie zostały jeszcze pobrane z karty klienta
- Faktyczne pobranie środków następuje dopiero po wywołaniu capture przez Ciebie

2. Przepływ przy manual capture:

- Klient kończy Checkout → checkout.session.completed (status: paid, ale tylko autoryzacja)
- payment_intent.amount_capturable_updated → środki gotowe do pobrania (teraz ustaw waiting, środki gotowe do pobrania)
- Ty decydujesz kiedy pobrać środki → wywołujesz capture na PaymentIntent
- payment_intent.succeeded → środki faktycznie pobrane

3. Warto pamiętać, że autoryzacja wygasa (zazwyczaj po 7 dniach dla kart,
   choć zależy od wystawcy), więc należy przechwycić środki przed tym terminem.

4. Zdarzenie payment_intent.amount_capturable_updated odpala się za każdym razem
   gdy zmienia się wartość pola amount_capturable na obiekcie PaymentIntent.

5. Dzieje się to w następujących sytuacjach:

- Po autoryzacji – gdy PaymentIntent przechodzi w status requires_capture, amount_capturable ustawia się na autoryzowaną kwotę
- Przy multicapture – gdy częściowo przechwycisz środki, amount_capturable zmniejsza się o przechwycona kwotę, co odpala to zdarzenie
- Przy incrementalnym zwiększeniu autoryzacji – gdy zwiększysz autoryzowaną kwotę

