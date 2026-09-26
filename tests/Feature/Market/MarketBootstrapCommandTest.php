<?php

declare(strict_types=1);

namespace Tests\Feature\Market;

use App\Models\Auction\PaymentMethod;
use App\Models\Category;
use App\Models\Market;
use App\Support\Market\MarketContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class MarketBootstrapCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_does_not_write_any_market_baseline_data(): void
    {
        $this->mapJordanCategory();

        $this->artisan('market:bootstrap', ['code' => 'JO', '--dry-run' => true])
            ->expectsOutputToContain('Dry run complete for JO')
            ->assertSuccessful();

        $this->assertSame(0, DB::table('payment_methods')->count());
        $this->assertSame(0, DB::table('auction_configuration_versions')->count());
        $this->assertSame(0, DB::table('auction_terms_versions')->count());
        $this->assertSame(0, DB::table('content_review_policies')->count());
        $this->assertSame(0, DB::table('content_review_settings')->count());
        $this->assertSame(0, DB::table('support_contacts')->count());
    }

    public function test_jordan_bootstrap_creates_the_requested_safe_baseline_without_users(): void
    {
        $this->mapJordanCategory();

        $this->artisan('market:bootstrap', ['code' => 'JO', '--apply' => true])->assertSuccessful();

        $jordan = Market::query()->where('code', 'JO')->firstOrFail();
        $this->assertTrue((bool) $jordan->is_active);
        $this->assertSame(0, DB::table('users')->count());

        $this->assertSame(3, DB::table('payment_methods')->where('market_id', $jordan->id)->count());
        $this->assertSame(1, DB::table('payment_methods')->where('market_id', $jordan->id)->where('is_active', true)->count());
        $this->assertDatabaseHas('payment_methods', [
            'market_id' => $jordan->id,
            'code' => 'jo_manual_transfer',
            'is_active' => true,
            'requires_manual_review' => true,
        ]);
        $this->assertDatabaseHas('payment_methods', [
            'market_id' => $jordan->id,
            'code' => 'jo_ngenius',
            'is_active' => false,
        ]);
        $this->assertDatabaseHas('payment_methods', [
            'market_id' => $jordan->id,
            'code' => 'jo_efawateercom',
            'is_active' => false,
        ]);

        $settings = json_decode((string) DB::table('content_review_settings')
            ->where('market_id', $jordan->id)
            ->where('is_active', true)
            ->value('settings'), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('gemini', $settings['provider']);
        $this->assertSame('shadow', $settings['mode']);
        $this->assertSame(5_000_000, $settings['daily_budget_micros']);
        $this->assertSame(100_000_000, $settings['monthly_budget_micros']);

        $terms = (string) DB::table('auction_terms_versions')->where('market_id', $jordan->id)->value('body');
        $this->assertStringContainsString('نسخة تشغيلية تجريبية', $terms);
        $this->assertStringContainsString('المملكة الأردنية الهاشمية', $terms);

        $this->assertSame(1, DB::table('auction_configuration_versions')->where('market_id', $jordan->id)->count());
        $this->assertSame(1, DB::table('content_review_policies')->where('market_id', $jordan->id)->count());
        $this->assertSame(1, DB::table('content_review_settings')->where('market_id', $jordan->id)->count());
        $this->assertSame(1, DB::table('support_contacts')->where('market_id', $jordan->id)->count());
    }

    public function test_both_markets_receive_isolated_version_one_policies_and_egypt_has_one_method(): void
    {
        $this->mapJordanCategory();

        $this->artisan('market:bootstrap', ['code' => 'JO', '--apply' => true])->assertSuccessful();
        $this->artisan('market:bootstrap', ['code' => 'EG', '--apply' => true])->assertSuccessful();

        $jordan = Market::query()->where('code', 'JO')->firstOrFail();
        $egypt = Market::query()->where('code', 'EG')->firstOrFail();

        $this->assertTrue((bool) $egypt->is_active);
        $this->assertSame(2, DB::table('content_review_policies')->where('version_number', 1)->count());
        $this->assertSame(2, DB::table('content_review_settings')->where('version_number', 1)->count());
        $this->assertSame(1, DB::table('payment_methods')->where('market_id', $egypt->id)->count());
        $this->assertDatabaseHas('payment_methods', [
            'market_id' => $egypt->id,
            'code' => 'eg_manual_transfer',
            'is_active' => true,
        ]);
        $this->assertSame(
            DB::table('market_category')->where('market_id', $jordan->id)->count(),
            DB::table('market_category')->where('market_id', $egypt->id)->count(),
        );

        $egyptCountry = DB::table('countries')->where('iso2', 'EG')->value('id');
        $this->assertSame(27, DB::table('states')->where('country_id', $egyptCountry)->count());
    }

    public function test_reapplying_the_profiles_is_idempotent_and_deactivates_unknown_methods(): void
    {
        $this->mapJordanCategory();
        $this->artisan('market:bootstrap', ['code' => 'JO', '--apply' => true])->assertSuccessful();

        $jordan = Market::query()->where('code', 'JO')->firstOrFail();
        app(MarketContext::class)->runInMarket($jordan, function (): void {
            PaymentMethod::query()->create([
                'name' => 'Legacy method',
                'code' => 'legacy_method',
                'channel' => 'manual',
                'rail' => 'transfer',
                'requires_manual_review' => true,
                'is_active' => true,
            ]);
        });

        $this->artisan('market:bootstrap', ['code' => 'JO', '--apply' => true])->assertSuccessful();
        $snapshot = $this->marketCounts($jordan->id);
        $this->artisan('market:bootstrap', ['code' => 'JO', '--apply' => true])->assertSuccessful();

        $this->assertSame($snapshot, $this->marketCounts($jordan->id));
        $this->assertDatabaseHas('payment_methods', [
            'market_id' => $jordan->id,
            'code' => 'legacy_method',
            'is_active' => false,
        ]);
    }

    public function test_online_jordan_methods_activate_after_credentials_are_configured(): void
    {
        $this->mapJordanCategory();
        $this->artisan('market:bootstrap', ['code' => 'JO', '--apply' => true])->assertSuccessful();

        config([
            'services.ngenius.api_key' => 'sandbox-api-key',
            'services.ngenius.outlet_reference' => 'sandbox-outlet',
            'services.ngenius.base_url' => 'https://sandbox.example.test',
            'services.ngenius.webhook_secret' => 'sandbox-webhook-secret',
            'services.efawateercom.biller_code' => 'test-biller',
            'services.efawateercom.username' => 'test-user',
            'services.efawateercom.password' => 'test-password',
        ]);

        $this->artisan('market:bootstrap', ['code' => 'JO', '--apply' => true])->assertSuccessful();

        $jordan = Market::query()->where('code', 'JO')->firstOrFail();
        $this->assertSame(3, DB::table('payment_methods')
            ->where('market_id', $jordan->id)
            ->whereIn('code', ['jo_manual_transfer', 'jo_ngenius', 'jo_efawateercom'])
            ->where('is_active', true)
            ->count());
    }

    public function test_command_requires_an_explicit_mode(): void
    {
        $this->artisan('market:bootstrap', ['code' => 'JO'])->assertExitCode(2);
        $this->artisan('market:bootstrap', [
            'code' => 'JO',
            '--dry-run' => true,
            '--apply' => true,
        ])->assertExitCode(2);
    }

    private function mapJordanCategory(): void
    {
        $jordan = Market::query()->where('code', 'JO')->firstOrFail();
        $countryId = DB::table('countries')->where('iso2', 'JO')->value('id');
        $stateId = DB::table('states')->insertGetId([
            'country_id' => $countryId,
            'name' => 'عمّان',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('cities')->insert([
            'state_id' => $stateId,
            'name' => 'عمّان',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $category = Category::query()->create(['name' => 'سيارات', 'display_order' => 1]);

        DB::table('market_category')->insert([
            'market_id' => $jordan->id,
            'category_id' => $category->id,
            'is_visible' => true,
            'display_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return array<string, int> */
    private function marketCounts(int $marketId): array
    {
        return [
            'payment_methods' => DB::table('payment_methods')->where('market_id', $marketId)->count(),
            'configurations' => DB::table('auction_configuration_versions')->where('market_id', $marketId)->count(),
            'terms' => DB::table('auction_terms_versions')->where('market_id', $marketId)->count(),
            'policies' => DB::table('content_review_policies')->where('market_id', $marketId)->count(),
            'settings' => DB::table('content_review_settings')->where('market_id', $marketId)->count(),
            'contacts' => DB::table('support_contacts')->where('market_id', $marketId)->count(),
        ];
    }
}
