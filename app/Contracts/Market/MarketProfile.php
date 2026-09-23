<?php

declare(strict_types=1);

namespace App\Contracts\Market;

interface MarketProfile
{
    public function marketCode(): string;

    public function categorySourceMarketCode(): string;

    public function supportContactSourceMarketCode(): string;

    /** @return array<string, mixed> */
    public function auctionConfiguration(): array;

    /** @return array{title: string, body: string} */
    public function auctionTerms(): array;

    /** @return list<array<string, mixed>> */
    public function paymentMethods(): array;
}
