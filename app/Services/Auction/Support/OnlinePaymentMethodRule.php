<?php

declare(strict_types=1);

namespace App\Services\Auction\Support;

use App\Domain\Auction\Enums\PaymentChannel;
use App\Domain\Auction\Enums\PaymentPurpose;
use App\Domain\Auction\Exceptions\AuctionException;
use App\Models\Auction\Auction;
use App\Models\Auction\PaymentMethod;
use App\Services\Auction\Payments\PaymentProviderFactory;

final class OnlinePaymentMethodRule
{
    public function __construct(
        private readonly PaymentProviderFactory $providers,
    ) {}

    public function assertUsable(
        PaymentMethod $method,
        Auction $auction,
        PaymentPurpose $purpose,
        PaymentObligation $obligation
    ): void {
        if ($method->channel !== PaymentChannel::Online) {
            throw AuctionException::domain('payment_method_not_online');
        }

        $providerCode = trim((string) $method->provider_code);

        if ($providerCode === '' || ! $this->providers->isEnabled($providerCode)) {
            throw AuctionException::domain('payment_provider_not_available', ['code' => $providerCode]);
        }

        $this->assertEnvironment($method, $providerCode);
        $this->assertPurpose($method, $purpose);
        $this->assertCountry($method, $auction);
        $this->assertCurrency($method, $obligation->currencyCode);
        $this->assertAmount($method, $obligation->amountMinor);

        $capabilities = $this->providers->make($providerCode)->capabilities();

        if (! $capabilities->supportsCurrency($obligation->currencyCode)) {
            throw AuctionException::domain('provider_currency_unsupported', ['code' => $obligation->currencyCode]);
        }
    }

    public function isAvailableFor(PaymentMethod $method, Auction $auction, PaymentPurpose $purpose): bool
    {
        if (! $method->is_active) {
            return false;
        }

        if ($method->channel !== PaymentChannel::Online) {
            return true;
        }

        $providerCode = trim((string) $method->provider_code);

        if ($providerCode === '' || ! $this->providers->isEnabled($providerCode)) {
            return false;
        }

        try {
            $this->assertPurpose($method, $purpose);
            $this->assertCountry($method, $auction);
            $this->assertCurrency($method, (string) $auction->currency_code);
        } catch (AuctionException) {
            return false;
        }

        return true;
    }

    private function assertEnvironment(PaymentMethod $method, string $providerCode): void
    {
        $providerSandbox = config("auction.payments.providers.{$providerCode}.sandbox");

        if ($providerSandbox === null) {
            return;
        }

        if ((bool) $providerSandbox !== (bool) $method->is_sandbox) {
            throw AuctionException::domain('payment_provider_environment_mismatch', ['code' => $providerCode]);
        }
    }

    private function assertPurpose(PaymentMethod $method, PaymentPurpose $purpose): void
    {
        $allowed = $method->allowed_purposes;

        if (is_array($allowed) && $allowed !== [] && ! in_array($purpose->value, $allowed, true)) {
            throw AuctionException::domain('payment_method_purpose_not_allowed');
        }
    }

    private function assertCountry(PaymentMethod $method, Auction $auction): void
    {
        $allowed = $method->country_codes;

        if (! is_array($allowed) || $allowed === []) {
            return;
        }

        $countryCode = strtoupper((string) $auction->country?->code);

        if ($countryCode === '' || ! in_array($countryCode, array_map('strtoupper', $allowed), true)) {
            throw AuctionException::domain('payment_method_country_not_allowed');
        }
    }

    private function assertCurrency(PaymentMethod $method, string $currencyCode): void
    {
        $allowed = $method->currency_codes;

        if (! is_array($allowed) || $allowed === []) {
            return;
        }

        if (! in_array(strtoupper($currencyCode), array_map('strtoupper', $allowed), true)) {
            throw AuctionException::domain('payment_method_currency_not_allowed');
        }
    }

    private function assertAmount(PaymentMethod $method, int $amountMinor): void
    {
        if ($method->min_amount_minor !== null && $amountMinor < (int) $method->min_amount_minor) {
            throw AuctionException::domain('payment_method_amount_below_minimum');
        }

        if ($method->max_amount_minor !== null && $amountMinor > (int) $method->max_amount_minor) {
            throw AuctionException::domain('payment_method_amount_above_maximum');
        }
    }
}
