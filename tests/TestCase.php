<?php

namespace Tests;

use App\Domain\Auction\Enums\AuctionStatus;
use App\Models\Auction\Auction;
use App\Models\Auction\AuctionConfigurationVersion;
use App\Models\Market;
use App\Repositories\Auction\AuctionConfigurationSnapshotRepository;
use App\Support\Market\MarketContext;
use App\Support\Market\MarketState;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->guardAgainstNonTestingDatabase();

        $this->app->singleton(MarketContext::class);
        $this->app->afterResolving(MarketContext::class, function (MarketContext $context): void {
            if (! $context->initialized() && Schema::hasTable('markets')) {
                $this->initializeTestMarketContext($context);
            }
        });

        if (Schema::hasTable('markets')) {
            $this->initializeTestMarketContext(app(MarketContext::class));
        }
    }

    /**
     * Build an active auction configuration version whose policies mirror the
     * production defaults in config/auction.php.
     */
    protected function auctionConfigurationVersion(array $overrides = []): AuctionConfigurationVersion
    {
        return AuctionConfigurationVersion::create([
            'version_number' => ((int) AuctionConfigurationVersion::max('version_number')) + 1,
            'is_active' => true,
            'published_at' => now()->subDay(),
            'configuration' => array_replace([
                'seller_deposit_minor' => 2_000,
                'bidder_deposit_minor' => 1_000,
                'minimum_bid_increment_minor' => 500,
                'platform_fee_type' => 'percentage',
                'platform_fee_basis_points' => 250,
                'platform_fee_fixed_minor' => 0,
                'extension_window_seconds' => 300,
                'extension_duration_seconds' => 600,
                'maximum_extension_count' => 6,
                'winner_payment_deadline_hours' => 48,
                'handover_deadline_hours' => 72,
                'alternative_winner_enabled' => true,
                'seller_deposit_policy' => config('auction.seller_deposit_policy'),
                'winner_default_deposit_policy' => config('auction.winner_default_deposit_policy'),
                'non_winner_deposit_policy' => config('auction.non_winner_deposit_policy'),
                'non_winner_deposit_hold_count' => (int) config('auction.non_winner_deposit_hold_count', 1),
            ], $overrides),
        ]);
    }

    /**
     * Create the auction's configuration snapshot through the production code path.
     *
     * Mirrors production, where the snapshot is written when an auction is approved,
     * so pre-approval fixtures are intentionally left without one.
     */
    protected function snapshotApprovedAuction(Auction $auction, ?int $createdBy = null): void
    {
        $preApproval = [AuctionStatus::Draft, AuctionStatus::PendingReview, AuctionStatus::Rejected];

        if (in_array($auction->status, $preApproval, true)) {
            return;
        }

        app(AuctionConfigurationSnapshotRepository::class)->createForApprovedAuction($auction, $createdBy);
    }

    /**
     * Refuse to run any migration/transaction test against a MySQL database whose
     * name does not end with "_testing", so destructive operations can never hit a
     * development, staging, or production database.
     */
    private function guardAgainstNonTestingDatabase(): void
    {
        $connection = config('database.default');

        if (config("database.connections.{$connection}.driver") !== 'mysql') {
            return;
        }

        $database = (string) config("database.connections.{$connection}.database");

        if (! str_ends_with($database, '_testing')) {
            throw new RuntimeException(
                "Refusing to run tests: MySQL database '{$database}' does not end with '_testing'. "
                .'Aborting before any migration or transaction runs to avoid destructive operations.'
            );
        }
    }

    private function initializeTestMarketContext(MarketContext $context): void
    {
        $market = Market::query()->where('code', 'JO')->first();
        if ($market !== null) {
            $context->replace(MarketState::systemMarket($market));
        }
    }
}
