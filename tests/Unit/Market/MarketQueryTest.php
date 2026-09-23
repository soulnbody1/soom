<?php

declare(strict_types=1);

namespace Tests\Unit\Market;

use App\Models\Market;
use App\Services\Market\MarketQuery;
use App\Support\Market\MarketContext;
use App\Support\Market\MarketState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

final class MarketQueryTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_filters_only_in_the_modes_that_own_a_market(): void
    {
        $context = app(MarketContext::class);
        $jo = Market::query()->where('code', 'JO')->firstOrFail();
        $markets = app(MarketQuery::class);

        $scoped = [
            MarketState::marketRequest($jo),
            MarketState::adminMarket($jo),
            MarketState::systemMarket($jo),
        ];

        foreach ($scoped as $state) {
            $sql = $context->run($state, fn (): string => $markets->table('ads')->toSql());
            $expected = DB::getQueryGrammar()->wrap('ads.market_id').' = ?';
            $this->assertStringContainsString($expected, $sql, $state->mode->value);
        }

        foreach ([MarketState::adminAll(), MarketState::accountGlobal(), MarketState::systemGlobal()] as $state) {
            $sql = $context->run($state, fn (): string => $markets->table('ads')->toSql());
            $this->assertStringNotContainsString('market_id', $sql, $state->mode->value);
        }
    }

    public function test_it_refuses_a_table_that_was_never_registered_as_market_scoped(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(MarketQuery::class)->table('users');
    }

    public function test_it_qualifies_the_filter_with_the_alias_so_joins_stay_unambiguous(): void
    {
        $context = app(MarketContext::class);
        $jo = Market::query()->where('code', 'JO')->firstOrFail();

        $sql = $context->run(
            MarketState::systemMarket($jo),
            fn (): string => app(MarketQuery::class)->table('ads as scoped')->toSql()
        );

        $this->assertStringContainsString(DB::getQueryGrammar()->wrap('scoped.market_id').' = ?', $sql);
    }
}
