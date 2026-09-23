<?php

declare(strict_types=1);

namespace Tests\Feature\Market;

use App\Models\Market;
use App\Support\Market\MarketContext;
use Database\Seeders\AuctionConfigurationSeeder;
use Database\Seeders\AuctionSeeder;
use Database\Seeders\ContentReviewSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class SeederMarketContextTest extends TestCase
{
    use RefreshDatabase;

    private const SEEDERS = [
        AuctionConfigurationSeeder::class,
        AuctionSeeder::class,
        ContentReviewSeeder::class,
    ];

    /**
     * A console command has no request and no job, so nothing establishes a
     * market for it. The seeders must open one themselves rather than relying
     * on a context the test harness happens to provide.
     */
    private function withoutAmbientContext(callable $callback): void
    {
        $context = app(MarketContext::class);
        app()->forgetInstance(MarketContext::class);
        app()->instance(MarketContext::class, new MarketContext);

        try {
            $callback();
        } finally {
            app()->instance(MarketContext::class, $context);
        }
    }

    public function test_every_baseline_seeder_runs_without_an_ambient_market(): void
    {
        $this->withoutAmbientContext(function (): void {
            foreach (self::SEEDERS as $seeder) {
                $this->seed($seeder);
            }
        });

        $jordan = (int) Market::query()->where('code', 'JO')->value('id');

        foreach ([
            'auction_configuration_versions',
            'auction_terms_versions',
            'payment_methods',
            'content_review_policies',
            'content_review_settings',
        ] as $table) {
            $this->assertSame(
                0,
                DB::table($table)->where('market_id', '!=', $jordan)->count(),
                $table
            );
            $this->assertGreaterThan(0, DB::table($table)->where('market_id', $jordan)->count(), $table);
        }
    }

    public function test_the_baseline_lands_in_the_configured_default_market(): void
    {
        Market::query()->where('code', 'EG')->update(['is_active' => true]);
        config(['markets.default_market_code' => 'EG']);

        $this->withoutAmbientContext(fn () => $this->seed(AuctionSeeder::class));

        $egypt = (int) Market::query()->where('code', 'EG')->value('id');
        $this->assertSame($egypt, (int) DB::table('payment_methods')->value('market_id'));
    }

    public function test_seeding_twice_creates_one_baseline(): void
    {
        $this->withoutAmbientContext(function (): void {
            foreach (self::SEEDERS as $seeder) {
                $this->seed($seeder);
                $this->seed($seeder);
            }
        });

        $this->assertSame(1, DB::table('auction_configuration_versions')->count());
        $this->assertSame(1, DB::table('auction_terms_versions')->count());
        $this->assertSame(1, DB::table('payment_methods')->count());
    }
}
