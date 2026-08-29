<?php

declare(strict_types=1);

namespace App\Console\Commands\Auction;

use App\Services\Auction\Payments\PaymentIntent;
use App\Services\Auction\Payments\PaymentProviderFactory;
use App\Services\Auction\Payments\Providers\NGenius\NGeniusRequestException;
use App\Services\Auction\Payments\Providers\NGeniusPaymentProvider;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

final class NGeniusSandboxSmokeTest extends Command
{
    protected $signature = 'auction:ngenius-smoke {--amount-minor=1000} {--currency=} {--order=}';

    protected $description = 'Probe the configured N-Genius environment without touching auction data.';

    public function handle(PaymentProviderFactory $providers): int
    {
        if (! $providers->isRegistered(NGeniusPaymentProvider::CODE)) {
            $this->error('The N-Genius provider is not registered.');

            return self::FAILURE;
        }

        if (! $providers->hasCredentials(NGeniusPaymentProvider::CODE)) {
            $this->warn('N-Genius credentials are missing. Set NGENIUS_API_KEY, NGENIUS_OUTLET_REFERENCE, NGENIUS_BASE_URL and NGENIUS_WEBHOOK_SECRET.');

            return self::FAILURE;
        }

        $provider = $providers->make(NGeniusPaymentProvider::CODE);

        $this->line('Environment: '.($providers->isEnabled(NGeniusPaymentProvider::CODE) ? 'enabled' : 'disabled')
            .' | sandbox: '.(config('auction.payments.providers.ngenius.sandbox') ? 'true' : 'false'));
        $this->line('Base URL: '.(string) config('services.ngenius.base_url'));
        $this->line('Outlet: '.(string) config('services.ngenius.outlet_reference'));
        $this->line('Return URL: '.$this->describeReturnUrl());
        $this->line('Configured currencies: '.($this->configuredCurrencies() === []
            ? '(none)'
            : implode(', ', $this->configuredCurrencies())));
        $this->line('Smoke currency: '.$this->smokeCurrency().' ('.$this->currencySource().')');

        try {
            $provider->verifyConnection();
            $this->info('Authentication succeeded.');
        } catch (Throwable $exception) {
            $this->report($exception, 'Authentication failed');

            return self::FAILURE;
        }

        $orderReference = (string) $this->option('order');

        if ($orderReference !== '') {
            return $this->inspect($provider, $orderReference);
        }

        try {
            $instruction = $provider->createCheckout(new PaymentIntent(
                merchantReference: strtoupper((string) Str::ulid()),
                amountMinor: (int) $this->option('amount-minor'),
                currencyCode: $this->smokeCurrency(),
                purpose: 'bidder_deposit',
                returnUrl: rtrim((string) config('auction.payments.return_url'), '/').'/smoke',
                metadata: ['payer_email' => (string) config('auction.payments.providers.ngenius.fallback_email')],
            ));
        } catch (Throwable $exception) {
            $this->report($exception, 'Creating a sandbox order failed');

            return self::FAILURE;
        }

        $this->info('Order reference: '.$instruction->providerTransactionId);
        $this->info('Hosted checkout: '.(string) $instruction->redirectUrl);
        $this->line('Pay it in the sandbox, then run this command again with --order='.$instruction->providerTransactionId);

        return self::SUCCESS;
    }

    private function configuredCurrencies(): array
    {
        return array_values(array_filter(array_map(
            static fn ($code): string => strtoupper(trim((string) $code)),
            (array) config('auction.payments.providers.ngenius.currencies', [])
        )));
    }

    private function smokeCurrency(): string
    {
        $requested = strtoupper(trim((string) $this->option('currency')));

        if ($requested !== '') {
            return $requested;
        }

        return $this->configuredCurrencies()[0] ?? 'AED';
    }

    private function currencySource(): string
    {
        if (trim((string) $this->option('currency')) !== '') {
            return '--currency option';
        }

        return $this->configuredCurrencies() === []
            ? 'built-in fallback, no configured currency'
            : 'first configured currency';
    }

    private function report(Throwable $exception, string $headline): void
    {
        if (! $exception instanceof NGeniusRequestException) {
            $this->error($headline.': '.$exception->getMessage());

            return;
        }

        $this->error($headline.' (HTTP '.$exception->status.')');
        $this->line('Operation: '.$exception->operation);
        $this->line('URL: '.$exception->url);
        $this->line('Summary: '.$exception->summary());

        $errors = $exception->errors();

        if ($errors !== []) {
            $this->newLine();
            $this->line('Field validation errors:');
            $this->table(['code', 'field', 'message'], array_map(
                static fn (array $error): array => [$error['code'], $error['field'], $error['message']],
                $errors
            ));
        }

        $this->newLine();
        $this->line('N-Genius response body:');
        $this->line($this->pretty($exception->body));

        $this->newLine();
        $this->line('Request payload sent (sensitive values masked):');
        $this->line($this->pretty($exception->redactedPayload()));
    }

    private function pretty(array $data): string
    {
        return (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function describeReturnUrl(): string
    {
        $configured = (string) config('auction.payments.return_url');

        if (trim($configured) === '') {
            return '(empty) -> redirectUrl would be sent as "'.rtrim($configured, '/').'/smoke"';
        }

        return $configured;
    }

    private function inspect(NGeniusPaymentProvider $provider, string $orderReference): int
    {
        try {
            $status = $provider->fetchStatus($orderReference);
        } catch (Throwable $exception) {
            $this->report($exception, 'Retrieving the order failed');

            return self::FAILURE;
        }

        $this->table(['field', 'value'], [
            ['status', $status->status->value],
            ['provider_transaction_id', $status->providerTransactionId],
            ['merchant_reference', (string) $status->merchantReference],
            ['captured_amount_minor', (string) $status->amountMinor],
            ['captured_currency', (string) $status->currencyCode],
            ['failure_code', (string) $status->failureCode],
        ]);

        return self::SUCCESS;
    }
}
