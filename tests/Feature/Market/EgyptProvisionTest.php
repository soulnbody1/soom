<?php

declare(strict_types=1);

namespace Tests\Feature\Market;

use App\Models\Auction\AuctionConfigurationVersion;
use App\Models\Auction\AuctionTermsVersion;
use App\Models\Auction\PaymentMethod;
use App\Models\Category;
use App\Models\Market;
use App\Services\Market\MarketProvisioner;
use App\Services\Market\Profiles\EgyptMarketProfile;
use App\Support\Market\MarketContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

final class EgyptProvisionTest extends TestCase
{
    use RefreshDatabase;

    private function mapCategoriesToJordan(int $count = 3): void
    {
        $jo = Market::query()->where('code', 'JO')->firstOrFail();

        for ($index = 1; $index <= $count; $index++) {
            $category = Category::query()->create(['name' => "فئة {$index}", 'display_order' => $index]);
            DB::table('market_category')->insert([
                'market_id' => $jo->getKey(),
                'category_id' => $category->getKey(),
                'is_visible' => true,
                'display_order' => $index,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function jordanLocationFingerprint(): string
    {
        $jordanId = DB::table('countries')->where('iso2', 'JO')->value('id');
        $states = DB::table('states')->where('country_id', $jordanId)->orderBy('id')->get(['id', 'name']);
        $cities = DB::table('cities')->join('states', 'states.id', '=', 'cities.state_id')
            ->where('states.country_id', $jordanId)->orderBy('cities.id')->get(['cities.id', 'cities.name']);

        return sha1($states->toJson().$cities->toJson());
    }

    public function test_egypt_starts_inactive_and_unready(): void
    {
        $this->assertFalse((bool) Market::query()->where('code', 'EG')->value('is_active'));

        $missing = app(MarketProvisioner::class)->readiness('EG');

        $this->assertContains('no visible category is mapped to this market', $missing);
        $this->assertContains('the market country has no states', $missing);
        $this->assertContains('no active auction configuration version', $missing);
        $this->assertContains('no active auction terms version', $missing);
        $this->assertContains('no active payment method', $missing);
    }

    public function test_activation_is_refused_while_a_prerequisite_is_missing(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('is not ready to activate');

        app(MarketProvisioner::class)->activate('EG');
    }

    public function test_provisioning_makes_egypt_active_and_complete(): void
    {
        $this->mapCategoriesToJordan();
        $before = $this->jordanLocationFingerprint();

        $this->artisan('market:provision', ['code' => 'EG'])->assertExitCode(0);

        $eg = Market::query()->where('code', 'EG')->firstOrFail();
        $this->assertTrue((bool) $eg->is_active);
        $this->assertSame([], app(MarketProvisioner::class)->readiness('EG'));
        $this->assertSame($before, $this->jordanLocationFingerprint());

        $egyptId = DB::table('countries')->where('iso2', 'EG')->value('id');
        $this->assertSame(27, DB::table('states')->where('country_id', $egyptId)->count());
        $this->assertGreaterThan(
            200,
            DB::table('cities')->join('states', 'states.id', '=', 'cities.state_id')
                ->where('states.country_id', $egyptId)->count()
        );

        $this->assertSame(3, DB::table('market_category')->where('market_id', $eg->getKey())->count());
    }

    public function test_the_configuration_carries_the_egyptian_amounts(): void
    {
        $this->mapCategoriesToJordan();
        $this->artisan('market:provision', ['code' => 'EG'])->assertExitCode(0);

        $eg = Market::query()->where('code', 'EG')->firstOrFail();

        app(MarketContext::class)->runInMarket($eg, function (): void {
            $configuration = AuctionConfigurationVersion::query()->where('is_active', true)->firstOrFail();

            $this->assertSame(70000, $configuration->seller_deposit_minor);
            $this->assertSame(35000, $configuration->bidder_deposit_minor);
            $this->assertSame(7000, $configuration->minimum_bid_increment_minor);
            $this->assertSame(250, $configuration->platform_fee_basis_points);

            $terms = AuctionTermsVersion::query()->where('is_active', true)->firstOrFail();
            $this->assertStringContainsString('مصر', $terms->title);

            $method = PaymentMethod::query()->where('is_active', true)->firstOrFail();
            $this->assertSame('eg_manual_transfer', $method->code);
            $this->assertSame('manual', $method->channel->value);
        });
    }

    public function test_jordan_keeps_its_own_configuration_and_payment_methods(): void
    {
        $this->mapCategoriesToJordan();
        $jo = Market::query()->where('code', 'JO')->firstOrFail();

        app(MarketContext::class)->runInMarket($jo, function (): void {
            AuctionConfigurationVersion::query()->create([
                'version_number' => 1,
                'configuration' => ['seller_deposit_minor' => 10000],
                'is_active' => true,
                'published_at' => now()->subDay(),
            ]);
        });

        $this->artisan('market:provision', ['code' => 'EG'])->assertExitCode(0);

        app(MarketContext::class)->runInMarket($jo, function (): void {
            $configuration = AuctionConfigurationVersion::query()->where('is_active', true)->firstOrFail();
            $this->assertSame(10000, $configuration->seller_deposit_minor);
            $this->assertSame(0, PaymentMethod::query()->where('code', 'eg_manual_transfer')->count());
        });
    }

    public function test_provisioning_twice_changes_nothing(): void
    {
        $this->mapCategoriesToJordan();
        $this->artisan('market:provision', ['code' => 'EG'])->assertExitCode(0);

        $eg = Market::query()->where('code', 'EG')->firstOrFail();
        $egyptId = DB::table('countries')->where('iso2', 'EG')->value('id');
        $snapshot = [
            'states' => DB::table('states')->where('country_id', $egyptId)->count(),
            'cities' => DB::table('cities')->join('states', 'states.id', '=', 'cities.state_id')
                ->where('states.country_id', $egyptId)->count(),
            'categories' => DB::table('market_category')->where('market_id', $eg->getKey())->count(),
            'terms' => DB::table('auction_terms_versions')->where('market_id', $eg->getKey())->count(),
            'configurations' => DB::table('auction_configuration_versions')->where('market_id', $eg->getKey())->count(),
            'methods' => DB::table('payment_methods')->where('market_id', $eg->getKey())->count(),
        ];
        $jordanBefore = $this->jordanLocationFingerprint();

        $this->artisan('market:provision', ['code' => 'EG'])->assertExitCode(0);

        $this->assertSame($snapshot['states'], DB::table('states')->where('country_id', $egyptId)->count());
        $this->assertSame(
            $snapshot['cities'],
            DB::table('cities')->join('states', 'states.id', '=', 'cities.state_id')
                ->where('states.country_id', $egyptId)->count()
        );
        $this->assertSame($snapshot['categories'], DB::table('market_category')->where('market_id', $eg->getKey())->count());
        $this->assertSame($snapshot['terms'], DB::table('auction_terms_versions')->where('market_id', $eg->getKey())->count());
        $this->assertSame($snapshot['configurations'], DB::table('auction_configuration_versions')->where('market_id', $eg->getKey())->count());
        $this->assertSame($snapshot['methods'], DB::table('payment_methods')->where('market_id', $eg->getKey())->count());
        $this->assertSame($jordanBefore, $this->jordanLocationFingerprint());
    }

    public function test_the_location_seeder_refuses_to_invent_a_country(): void
    {
        DB::table('markets')->where('code', 'EG')->delete();
        DB::table('countries')->where('iso2', 'EG')->delete();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('EG country row is missing');

        (new \Database\Seeders\Market\EgyptLocationSeeder)->run();
    }

    public function test_the_egyptian_method_is_unusable_outside_its_market(): void
    {
        $this->mapCategoriesToJordan();
        $this->artisan('market:provision', ['code' => 'EG'])->assertExitCode(0);

        $eg = Market::query()->where('code', 'EG')->firstOrFail();
        $jo = Market::query()->where('code', 'JO')->firstOrFail();

        $method = app(MarketContext::class)->runInMarket(
            $eg,
            fn (): PaymentMethod => PaymentMethod::query()->where('code', 'eg_manual_transfer')->firstOrFail()
        );

        $auction = new \App\Models\Auction\Auction;
        $auction->market_id = $jo->getKey();
        $auction->currency_code = 'JOD';
        $auction->setRelation('market', $jo);

        $this->assertFalse(
            app(\App\Services\Auction\Support\PaymentMethodMarketRule::class)
                ->isAvailableFor($method, $auction, 'JOD')
        );
    }

    public function test_the_profile_declares_the_currency_the_market_actually_uses(): void
    {
        $profile = new EgyptMarketProfile;
        $eg = Market::query()->where('code', 'EG')->firstOrFail();

        $this->assertSame('EG', $profile->marketCode());
        $this->assertSame('EGP', $eg->currency_code);
        $this->assertSame(2, \App\Domain\Auction\ValueObjects\Currency::fromCode('EGP')->exponent());
    }
}
