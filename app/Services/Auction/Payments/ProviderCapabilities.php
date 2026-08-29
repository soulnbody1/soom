<?php

declare(strict_types=1);

namespace App\Services\Auction\Payments;

use App\Domain\Auction\Enums\ProviderAmountFormat;

final readonly class ProviderCapabilities
{
    public function __construct(
        public ProviderAmountFormat $amountFormat,
        public array $supportedCurrencies,
        public bool $supportsRefund,
        public bool $supportsPartialRefund,
        public bool $supportsInquiry,
        public bool $supportsWebhook,
    ) {}

    public function supportsCurrency(string $currencyCode): bool
    {
        return in_array(strtoupper($currencyCode), $this->supportedCurrencies, true);
    }
}
