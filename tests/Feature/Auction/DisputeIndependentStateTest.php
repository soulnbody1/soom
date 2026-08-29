<?php

declare(strict_types=1);

namespace Tests\Feature\Auction;

use App\Domain\Auction\Enums\AuctionParticipantStatus;
use App\Domain\Auction\Enums\AuctionStatus;
use App\Domain\Auction\Enums\SettlementStatus;
use App\Domain\Auction\Exceptions\AuctionException;
use App\Models\Auction\Auction;
use App\Models\Auction\AuctionBid;
use App\Models\Auction\AuctionConfigurationVersion;
use App\Models\Auction\AuctionDispute;
use App\Models\Auction\AuctionParticipant;
use App\Models\Auction\AuctionSellerPayout;
use App\Models\Auction\AuctionSettlement;
use App\Models\Auction\AuctionTermsVersion;
use App\Models\Auction\OutboxMessage;
use App\Models\Category;
use App\Models\Country;
use App\Models\User;
use App\Repositories\Auction\AuctionConfigurationSnapshotRepository;
use App\Services\Auction\Actions\ConfirmAuctionHandoverBySellerAction;
use App\Services\Auction\Actions\ConfirmAuctionReceiptByWinnerAction;
use App\Services\Auction\Actions\OpenAuctionDisputeAction;
use App\Services\Auction\Actions\ReconcileAuctionsAction;
use App\Services\Auction\Actions\ResolveAuctionDisputeAction;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

