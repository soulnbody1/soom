<?php

declare(strict_types=1);

namespace Tests\Feature\Market;

use App\Models\Auction\AuctionConfigurationVersion;
use App\Models\Auction\AuctionTermsVersion;
use App\Models\Market;
use App\Models\User;
use App\Repositories\Auction\AuctionConfigurationRepository;
use App\Repositories\Auction\AuctionTermsRepository;
use App\Support\Market\MarketContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class MarketAuctionVersionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_terms_and_configuration_versions_are_scoped_by_market(): void
    {
        $jo = Market::query()->where('code', 'JO')->firstOrFail();
        $eg = Market::query()->where('code', 'EG')->firstOrFail();
        $joTerms = app(MarketContext::class)->runInMarket($jo, fn () => AuctionTermsVersion::create(['version_number' => 1, 'title' => 'JO', 'body' => 'JO terms', 'is_active' => true, 'published_at' => now()]));
        $egTerms = app(MarketContext::class)->runInMarket($eg, fn () => AuctionTermsVersion::create(['version_number' => 1, 'title' => 'EG', 'body' => 'EG terms', 'is_active' => true, 'published_at' => now()]));
        $joConfig = app(MarketContext::class)->runInMarket($jo, fn () => AuctionConfigurationVersion::create(['version_number' => 1, 'configuration' => ['minimum_bid_increment_minor' => 500], 'is_active' => true, 'published_at' => now()]));
        $egConfig = app(MarketContext::class)->runInMarket($eg, fn () => AuctionConfigurationVersion::create(['version_number' => 1, 'configuration' => ['minimum_bid_increment_minor' => 100], 'is_active' => true, 'published_at' => now()]));

        app(MarketContext::class)->runInMarket($jo, function () use ($joTerms, $joConfig): void {
            $this->assertSame($joTerms->id, app(AuctionTermsRepository::class)->getActiveTermsVersion()?->id);
            $this->assertSame($joConfig->id, app(AuctionConfigurationRepository::class)->getActiveConfiguration()->id);
        });
        app(MarketContext::class)->runInMarket($eg, function () use ($egTerms, $egConfig): void {
            $this->assertSame($egTerms->id, app(AuctionTermsRepository::class)->getActiveTermsVersion()?->id);
            $this->assertSame($egConfig->id, app(AuctionConfigurationRepository::class)->getActiveConfiguration()->id);
        });
    }

    public function test_admin_mutation_requires_a_specific_market_before_the_market_goes_live(): void
    {
        $admin = User::factory()->admin()->create();
        $payload = ['title' => 'Egypt terms', 'body' => 'Prepared before launch', 'publish' => true];

        $this->actingAs($admin, 'sanctum')->postJson('http://api-admin.soom.test/api/admin/auctions/terms', $payload)->assertStatus(422);
        $this->actingAs($admin, 'sanctum')->postJson('http://api-admin.soom.test/api/admin/auctions/terms?market=eg', $payload)
            ->assertCreated()->assertJsonPath('data.market', 'eg');
    }
}
