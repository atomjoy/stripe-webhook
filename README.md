# Stripe scripts
Stripe help.

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

## Wyłącz ochronę Csrf

Dodaj koniecznie wykluczenie w **bootstrap/app.php**.

```php
$middleware->preventRequestForgery(except: [
    'webhooks/stripe',
]);
```

## Local tests Win

Zostaw okno terminala ze stripe listen otwarte. Upewnij się, że w bootstrap/app.php dodałeś
wyjątek $middleware->preventRequestForgery(except: ['webhooks/stripe']) (bez ukośnika na początku),
inaczej terminal stripe listen pokaże status 419.

```sh
# W Powershell
winget install Stripe.StripeCli

# Zaloguj w cmd (Naciskasz Enter przekieruje na stronę z logowaniem stripe)
stripe login

# Uruchom i nie zamykaj cmd (pobierz klucz)
stripe listen --forward-to http://127.0.0.1:8000/webhooks/stripe --all-snapshot

# Skopiuj tymczasowy klucz whsec_... do pliku .env do STRIPE_WEBHOOK_SECRET

# Uruchon w vscode
php artisan config:clear

# Złóż zamówienie z http://127.0.0.1:8000 przez koszyk z płatnością Stripe i obserwuj statusy na cmd i w aplikacji!
```