final class DisputeIndependentStateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate', ['--force' => true]);
    }

    public function test_opening_a_dispute_leaves_the_auction_in_handover_pending(): void
    {
        [$auction, , $winner, $bid] = $this->handoverAuction();
        $settlement = $this->settlement($auction, $bid, SettlementStatus::Paid, sellerHandover: true);

        $dispute = app(OpenAuctionDisputeAction::class)->execute($auction, $winner->id, 'item not as described');

        $this->assertSame('open', $dispute->status);
        $this->assertSame(AuctionStatus::HandoverPending, $auction->refresh()->status);
        $this->assertSame(SettlementStatus::Paid, $settlement->refresh()->status);
    }

    public function test_opening_a_dispute_twice_is_rejected_and_notifies_once(): void
    {
        [$auction, , $winner, $bid] = $this->handoverAuction();
        $this->settlement($auction, $bid, SettlementStatus::Paid, sellerHandover: true);

        app(OpenAuctionDisputeAction::class)->execute($auction, $winner->id, 'first');

        try {
            app(OpenAuctionDisputeAction::class)->execute($auction->refresh(), $winner->id, 'second');
            $this->fail('A second dispute on the same auction should have been rejected.');
        } catch (AuctionException $exception) {
            $this->assertSame('dispute_already_open', $exception->getErrorCode());
            $this->assertSame(409, $exception->getStatusCode());
        }

        $this->assertSame(1, AuctionDispute::where('auction_id', $auction->id)->count());
        $this->assertSame(AuctionStatus::HandoverPending, $auction->refresh()->status);
        $this->assertSame(1, OutboxMessage::where('aggregate_id', $auction->id)
            ->where('event_type', 'auction.dispute_opened')
            ->count());
    }

    public function test_seller_cannot_confirm_handover_twice(): void
    {
        [$auction, $seller, , $bid] = $this->handoverAuction();
        $this->settlement($auction, $bid, SettlementStatus::Paid);

        app(ConfirmAuctionHandoverBySellerAction::class)->execute($auction->refresh(), $seller->id);

        try {
            app(ConfirmAuctionHandoverBySellerAction::class)->execute($auction->refresh(), $seller->id);
            $this->fail('A second handover confirmation should have been rejected.');
        } catch (AuctionException $exception) {
            $this->assertSame('handover_already_confirmed', $exception->getErrorCode());
            $this->assertSame(409, $exception->getStatusCode());
        }

        $this->assertSame(1, OutboxMessage::where('aggregate_id', $auction->id)
            ->where('event_type', 'auction.seller_handover_confirmed')
            ->count());
    }

    public function test_seller_cannot_confirm_handover_while_a_dispute_is_open(): void
    {
        [$auction, $seller, $winner, $bid] = $this->handoverAuction();
        $this->settlement($auction, $bid, SettlementStatus::Paid);

        app(OpenAuctionDisputeAction::class)->execute($auction, $winner->id, 'dispute before handover');

        $this->expectException(AuctionException::class);
        $this->expectExceptionMessage(__('auction.errors.handover_blocked_by_dispute'));

        app(ConfirmAuctionHandoverBySellerAction::class)->execute($auction->refresh(), $seller->id);
    }

    public function test_winner_cannot_confirm_receipt_while_a_dispute_is_open(): void
    {
        [$auction, , $winner, $bid] = $this->handoverAuction();
        $this->settlement($auction, $bid, SettlementStatus::Paid, sellerHandover: true);

        app(OpenAuctionDisputeAction::class)->execute($auction, $winner->id, 'dispute before receipt');

        $this->expectException(AuctionException::class);
        $this->expectExceptionMessage(__('auction.errors.handover_blocked_by_dispute'));

        app(ConfirmAuctionReceiptByWinnerAction::class)->execute($auction->refresh(), $winner->id);
    }

    public function test_resume_handover_resolution_keeps_the_auction_in_handover_pending(): void
    {
        [$auction, , $winner, $bid] = $this->handoverAuction();
        $settlement = $this->settlement($auction, $bid, SettlementStatus::Paid, sellerHandover: true);
        $dispute = app(OpenAuctionDisputeAction::class)->execute($auction, $winner->id, 'needs more time');

        $resolved = app(ResolveAuctionDisputeAction::class)
            ->execute($auction->refresh(), $dispute, $this->user('admin')->id, 'resume_handover', 'seller will redeliver');

        $this->assertSame(AuctionStatus::HandoverPending, $resolved->status);
        $this->assertSame('resolved', $dispute->refresh()->status);
        $this->assertSame(SettlementStatus::HandoverPending, $settlement->refresh()->status);
        $this->assertSame(0, $this->reconciliationFlags());
    }

    public function test_complete_resolution_completes_the_auction_and_creates_the_payout(): void
    {
        [$auction, , $winner, $bid] = $this->handoverAuction();
        $settlement = $this->settlement($auction, $bid, SettlementStatus::Paid, sellerHandover: true);
        $dispute = app(OpenAuctionDisputeAction::class)->execute($auction, $winner->id, 'resolved in buyer favour');

        $resolved = app(ResolveAuctionDisputeAction::class)
            ->execute($auction->refresh(), $dispute, $this->user('admin')->id, 'complete', 'settled amicably');

        $this->assertSame(AuctionStatus::Completed, $resolved->status);
        $this->assertSame(SettlementStatus::Completed, $settlement->refresh()->status);
        $this->assertSame(1, AuctionSellerPayout::where('auction_id', $auction->id)->count());
        $this->assertSame(0, $this->reconciliationFlags());
    }

    public function test_handover_confirmation_works_again_after_the_dispute_is_resolved(): void
    {
        [$auction, $seller, $winner, $bid] = $this->handoverAuction();
        $this->settlement($auction, $bid, SettlementStatus::Paid);
        $dispute = app(OpenAuctionDisputeAction::class)->execute($auction, $winner->id, 'temporary');

        app(ResolveAuctionDisputeAction::class)
            ->execute($auction->refresh(), $dispute, $this->user('admin')->id, 'resume_handover', 'continue');

        $updated = app(ConfirmAuctionHandoverBySellerAction::class)->execute($auction->refresh(), $seller->id);

        $this->assertSame(AuctionStatus::HandoverPending, $updated->status);
        $this->assertNotNull($updated->settlement->seller_handover_confirmed_at);
    }

    public function test_legacy_row_already_in_disputed_status_still_resolves(): void
    {
        [$auction, , $winner, $bid] = $this->handoverAuction();
        $settlement = $this->settlement($auction, $bid, SettlementStatus::Paid, sellerHandover: true);
        $dispute = app(OpenAuctionDisputeAction::class)->execute($auction, $winner->id, 'legacy dispute');

        $auction->forceFill(['status' => AuctionStatus::Disputed])->save();
        $settlement->forceFill(['status' => SettlementStatus::Disputed])->save();

        $resolved = app(ResolveAuctionDisputeAction::class)
            ->execute($auction->refresh(), $dispute, $this->user('admin')->id, 'resume_handover', 'legacy resume');

        $this->assertSame(AuctionStatus::HandoverPending, $resolved->status);
        $this->assertSame(SettlementStatus::HandoverPending, $settlement->refresh()->status);
        $this->assertSame('resolved', $dispute->refresh()->status);
    }

    public function test_legacy_disputed_row_can_be_completed(): void
    {
        [$auction, , $winner, $bid] = $this->handoverAuction();
        $settlement = $this->settlement($auction, $bid, SettlementStatus::Paid, sellerHandover: true);
        $dispute = app(OpenAuctionDisputeAction::class)->execute($auction, $winner->id, 'legacy dispute');

        $auction->forceFill(['status' => AuctionStatus::Disputed])->save();
        $settlement->forceFill(['status' => SettlementStatus::Disputed])->save();

        $resolved = app(ResolveAuctionDisputeAction::class)
            ->execute($auction->refresh(), $dispute, $this->user('admin')->id, 'complete', 'legacy complete');

        $this->assertSame(AuctionStatus::Completed, $resolved->status);
        $this->assertSame(SettlementStatus::Completed, $settlement->refresh()->status);
    }

    private function reconciliationFlags(): int
    {
        $metrics = app(ReconcileAuctionsAction::class)->execute();

        return (int) ($metrics['seller_deposits_resolved_without_terminal_reason'] ?? 0);
    }

    private function handoverAuction(): array
    {
        $seller = $this->user();
        $terms = AuctionTermsVersion::create([
            'version_number' => ((int) AuctionTermsVersion::max('version_number')) + 1,
            'title' => 'Terms',
            'body' => 'Auction terms.',
            'is_active' => true,
            'published_at' => now()->subDay(),
        ]);
        $configuration = AuctionConfigurationVersion::create([
            'version_number' => ((int) AuctionConfigurationVersion::max('version_number')) + 1,
            'configuration' => [
                'seller_deposit_minor' => 0,
                'bidder_deposit_minor' => 10_000,
                'minimum_bid_increment_minor' => 500,
                'seller_deposit_policy' => config('auction.seller_deposit_policy'),
                'winner_default_deposit_policy' => config('auction.winner_default_deposit_policy'),
                'non_winner_deposit_policy' => config('auction.non_winner_deposit_policy'),
                'non_winner_deposit_hold_count' => (int) config('auction.non_winner_deposit_hold_count', 1),
            ],
            'is_active' => true,
            'published_at' => now()->subDay(),
        ]);
        $category = Category::create(['name' => 'dispute-cat-'.Str::ulid(), 'display_order' => 0]);
        $country = Country::create(['name' => 'dispute-country-'.Str::ulid(), 'code' => strtoupper(substr((string) Str::ulid(), 0, 6))]);

        $auction = Auction::create([
            'seller_id' => $seller->id,
            'category_id' => $category->id,
            'country_id' => $country->id,
            'terms_version_id' => $terms->id,
            'configuration_version_id' => $configuration->id,
            'currency_code' => 'JOD',
            'title' => 'Dispute auction '.Str::ulid(),
            'description' => 'Dispute auction.',
            'status' => AuctionStatus::HandoverPending,
            'starting_amount_minor' => 10_000,
            'reserve_amount_minor' => null,
            'minimum_bid_increment_minor' => 500,
            'seller_deposit_amount_minor' => 0,
            'bidder_deposit_amount_minor' => 10_000,
            'platform_fee_type' => 'percentage',
            'platform_fee_basis_points' => 250,
            'platform_fee_fixed_minor' => 0,
            'winner_payment_deadline_hours' => 48,
            'handover_deadline_hours' => 72,
            'starts_at' => now()->subDays(2),
            'original_ends_at' => now()->subHour(),
            'ends_at' => now()->subHour(),
        ]);
        app(AuctionConfigurationSnapshotRepository::class)->createForApprovedAuction($auction, $seller->id);

        $winner = $this->user();
        $participant = AuctionParticipant::create([
            'auction_id' => $auction->id,
            'user_id' => $winner->id,
            'status' => AuctionParticipantStatus::Qualified,
            'registered_at' => now()->subDay(),
            'qualified_at' => now()->subHour(),
        ]);
        $bid = AuctionBid::create([
            'auction_id' => $auction->id,
            'participant_id' => $participant->id,
            'bidder_id' => $winner->id,
            'amount_minor' => 100_000,
            'currency_code' => 'JOD',
            'sequence_number' => 1,
            'idempotency_key' => 'dispute-bid-'.Str::ulid(),
            'server_received_at' => now()->subHour(),
            'accepted_at' => now()->subHour(),
        ]);
        $auction->forceFill(['winning_bid_id' => $bid->id])->save();

        return [$auction, $seller, $winner, $bid];
    }

    private function settlement(Auction $auction, AuctionBid $bid, SettlementStatus $status, bool $sellerHandover = false): AuctionSettlement
    {
        return AuctionSettlement::create([
            'auction_id' => $auction->id,
            'winning_bid_id' => $bid->id,
            'winner_id' => $bid->bidder_id,
            'sequence_number' => 1,
            'is_current' => true,
            'current_marker' => 1,
            'status' => $status,
            'winning_amount_minor' => 100_000,
            'deposit_applied_minor' => 0,
            'platform_fee_minor' => 2_500,
            'seller_net_amount_minor' => 97_500,
            'amount_due_minor' => 100_000,
            'amount_paid_minor' => 100_000,
            'remaining_amount_minor' => 0,
            'currency_code' => 'JOD',
            'payment_due_at' => now()->subHour(),
            'paid_at' => now()->subHour(),
            'handover_due_at' => now()->addDay(),
            'seller_handover_confirmed_at' => $sellerHandover ? now()->subMinute() : null,
        ]);
    }

    private function user(string $role = 'user'): User
    {
        $unique = strtolower((string) Str::ulid());
        $phoneSuffix = str_pad((string) (abs(crc32($unique)) % 10_000_000), 7, '0', STR_PAD_LEFT);

        return User::create([
            'name' => fake()->name(),
            'email' => "dispute-{$unique}@example.test",
            'phone' => '+96271'.$phoneSuffix,
            'password' => Hash::make('password'),
            'role' => $role,
            'email_verified_at' => now(),
        ]);
    }
}
