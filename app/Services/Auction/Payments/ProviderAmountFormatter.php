<?php

declare(strict_types=1);

namespace App\Services\Auction\Payments;

use App\Domain\Auction\Enums\ProviderAmountFormat;
use App\Domain\Auction\Exceptions\AuctionException;
use App\Domain\Auction\ValueObjects\Currency;

final class ProviderAmountFormatter
{
    public function format(int $amountMinor, string $currencyCode, ProviderCapabilities $capabilities): string
    {
        if (! $capabilities->supportsCurrency($currencyCode)) {
            throw AuctionException::domain('provider_currency_unsupported', ['code' => strtoupper($currencyCode)]);
        }

        $currency = Currency::fromCode($currencyCode);

        return match ($capabilities->amountFormat) {
            ProviderAmountFormat::MinorUnits => (string) $amountMinor,
            ProviderAmountFormat::DecimalString => $this->decimal($amountMinor, $currency->exponent()),
        };
    }

    public function toMinor(string $amount, string $currencyCode, ProviderCapabilities $capabilities): int
    {
        $currency = Currency::fromCode($currencyCode);
        $amount = trim($amount);

        if ($capabilities->amountFormat === ProviderAmountFormat::MinorUnits) {
            if (! preg_match('/^\d+$/', $amount)) {
                throw AuctionException::domain('provider_amount_unreadable');
            }

            return (int) $amount;
        }

        if (! preg_match('/^(\d+)(?:\.(\d+))?$/', $amount, $matches)) {
            throw AuctionException::domain('provider_amount_unreadable');
        }

        $exponent = $currency->exponent();
        $fraction = $matches[2] ?? '';

        if (strlen($fraction) > $exponent) {
            throw AuctionException::domain('provider_amount_unreadable');
        }

        return ((int) $matches[1]) * $currency->scale() + (int) str_pad($fraction, $exponent, '0');
    }

    private function decimal(int $amountMinor, int $exponent): string
    {
        if ($exponent === 0) {
            return (string) $amountMinor;
        }

        $digits = str_pad((string) $amountMinor, $exponent + 1, '0', STR_PAD_LEFT);

        return substr($digits, 0, -$exponent).'.'.substr($digits, -$exponent);
    }
}
