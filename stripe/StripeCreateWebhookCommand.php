<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;

// php artisan stripe:webhook-create --url=https://example.com/webkooks/stripe
class StripeCreateWebhookCommand extends Command
{
    protected $signature = 'stripe:webhook-create {--url= : Opcjonalny własny adres URL (domyślnie pobiera z APP_URL)}';
    protected $description = 'Rejestruje punkt końcowy webhooka w Stripe i zwraca klucz podpisu (Signing Secret)';

    public function handle(): int
    {
        $secretKey = config('shop.stripe.secret');

        if (empty($secretKey)) {
            $this->error('Brak klucza API Stripe (config("shop.stripe.secret")). Sprawdź plik .env.');
            return self::FAILURE;
        }

        $webhookUrl = $this->option('url') ?: secure_url('/webhooks/stripe');

        // Ostrzeżenie przed utworzeniem duplikatu
        if (! $this->confirm("Czy na pewno chcesz zarejestrować nowy webhook dla adresu: {$webhookUrl}?", true)) {
            $this->info('Anulowano.');
            return self::SUCCESS;
        }

        try {
            $stripe = new StripeClient($secretKey);

            $existingEndpoints = $stripe->webhookEndpoints->all(['limit' => 100]);
            foreach ($existingEndpoints->data as $existing) {
                if ($existing->url === $webhookUrl) {
                    $this->warn("Webhook dla adresu {$webhookUrl} już istnieje (ID: {$existing->id}).");
                    $this->warn('Stripe nie pozwala pobrać klucza secret dla istniejących endpointów. Usuń go w panelu lub przez API, aby wygenerować nowy.');
                    return self::FAILURE;
                }
            }

            $endpoint = $stripe->webhookEndpoints->create([
                'url' => $webhookUrl,
                'enabled_events' => [
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
                ],
            ]);

            if ($this->updateEnvFile('STRIPE_WEBHOOK_SECRET', $endpoint->secret)) {
                $this->info('Webhook utworzony, a klucz STRIPE_WEBHOOK_SECRET został automatycznie zapisany w pliku .env!');

                // Odświeżenie cache konfiguracji, jeśli był używany
                if (app()->configurationIsCached()) {
                    $this->call('config:cache');
                }
            } else {
                $this->warn('Nie udało się zmodyfikować pliku .env. Zapisz klucz ręcznie:');
                $this->line("STRIPE_WEBHOOK_SECRET={$endpoint->secret}");
            }

            $this->info('Webhook został pomyślnie utworzony w Stripe!');
            $this->newLine();
            $this->line("ID Endpointu: <comment>{$endpoint->id}</comment>");
            $this->line("Adres URL:    <comment>{$endpoint->url}</comment>");
            $this->newLine();
            $this->warn('Skopiuj poniższy klucz i wklej go do pliku .env (wyświetla się tylko raz):');
            $this->line("STRIPE_WEBHOOK_SECRET={$endpoint->secret}");

            return self::SUCCESS;
        } catch (ApiErrorException $e) {
            $this->error('Błąd API Stripe: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    /**
     * Aktualizuje lub dopisuje zmienną w pliku .env.
     */
    protected function updateEnvFile(string $key, string $value): bool
    {
        $envPath = base_path('.env');

        if (! File::exists($envPath) || ! File::isWritable($envPath)) {
            return false;
        }

        $content = File::get($envPath);
        $line = "{$key}={$value}";

        if (preg_match("/^{$key}=.*/m", $content)) {
            $content = preg_replace("/^{$key}=.*/m", $line, $content);
        } else {
            $content .= PHP_EOL . $line . PHP_EOL;
        }

        return File::put($envPath, $content) !== false;
    }
}
