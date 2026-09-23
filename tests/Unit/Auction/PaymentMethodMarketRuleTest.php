<?php

declare(strict_types=1);

namespace Tests\Unit\Auction;

use App\Models\Auction\Auction;
use App\Models\Auction\PaymentMethod;
use App\Models\Market;
use App\Services\Auction\Support\PaymentMethodMarketRule;
use Tests\TestCase;

final class PaymentMethodMarketRuleTest extends TestCase
{
    public function test_method_must_match_both_auction_market_and_currency(): void
    {
        $rule = new PaymentMethodMarketRule;
        $method = new PaymentMethod(['is_active' => true]);
        $method->market_id = 2;

        $this->assertTrue($rule->isAvailableFor($method, $this->auction(2, 'EGP'), 'EGP'));
        $this->assertFalse($rule->isAvailableFor($method, $this->auction(1, 'JOD'), 'JOD'));
        $this->assertFalse($rule->isAvailableFor($method, $this->auction(2, 'EGP'), 'JOD'));
    }

    private function auction(int $marketId, string $currency): Auction
    {
        $auction = new Auction(['currency_code' => $currency]);
        $auction->market_id = $marketId;
        $auction->setRelation('market', new Market(['currency_code' => $currency]));

        return $auction;
    }
}
