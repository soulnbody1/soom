<?php

declare(strict_types=1);

namespace App\Services\Auction\Payments;

use App\Domain\Auction\Exceptions\AuctionException;
use App\Services\Auction\Payments\Contracts\PaymentProvider;
use Illuminate\Contracts\Container\Container;

final class PaymentProviderFactory
{
    private const FAKE = 'fake';

    private array $resolved = [];

    public function __construct(
        private readonly Container $container,
    ) {}

    public function make(string $code): PaymentProvider
    {
        $code = trim($code);

        if (! $this->isRegistered($code)) {
            throw AuctionException::domain('payment_provider_unknown', ['code' => $code]);
        }

        if ($code === self::FAKE && ! $this->fakeProviderAllowed()) {
            throw AuctionException::domain('payment_provider_not_available', ['code' => $code]);
        }

        return $this->resolved[$code] ??= $this->container->make($this->binding($code));
    }

    public function isRegistered(string $code): bool
    {
        return $this->binding($code) !== null;
    }

    public function isEnabled(string $code): bool
    {
        if (! $this->isRegistered($code)) {
            return false;
        }

        if ($code === self::FAKE && ! $this->fakeProviderAllowed()) {
            return false;
        }

        return ! in_array($code, $this->disabledProviders(), true);
    }

    public function hasCredentials(string $code): bool
    {
        $required = (array) config("auction.payments.providers.{$code}.required_credentials", []);

        foreach ($required as $key) {
            if (trim((string) config("services.{$code}.{$key}")) === '') {
                return false;
            }
        }

        return true;
    }

    public function registeredCodes(): array
    {
        return array_keys((array) config('auction.payments.providers', []));
    }

    private function binding(string $code): ?string
    {
        $binding = config("auction.payments.providers.{$code}.class");

        if (! is_string($binding) || $binding === '') {
            return null;
        }

        return $binding;
    }

    private function fakeProviderAllowed(): bool
    {
        return app()->environment('local', 'testing')
            && (bool) config('auction.payments.allow_fake_provider', false);
    }

    private function disabledProviders(): array
    {
        return array_values(array_filter(array_map(
            static fn (string $code): string => trim($code),
            (array) config('auction.payments.disabled_providers', [])
        )));
    }
}
