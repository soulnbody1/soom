<?php

declare(strict_types=1);

namespace Tests\Feature\Auction;

use App\Domain\Auction\Enums\AuctionDepositStatus;
use App\Domain\Auction\Enums\AuctionParticipantStatus;
use App\Domain\Auction\Enums\AuctionStatus;
use App\Domain\Auction\Enums\SettlementStatus;
use App\Domain\Auction\Exceptions\AuctionException;
use App\Models\Auction\Auction;
use App\Models\Auction\AuctionActivityLog;
use App\Models\Auction\AuctionBid;
use App\Models\Auction\AuctionDeposit;
use App\Models\Auction\AuctionParticipant;
use App\Models\Auction\AuctionSettlement;
use App\Models\Auction\OutboxMessage;
use App\Services\Auction\Actions\FinalizeAuctionAction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\Feature\Auction\Concerns\BuildsOnlinePaymentFixtures;
use Tests\TestCase;

final class EndAuctionEarlyTest extends TestCase
{
    use BuildsOnlinePaymentFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate', ['--force' => true]);
    }

    public function test_the_seller_ends_the_auction_through_the_normal_finalization_flow(): void
    {
        [$auction, $seller] = $this->paymentAuction(AuctionStatus::Live);
        [$bidder] = $this->qualifiedBidderWithBid($auction, 50_000);

        $this->actingAs($seller, 'sanctum')
            ->postJson('/api/soom/auctions/'.$auction->public_id.'/end-now')
            ->assertOk();

        $auction->refresh();
        $settlement = AuctionSettlement::where('auction_id', $auction->id)->firstOrFail();

        $this->assertSame(AuctionStatus::PaymentPending, $auction->status);
        $this->assertSame($bidder->id, (int) $settlement->winner_id);
        $this->assertSame(50_000, (int) $settlement->winning_amount_minor);
        $this->assertSame(SettlementStatus::PaymentPending, $settlement->status);
        $this->assertNotNull($settlement->payment_due_at);

        $this->assertSame(
            AuctionDepositStatus::AppliedToSettlement,
            AuctionDeposit::where('auction_id', $auction->id)->where('user_id', $bidder->id)->firstOrFail()->status
        );

        $this->assertSame(1, OutboxMessage::where('aggregate_id', $auction->id)
            ->where('event_type', 'auction.finalized')
            ->count());
        $this->assertSame(1, AuctionActivityLog::where('auction_id', $auction->id)
            ->where('event_type', 'auction.ended_early')
            ->count());
    }

    public function test_an_admin_ends_the_auction_with_the_same_result(): void
    {
        [$auction] = $this->paymentAuction(AuctionStatus::Live);
        [$bidder] = $this->qualifiedBidderWithBid($auction, 50_000);

        $this->actingAs($this->paymentUser('admin'), 'sanctum')
            ->postJson('/api/admin/auctions/'.$auction->public_id.'/end-now')
            ->assertOk();

        $this->assertSame(AuctionStatus::PaymentPending, $auction->refresh()->status);
        $this->assertSame(
            $bidder->id,
            (int) AuctionSettlement::where('auction_id', $auction->id)->firstOrFail()->winner_id
        );
    }

    public function test_ending_twice_does_not_finalize_twice(): void
    {
        [$auction, $seller] = $this->paymentAuction(AuctionStatus::Live);
        $this->qualifiedBidderWithBid($auction, 50_000);

        $this->actingAs($seller, 'sanctum')
            ->postJson('/api/soom/auctions/'.$auction->public_id.'/end-now')
            ->assertOk();

        $this->actingAs($seller, 'sanctum')
            ->postJson('/api/soom/auctions/'.$auction->public_id.'/end-now')
            ->assertForbidden();

        $this->assertSame(1, AuctionSettlement::where('auction_id', $auction->id)->count());
        $this->assertSame(1, OutboxMessage::where('aggregate_id', $auction->id)
            ->where('event_type', 'auction.finalized')
            ->count());
        $this->assertSame(1, AuctionActivityLog::where('auction_id', $auction->id)
            ->where('event_type', 'auction.ended_early')
            ->count());
    }

    public function test_the_engine_refuses_a_second_early_end_that_slips_past_the_gate(): void
    {
        [$auction, $seller] = $this->paymentAuction(AuctionStatus::Live);
        $this->qualifiedBidderWithBid($auction, 50_000);

        $action = app(FinalizeAuctionAction::class);
        $action->executeEarly($auction, $seller->id, 'user');

        $this->expectException(AuctionException::class);
        $this->expectExceptionMessage(__('auction.errors.early_end_not_available'));

        $action->executeEarly($auction->refresh(), $seller->id, 'user');
    }

    public function test_an_auction_without_bids_cannot_be_ended_early(): void
    {
        [$auction, $seller] = $this->paymentAuction(AuctionStatus::Live);

        $this->actingAs($seller, 'sanctum')
            ->postJson('/api/soom/auctions/'.$auction->public_id.'/end-now')
            ->assertStatus(422)
            ->assertJsonPath('code', 'no_bids_to_accept');

        $this->assertSame(AuctionStatus::Live, $auction->refresh()->status);
        $this->assertSame(0, AuctionSettlement::where('auction_id', $auction->id)->count());
    }

    public function test_a_highest_bid_below_the_reserve_cannot_be_ended_early(): void
    {
        [$auction, $seller] = $this->paymentAuction(
            AuctionStatus::Live,
            ['reserve_amount_minor' => 80_000]
        );
        $this->qualifiedBidderWithBid($auction, 50_000);

        $this->actingAs($seller, 'sanctum')
            ->postJson('/api/soom/auctions/'.$auction->public_id.'/end-now')
            ->assertStatus(422)
            ->assertJsonPath('code', 'reserve_not_met');

        $this->assertSame(AuctionStatus::Live, $auction->refresh()->status);
        $this->assertSame(0, AuctionSettlement::where('auction_id', $auction->id)->count());
    }

    public function test_a_bid_that_meets_the_reserve_can_be_ended_early(): void
    {
        [$auction, $seller] = $this->paymentAuction(
            AuctionStatus::Live,
            ['reserve_amount_minor' => 40_000]
        );
        $this->qualifiedBidderWithBid($auction, 50_000);

        $this->actingAs($seller, 'sanctum')
            ->postJson('/api/soom/auctions/'.$auction->public_id.'/end-now')
            ->assertOk();

        $this->assertSame(AuctionStatus::PaymentPending, $auction->refresh()->status);
    }

    public function test_a_scheduled_auction_cannot_be_ended_early(): void
    {
        [$auction, $seller] = $this->paymentAuction(AuctionStatus::Scheduled);

        $this->actingAs($seller, 'sanctum')
            ->postJson('/api/soom/auctions/'.$auction->public_id.'/end-now')
            ->assertForbidden();
    }

    public function test_a_stranger_cannot_end_someone_elses_auction(): void
    {
        [$auction] = $this->paymentAuction(AuctionStatus::Live);
        $this->qualifiedBidderWithBid($auction, 50_000);

        $this->actingAs($this->paymentUser(), 'sanctum')
            ->postJson('/api/soom/auctions/'.$auction->public_id.'/end-now')
            ->assertForbidden();

        $this->assertSame(AuctionStatus::Live, $auction->refresh()->status);
    }

    private function qualifiedBidderWithBid(Auction $auction, int $amountMinor): array
    {
        $bidder = $this->paymentUser();

        $participant = AuctionParticipant::create([
            'auction_id' => $auction->id,
            'user_id' => $bidder->id,
            'status' => AuctionParticipantStatus::Qualified,
            'registered_at' => Carbon::now()->subDay(),
            'qualified_at' => Carbon::now()->subHour(),
        ]);

        AuctionDeposit::create([
            'auction_id' => $auction->id,
            'participant_id' => $participant->id,
            'user_id' => $bidder->id,
            'type' => 'bidder',
            'status' => AuctionDepositStatus::Held,
            'required_amount_minor' => 1_000,
            'held_amount_minor' => 1_000,
            'currency_code' => 'JOD',
            'held_at' => Carbon::now()->subHour(),
        ]);

        $bid = AuctionBid::create([
            'auction_id' => $auction->id,
            'participant_id' => $participant->id,
            'bidder_id' => $bidder->id,
            'amount_minor' => $amountMinor,
            'currency_code' => 'JOD',
            'sequence_number' => 1,
            'idempotency_key' => 'early-end-'.Str::ulid(),
            'server_received_at' => Carbon::now(),
            'accepted_at' => Carbon::now(),
        ]);

        return [$bidder, $bid];
    }
}
