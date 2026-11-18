# Stripe

Jak dodać webhook do Stripe i testować lokalnie.

## Webhooks

Dodaj webhook: **`https://example.com/webhooks/stripe`** i eventy w panelu. 
Przełącz na sandbox w pasku wyszukiwania na górze: **Stripe Api** i potem 
**Developer** do przejścia na webhooks panel. 

```php
$events = [
  'payment_intent.payment_failed',
  'payment_intent.succeeded',
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
STRIPE_KEY=pk_test_...
STRIPE_SECRET=sk_test_...
STRIPE_WEBHOOK_SECRET=whsec_...
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

# Złóż zamówienie z http://127.0.0.1:8000 przez koszyk z płatnością Stripe i obserwuj statusy na cmd i w aplikacji!
```
