<?php

declare(strict_types=1);

namespace App\Services\Auction\Support;

use App\Domain\Auction\Exceptions\AuctionException;
use App\Models\Auction\Auction;
use App\Models\Auction\PaymentMethod;

final class PaymentMethodMarketRule
{
    public function assertUsable(PaymentMethod $method, Auction $auction, string $currency): void
    {
        if (! $this->isAvailableFor($method, $auction, $currency)) {
            throw AuctionException::domain('payment_method_country_not_allowed');
        }
    }

    public function isAvailableFor(PaymentMethod $method, Auction $auction, string $currency): bool
    {
        return $method->is_active
            && (int) $method->market_id === (int) $auction->market_id
            && strtoupper($currency) === strtoupper((string) $auction->currency_code)
            && strtoupper($currency) === strtoupper((string) $auction->market->currency_code);
    }
}
