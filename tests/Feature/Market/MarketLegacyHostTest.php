<?php

declare(strict_types=1);

namespace Tests\Feature\Market;

use App\Models\Market;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class MarketLegacyHostTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_legacy_host_is_unknown_until_it_is_configured(): void
    {
        config(['markets.legacy_api_host' => null]);

        $this->getJson('http://api.soom.test/api/market-config')->assertNotFound();
    }

    public function test_a_configured_legacy_host_serves_the_named_market(): void
    {
        config(['markets.legacy_api_host' => 'api.soom.test', 'markets.legacy_market_code' => 'JO']);

        $this->getJson('http://api.soom.test/api/market-config')
            ->assertOk()
            ->assertJsonPath('data.code', 'jo')
            ->assertJsonPath('data.currency_code', 'JOD');
    }

    public function test_it_announces_its_own_deprecation(): void
    {
        config([
            'markets.legacy_api_host' => 'api.soom.test',
            'markets.legacy_sunset' => 'Wed, 31 Dec 2026 23:59:59 GMT',
        ]);

        $this->getJson('http://api.soom.test/api/market-config')
            ->assertOk()
            ->assertHeader('Deprecation', 'true')
            ->assertHeader('Sunset', 'Wed, 31 Dec 2026 23:59:59 GMT');
    }

    public function test_a_market_host_carries_no_deprecation_notice(): void
    {
        config(['markets.legacy_api_host' => 'api.soom.test']);

        $response = $this->getJson('http://api-jo.soom.test/api/market-config')->assertOk();

        $this->assertNull($response->headers->get('Deprecation'));
    }

    public function test_the_legacy_host_cannot_reach_admin_routes(): void
    {
        config(['markets.legacy_api_host' => 'api.soom.test']);

        $this->getJson('http://api.soom.test/api/admin/markets')->assertNotFound();
    }

    public function test_it_fails_closed_when_the_named_market_is_inactive(): void
    {
        config(['markets.legacy_api_host' => 'api.soom.test', 'markets.legacy_market_code' => 'EG']);

        $this->getJson('http://api.soom.test/api/market-config')->assertNotFound();

        Market::query()->where('code', 'EG')->update(['is_active' => true]);

        $this->getJson('http://api.soom.test/api/market-config')
            ->assertOk()
            ->assertJsonPath('data.code', 'eg');
    }

    public function test_an_unrelated_host_is_still_rejected(): void
    {
        config(['markets.legacy_api_host' => 'api.soom.test']);

        $this->getJson('http://api-unknown.soom.test/api/market-config')->assertNotFound();
    }
}
