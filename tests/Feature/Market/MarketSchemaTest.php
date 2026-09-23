<?php

declare(strict_types=1);

namespace Tests\Feature\Market;

use App\Models\Market;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class MarketSchemaTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('uniqueColumns')]
    public function test_market_identity_columns_are_independently_unique(string $column): void
    {
        $jo = Market::query()->where('code', 'JO')->firstOrFail();
        $ae = Market::query()->where('code', 'AE')->firstOrFail();
        $ae->setAttribute($column, $jo->getAttribute($column));

        $this->expectException(QueryException::class);
        $ae->save();
    }

    public static function uniqueColumns(): array
    {
        return [['country_id'], ['code'], ['web_host'], ['api_host']];
    }

    public function test_active_market_requires_both_hosts(): void
    {
        $ae = Market::query()->where('code', 'AE')->firstOrFail();
        $ae->is_active = true;

        $this->expectException(DomainException::class);
        $ae->save();
    }

    public function test_inactive_market_may_have_null_hosts(): void
    {
        $ae = Market::query()->where('code', 'AE')->firstOrFail();
        $this->assertFalse($ae->is_active);
        $this->assertNull($ae->web_host);
        $this->assertNull($ae->api_host);
    }
}
