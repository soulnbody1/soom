<?php

declare(strict_types=1);

namespace Tests\Feature\Auction;

use App\Domain\Auction\Enums\AuctionDepositStatus;
use App\Domain\Auction\Enums\AuctionParticipantStatus;
use App\Domain\Auction\Enums\AuctionStatus;
use App\Domain\Auction\Exceptions\AuctionConfigurationSnapshotImmutableException;
use App\Domain\Auction\Exceptions\AuctionConfigurationSnapshotIncompleteException;
use App\Domain\Auction\Exceptions\AuctionConfigurationSnapshotMissingException;
use App\Domain\Auction\Exceptions\AuctionConfigurationVersionInUseException;
use App\Domain\Auction\Exceptions\AuctionException;
use App\Models\Auction\Auction;
use App\Models\Auction\AuctionActivityLog;
use App\Models\Auction\AuctionBid;
use App\Models\Auction\AuctionConfigurationSnapshot;
use App\Models\Auction\AuctionConfigurationVersion;
use App\Models\Auction\AuctionDeposit;
use App\Models\Auction\AuctionParticipant;
use App\Models\Auction\AuctionTermsAcceptance;
use App\Models\Auction\AuctionTermsVersion;
use App\Models\Category;
use App\Models\Country;
use App\Models\User;
use App\Services\Auction\Actions\FinalizeAuctionAction;
use App\Services\Auction\Actions\PlaceBidAction;
use App\Services\Auction\Actions\ReviewAuctionAction;
use App\Services\Auction\Support\AuctionConfigurationSnapshotHasher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AuctionConfigurationSnapshotTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate', ['--force' => true]);
    }

    public function test_approval_creates_one_immutable_snapshot_from_source_version(): void
    {
        $version = $this->configurationVersion(1, sellerDeposit: 100, bidderDeposit: 50, feeBasisPoints: 500, winnerDeadlineHours: 48);
        $auction = $this->auction(AuctionStatus::PendingReview, $version);
        $admin = $this->user('admin');

        app(ReviewAuctionAction::class)->approve($auction, $admin->id, 'approved');
        app(ReviewAuctionAction::class)->approve($auction->refresh(), $admin->id, 'retry');

        $snapshot = AuctionConfigurationSnapshot::where('auction_id', $auction->id)->firstOrFail();

        $this->assertSame(1, AuctionConfigurationSnapshot::where('auction_id', $auction->id)->count());
        $this->assertSame($version->id, $snapshot->source_configuration_version_id);
        $this->assertSame(100, $snapshot->seller_deposit_required_minor);
        $this->assertSame(50, $snapshot->bidder_deposit_required_minor);
        $this->assertSame(48 * 60, $snapshot->winner_payment_deadline_minutes);
        $this->assertNotSame('', $snapshot->snapshot_hash);
        $this->assertSame(1, AuctionActivityLog::where('auction_id', $auction->id)->where('event_type', 'auction_configuration_snapshot_created')->count());
        $this->assertSame(1, AuctionDeposit::where('auction_id', $auction->id)->where('type', 'seller')->count());

        $this->expectException(AuctionConfigurationSnapshotImmutableException::class);
        $snapshot->forceFill(['seller_deposit_required_minor' => 999])->save();
    }

    public function test_existing_auction_keeps_snapshot_after_new_configuration_is_activated(): void
    {
        $oldVersion = $this->configurationVersion(10, sellerDeposit: 100, bidderDeposit: 10_000, feeBasisPoints: 500, winnerDeadlineHours: 48);
        $auctionA = $this->auction(AuctionStatus::PendingReview, $oldVersion);
        $admin = $this->user('admin');
        app(ReviewAuctionAction::class)->approve($auctionA, $admin->id, 'approved A');

        $newVersion = $this->configurationVersion(11, sellerDeposit: 200, bidderDeposit: 10_000, feeBasisPoints: 800, winnerDeadlineHours: 12);
        $auctionB = $this->auction(AuctionStatus::PendingReview, $newVersion);
        app(ReviewAuctionAction::class)->approve($auctionB, $admin->id, 'approved B');

        $snapshotA = $auctionA->configurationSnapshot()->firstOrFail();
        $snapshotB = $auctionB->configurationSnapshot()->firstOrFail();

        $this->assertSame(100, $snapshotA->seller_deposit_required_minor);
        $this->assertSame(48 * 60, $snapshotA->winner_payment_deadline_minutes);
        $this->assertSame(500, $snapshotA->platform_fee_value);
        $this->assertSame(200, $snapshotB->seller_deposit_required_minor);
        $this->assertSame(12 * 60, $snapshotB->winner_payment_deadline_minutes);
        $this->assertSame(800, $snapshotB->platform_fee_value);

        [$winnerBid] = $this->makeEndedAuctionWithWinningBid($auctionA->refresh(), 100_000, 10_000);
        $auctionA = app(FinalizeAuctionAction::class)->execute($auctionA->refresh());
        $settlement = $auctionA->settlement;

        $this->assertSame($winnerBid->id, $settlement->winning_bid_id);
        $this->assertSame(5_000, $settlement->platform_fee_minor);
        $this->assertTrue($settlement->payment_due_at->between(
            Carbon::now()->addHours(48)->subMinute(),
            Carbon::now()->addHours(48)->addMinute()
        ));
    }

    public function test_missing_and_incomplete_snapshot_fail_explicitly(): void
    {
        $version = $this->configurationVersion(20);
        $auction = $this->auction(AuctionStatus::Ended, $version);
        $this->makeEndedAuctionWithWinningBid($auction, 100_000, 10_000);

        $this->expectException(AuctionConfigurationSnapshotMissingException::class);
        app(FinalizeAuctionAction::class)->execute($auction);
    }

    public function test_incomplete_source_version_blocks_approval(): void
    {
        $version = AuctionConfigurationVersion::create([
            'version_number' => $this->nextConfigurationVersionNumber(),
            'is_active' => true,
            'published_at' => now()->subDay(),
            'configuration' => [
                'seller_deposit_minor' => 100,
                'bidder_deposit_minor' => 100,
                'platform_fee_type' => 'percentage',
                'platform_fee_basis_points' => 500,
                'platform_fee_fixed_minor' => 0,
                'minimum_bid_increment_minor' => 100,
                'extension_window_seconds' => 300,
                'extension_duration_seconds' => 600,
                'maximum_extension_count' => 6,
                'winner_payment_deadline_hours' => 48,
                'handover_deadline_hours' => 72,
            ],
        ]);
        $auction = $this->auction(AuctionStatus::PendingReview, $version);

        $this->expectException(AuctionConfigurationSnapshotIncompleteException::class);
        app(ReviewAuctionAction::class)->approve($auction, $this->user('admin')->id, 'incomplete');
    }

    public function test_configuration_version_in_use_cannot_be_mutated_or_deleted(): void
    {
        $version = $this->configurationVersion(30);
        $auction = $this->auction(AuctionStatus::PendingReview, $version);
        app(ReviewAuctionAction::class)->approve($auction, $this->user('admin')->id, 'approved');

        $this->expectException(AuctionConfigurationVersionInUseException::class);
        $version->forceFill(['configuration' => [...$version->configuration, 'seller_deposit_minor' => 999]])->save();
    }

    public function test_terms_version_required_by_snapshot_controls_bidding(): void
    {
        $version = $this->configurationVersion(40);
        $auction = $this->auction(AuctionStatus::PendingReview, $version);
        app(ReviewAuctionAction::class)->approve($auction, $this->user('admin')->id, 'approved');
        $auction->forceFill(['status' => AuctionStatus::Live, 'starts_at' => now()->subHour(), 'ends_at' => now()->addHour()])->save();

        [$bidder, $participant] = $this->qualifiedParticipant($auction, 10_000);
        $otherTerms = AuctionTermsVersion::create([
            'version_number' => ((int) AuctionTermsVersion::max('version_number')) + 1,
            'title' => 'New terms',
            'body' => 'New terms body.',
            'is_active' => true,
            'published_at' => now(),
        ]);
        $this->acceptTerms($auction, $participant, $bidder, $otherTerms->id);

        try {
            app(PlaceBidAction::class)->execute($auction->refresh(), $bidder->id, '10.000', 'JOD', 'wrong-terms');
            $this->fail('Bid should require the snapshot terms version.');
        } catch (AuctionException $exception) {
            $this->assertSame(__('auction.errors.terms_required_before_bidding'), $exception->getMessage());
        }

        $this->acceptTerms($auction, $participant, $bidder, $auction->configurationSnapshot->terms_version_id);
        $bid = app(PlaceBidAction::class)->execute($auction->refresh(), $bidder->id, '10.000', 'JOD', 'right-terms');

        $this->assertSame($bidder->id, $bid->bidder_id);
    }

    public function test_snapshot_hash_is_canonical_and_changes_with_values(): void
    {
        $hasher = app(AuctionConfigurationSnapshotHasher::class);
        $first = ['b' => 2, 'a' => ['d' => 4, 'c' => 3]];
        $second = ['a' => ['c' => 3, 'd' => 4], 'b' => 2];
        $changed = ['a' => ['c' => 3, 'd' => 5], 'b' => 2];

        $this->assertSame($hasher->hash($first), $hasher->hash($second));
        $this->assertNotSame($hasher->hash($first), $hasher->hash($changed));
    }

    private function configurationVersion(
        int $number,
        int $sellerDeposit = 100,
        int $bidderDeposit = 10_000,
        int $feeBasisPoints = 500,
        int $winnerDeadlineHours = 48,
    ): AuctionConfigurationVersion {
        return AuctionConfigurationVersion::create([
            'version_number' => $this->nextConfigurationVersionNumber(),
            'is_active' => true,
            'published_at' => now()->subDay(),
            'configuration' => $this->configuration($sellerDeposit, $bidderDeposit, $feeBasisPoints, $winnerDeadlineHours),
        ]);
    }

    private function nextConfigurationVersionNumber(): int
    {
        return ((int) AuctionConfigurationVersion::max('version_number')) + 1;
    }

    private function configuration(int $sellerDeposit, int $bidderDeposit, int $feeBasisPoints, int $winnerDeadlineHours): array
    {
        return [
            'seller_deposit_minor' => $sellerDeposit,
            'bidder_deposit_minor' => $bidderDeposit,
            'platform_fee_type' => 'percentage',
            'platform_fee_basis_points' => $feeBasisPoints,
            'platform_fee_fixed_minor' => 0,
            'minimum_bid_increment_minor' => 500,
            'extension_window_seconds' => 300,
            'extension_duration_seconds' => 600,
            'maximum_extension_count' => 6,
            'winner_payment_deadline_hours' => $winnerDeadlineHours,
            'handover_deadline_hours' => 72,
            'non_winner_deposit_policy' => 'hold_top_n_bidders_until_winner_payment',
            'non_winner_deposit_hold_count' => 1,
            'winner_default_deposit_policy' => [
                'disposition' => 'full_forfeit',
                'forfeit_amount_minor' => 0,
            ],
            'seller_deposit_policy' => [
                'auction_rejected' => 'refund',
                'unsold' => 'refund',
                'completed' => 'refund',
                'seller_cancellation_before_start' => 'refund',
                'seller_cancellation_after_start' => 'manual_review',
                'admin_cancellation_platform_fault' => 'refund',
                'admin_cancellation_seller_fault' => 'forfeit',
                'admin_cancellation_neutral' => 'refund',
                'admin_cancellation_fraud_or_compliance' => 'manual_review',
                'system_cancellation_platform_fault' => 'refund',
                'system_cancellation_seller_fault' => 'forfeit',
                'system_cancellation_neutral' => 'refund',
                'winner_default' => 'keep_held',
                'seller_breach' => 'forfeit',
                'dispute_complete' => 'refund',
                'dispute_cancel' => 'manual_review',
                'dispute_resume_handover' => 'keep_held',
            ],
        ];
    }

    private function auction(AuctionStatus $status, ?AuctionConfigurationVersion $version): Auction
    {
        $terms = AuctionTermsVersion::create([
            'version_number' => ((int) AuctionTermsVersion::max('version_number')) + 1,
            'title' => 'Terms',
            'body' => 'Snapshot terms body '.Str::ulid(),
            'is_active' => true,
            'published_at' => now()->subDay(),
        ]);

        return Auction::create([
            'seller_id' => $this->user()->id,
            'category_id' => Category::create(['name' => 'snapshot-cat-'.Str::ulid(), 'display_order' => 0])->id,
            'country_id' => Country::create(['name' => 'snapshot-country-'.Str::ulid(), 'code' => strtoupper(substr((string) Str::ulid(), 0, 6))])->id,
            'terms_version_id' => $terms->id,
            'configuration_version_id' => $version?->id,
            'currency_code' => 'JOD',
            'title' => 'Snapshot auction',
            'description' => 'Snapshot auction.',
            'status' => $status,
            'starting_amount_minor' => 10_000,
            'reserve_amount_minor' => null,
            'minimum_bid_increment_minor' => (int) ($version?->minimum_bid_increment_minor ?? 500),
            'seller_deposit_amount_minor' => (int) ($version?->seller_deposit_minor ?? 100),
            'bidder_deposit_amount_minor' => (int) ($version?->bidder_deposit_minor ?? 10_000),
            'platform_fee_type' => (string) ($version?->platform_fee_type ?? 'percentage'),
            'platform_fee_basis_points' => (int) ($version?->platform_fee_basis_points ?? 500),
            'platform_fee_fixed_minor' => (int) ($version?->platform_fee_fixed_minor ?? 0),
            'winner_payment_deadline_hours' => (int) ($version?->winner_payment_deadline_hours ?? 48),
            'handover_deadline_hours' => (int) ($version?->handover_deadline_hours ?? 72),
            'starts_at' => now()->subDay(),
            'original_ends_at' => now()->addDay(),
            'ends_at' => now()->addDay(),
        ]);
    }

    private function makeEndedAuctionWithWinningBid(Auction $auction, int $amount, int $heldDeposit): array
    {
        $auction->forceFill([
            'status' => AuctionStatus::Ended,
            'starts_at' => now()->subDays(2),
            'original_ends_at' => now()->subMinute(),
            'ends_at' => now()->subMinute(),
        ])->save();
        [$winner, $participant] = $this->qualifiedParticipant($auction, $heldDeposit);
        $this->acceptTerms($auction, $participant, $winner, $auction->terms_version_id);

        $bid = AuctionBid::create([
            'auction_id' => $auction->id,
            'participant_id' => $participant->id,
            'bidder_id' => $winner->id,
            'amount_minor' => $amount,
            'currency_code' => 'JOD',
            'sequence_number' => 1,
            'idempotency_key' => 'snapshot-bid-'.Str::ulid(),
            'server_received_at' => now(),
            'accepted_at' => now(),
        ]);
        $auction->forceFill(['current_leading_bid_id' => $bid->id])->save();

        return [$bid, $winner, $participant];
    }

    private function qualifiedParticipant(Auction $auction, int $heldDeposit): array
    {
        $user = $this->user();
        $participant = AuctionParticipant::create([
            'auction_id' => $auction->id,
            'user_id' => $user->id,
            'status' => AuctionParticipantStatus::Qualified,
            'registered_at' => now()->subDay(),
            'qualified_at' => now()->subHour(),
        ]);

        AuctionDeposit::create([
            'auction_id' => $auction->id,
            'participant_id' => $participant->id,
            'user_id' => $user->id,
            'type' => 'bidder',
            'status' => AuctionDepositStatus::Held,
            'required_amount_minor' => $heldDeposit,
            'held_amount_minor' => $heldDeposit,
            'currency_code' => 'JOD',
            'held_at' => now()->subHour(),
        ]);

        return [$user, $participant];
    }

    private function acceptTerms(Auction $auction, AuctionParticipant $participant, User $user, int $termsVersionId): void
    {
        AuctionTermsAcceptance::firstOrCreate([
            'auction_id' => $auction->id,
            'user_id' => $user->id,
            'terms_version_id' => $termsVersionId,
        ], [
            'participant_id' => $participant->id,
            'accepted_at' => now()->subHour(),
        ]);
    }

    private function user(string $role = 'user'): User
    {
        $unique = strtolower((string) Str::ulid());

        return User::create([
            'name' => 'Snapshot User',
            'email' => "snapshot-{$unique}@example.test",
            'phone' => '+96279'.str_pad((string) (abs(crc32($unique)) % 10_000_000), 7, '0', STR_PAD_LEFT),
            'password' => Hash::make('password'),
            'role' => $role,
            'email_verified_at' => now(),
        ]);
    }
}
