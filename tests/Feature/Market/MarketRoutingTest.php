<?php

declare(strict_types=1);

namespace Tests\Feature\Market;

use App\Models\Ad;
use App\Models\Auction\Auction;
use App\Models\Banner;
use App\Models\City;
use App\Models\Favorite;
use App\Models\Market;
use App\Models\State;
use App\Models\User;
use App\Support\Market\MarketContext;
use App\Support\Market\MarketMode;
use App\Support\Market\MarketState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Storage;
use LogicException;
use Tests\TestCase;

final class MarketRoutingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('spaces');
    }

    public function test_public_market_is_resolved_only_from_an_active_api_host(): void
    {
        $this->getJson('http://api-jo.soom.test/api/market-config?market=eg')
            ->assertOk()->assertJsonPath('data.code', 'jo')->assertJsonPath('data.currency_code', 'JOD');
        $this->getJson('http://api-eg.soom.test/api/market-config')->assertNotFound();

        Market::query()->where('code', 'EG')->update(['is_active' => true]);
        $this->getJson('http://api-eg.soom.test/api/market-config')->assertOk()->assertJsonPath('data.code', 'eg');
        $this->getJson('http://api-ae.soom.test/api/market-config')->assertNotFound();
        $this->getJson('http://api.soom.test/api/market-config')->assertNotFound();
        $this->getJson('http://api-unknown.soom.test/api/market-config')->assertNotFound();
    }

    public function test_banner_proof_of_concept_covers_public_and_admin_modes(): void
    {
        [$jo, $eg] = $this->markets();
        $joBanner = $this->inMarket($jo, fn () => Banner::create(['image' => 'jo.jpg', 'is_active' => true]));
        $egBanner = $this->inMarket($eg, fn () => Banner::create(['image' => 'eg.jpg', 'is_active' => true]));
        Market::query()->whereKey($eg->id)->update(['is_active' => true]);

        $this->getJson('http://api-jo.soom.test/api/soom/banners')->assertOk()->assertSee('jo.jpg')->assertDontSee('eg.jpg');
        $this->getJson('http://api-eg.soom.test/api/soom/banners')->assertOk()->assertSee('eg.jpg')->assertDontSee('jo.jpg');

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin, 'sanctum')->getJson('http://api-admin.soom.test/api/admin/banners')
            ->assertOk()->assertSee((string) $joBanner->id)->assertSee((string) $egBanner->id);
        $this->actingAs($admin, 'sanctum')->getJson('http://api-admin.soom.test/api/admin/banners?market=eg')
            ->assertOk()->assertSee((string) $egBanner->id);
        $this->actingAs($admin, 'sanctum')->postJson('http://api-admin.soom.test/api/admin/banners', [])->assertStatus(422);
    }

    public function test_ad_and_auction_return_404_on_the_wrong_market_host(): void
    {
        [, $eg] = $this->markets();
        Market::query()->whereKey($eg->id)->update(['is_active' => true]);
        $egAd = $this->inMarket($eg, fn () => Ad::factory()->create(['country_id' => $eg->country_id, 'currency_code' => 'EGP']));
        $egAuction = $this->inMarket($eg, fn () => Auction::factory()->live()->create(['country_id' => $eg->country_id, 'currency_code' => 'EGP']));

        $this->getJson('http://api-jo.soom.test/api/soom/ads/'.$egAd->public_id)->assertNotFound();
        $this->getJson('http://api-jo.soom.test/api/auctions/'.$egAuction->public_id)->assertNotFound();
        $this->getJson('http://api-eg.soom.test/api/soom/ads/'.$egAd->public_id)->assertOk();
        $this->getJson('http://api-eg.soom.test/api/auctions/'.$egAuction->public_id)->assertOk();
    }

    public function test_context_is_restored_after_an_exception(): void
    {
        [$jo] = $this->markets();
        $context = app(MarketContext::class);
        $context->replace(MarketState::accountGlobal());
        $this->assertSame('account_global', Context::get('market_mode'));
        $this->assertFalse(Context::has('market_id'));

        try {
            $context->runInMarket($jo, function () use ($context): never {
                $this->assertSame(MarketMode::SystemMarket, $context->state()->mode);
                $this->assertSame('system_market', Context::get('market_mode'));
                $this->assertSame($context->marketId(), Context::get('market_id'));
                $this->assertSame('JO', Context::get('market_code'));
                throw new LogicException('expected');
            });
        } catch (LogicException $exception) {
            $this->assertSame('expected', $exception->getMessage());
        }

        $this->assertSame(MarketMode::AccountGlobal, $context->state()->mode);
        $this->assertNull($context->state()->market);
        $this->assertSame('account_global', Context::get('market_mode'));
        $this->assertFalse(Context::has('market_id'));
        $this->assertFalse(Context::has('market_code'));
    }

    public function test_uninitialized_context_fails_closed(): void
    {
        $this->expectException(LogicException::class);

        (new MarketContext)->state();
    }

    public function test_global_context_cannot_write_even_with_an_explicit_market_id(): void
    {
        [$jo] = $this->markets();
        $context = app(MarketContext::class);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('cannot be created from a global context');

        $context->runGlobally(fn () => Banner::create([
            'market_id' => $jo->id,
            'image' => 'forbidden.jpg',
            'is_active' => true,
        ]));
    }

    public function test_account_global_loads_owned_cross_market_resources_with_direct_urls(): void
    {
        [, $eg] = $this->markets();
        $user = User::factory()->create();
        $state = State::factory()->create(['country_id' => $eg->country_id]);
        $city = City::factory()->create(['state_id' => $state->id]);
        $ad = $this->inMarket($eg, fn () => Ad::factory()->create([
            'user_id' => $user->id,
            'country_id' => $eg->country_id,
            'state_id' => $state->id,
            'city_id' => $city->id,
            'currency_code' => 'EGP',
        ]));
        $auction = $this->inMarket($eg, fn () => Auction::factory()->create([
            'seller_id' => $user->id,
            'country_id' => $eg->country_id,
            'state_id' => $state->id,
            'city_id' => $city->id,
            'currency_code' => 'EGP',
        ]));
        Favorite::factory()->create(['user_id' => $user->id, 'ad_id' => $ad->id]);

        $favorite = $this->actingAs($user, 'sanctum')
            ->getJson('http://api-jo.soom.test/api/soom/favorites')
            ->assertOk()
            ->json('data.0.ad');

        $this->assertSame('eg', $favorite['market_code']);
        $this->assertSame('https://eg.soom.test/ads/'.$ad->public_id, $favorite['url']);

        $owned = $this->actingAs($user, 'sanctum')
            ->getJson('http://api-jo.soom.test/api/soom/ads/my')
            ->assertOk()
            ->json('data.0');

        $this->assertSame('eg', $owned['market_code']);
        $this->assertSame('https://eg.soom.test/ads/'.$ad->public_id, $owned['url']);

        $ownedAuction = $this->actingAs($user, 'sanctum')
            ->getJson('http://api-jo.soom.test/api/soom/my/auctions')
            ->assertOk()
            ->json('data.0');

        $this->assertSame('eg', $ownedAuction['market_code']);
        $this->assertSame('https://eg.soom.test/auctions/'.$auction->public_id, $ownedAuction['url']);
    }

    /** @return array{Market, Market} */
    private function markets(): array
    {
        return [Market::query()->where('code', 'JO')->firstOrFail(), Market::query()->where('code', 'EG')->firstOrFail()];
    }

    private function inMarket(Market $market, callable $callback): mixed
    {
        return app(MarketContext::class)->runInMarket($market, $callback);
    }
}
