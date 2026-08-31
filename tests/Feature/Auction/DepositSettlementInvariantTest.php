<?php

declare(strict_types=1);

namespace Tests\Feature\Auction;

use App\Domain\Auction\Enums\AuctionDepositStatus;
use App\Domain\Auction\Enums\AuctionParticipantStatus;
use App\Domain\Auction\Enums\AuctionStatus;
use App\Domain\Auction\Enums\PaymentPurpose;
use App\Domain\Auction\Enums\PaymentTransactionStatus;
use App\Domain\Auction\Enums\RefundTransactionStatus;
use App\Domain\Auction\Enums\SettlementStatus;
use App\Models\Auction\Auction;
use App\Models\Auction\AuctionBid;
use App\Models\Auction\AuctionDeposit;
use App\Models\Auction\AuctionParticipant;
use App\Models\Auction\PaymentTransaction;
use App\Models\Auction\RefundTransaction;
use App\Models\User;
use App\Services\Auction\Actions\FinalizeAuctionAction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\Feature\Auction\Concerns\BuildsOnlinePaymentFixtures;
use Tests\TestCase;

final class DepositSettlementInvariantTest extends TestCase
{
    use BuildsOnlinePaymentFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate', ['--force' => true]);
        $this->enableFakeProvider();
    }

    public function test_a_deposit_reserved_by_a_pending_refund_is_not_applied_to_the_settlement(): void
    {
        [$auction] = $this->paymentAuction(AuctionStatus::Ended);
        [$bidder, $deposit] = $this->qualifiedBidderWithHeldDeposit($auction, 1_000);
        $this->winningBidFor($auction, $bidder, $deposit, 100_000);
        $this->pendingHeldRefund($auction, $deposit, 1_000);

        app(FinalizeAuctionAction::class)->execute($auction);

        $settlement = $auction->refresh()->settlement;
        $deposit->refresh();

        $this->assertSame(0, (int) $settlement->deposit_applied_minor);
        $this->assertSame(100_000, (int) $settlement->amount_due_minor);
        $this->assertSame(1_000, (int) $deposit->held_amount_minor);
        $this->assertSame(0, (int) $deposit->applied_amount_minor);
        $this->assertDepositLedgerBalances($deposit);
    }

    public function test_an_unreserved_deposit_is_still_applied_to_the_settlement(): void
    {
        [$auction] = $this->paymentAuction(AuctionStatus::Ended);
        [$bidder, $deposit] = $this->qualifiedBidderWithHeldDeposit($auction, 1_000);
        $this->winningBidFor($auction, $bidder, $deposit, 100_000);

        app(FinalizeAuctionAction::class)->execute($auction);

        $settlement = $auction->refresh()->settlement;
        $deposit->refresh();

        $this->assertSame(1_000, (int) $settlement->deposit_applied_minor);
        $this->assertSame(99_000, (int) $settlement->amount_due_minor);
        $this->assertSame(0, (int) $deposit->held_amount_minor);
        $this->assertSame(1_000, (int) $deposit->applied_amount_minor);
        $this->assertSame(AuctionDepositStatus::AppliedToSettlement, $deposit->status);
        $this->assertDepositLedgerBalances($deposit);
    }

    public function test_an_applied_winner_deposit_cannot_be_refunded_again(): void
    {
        [$auction] = $this->paymentAuction(AuctionStatus::Ended);
        [$bidder, $deposit] = $this->qualifiedBidderWithHeldDeposit($auction, 1_000);
        $this->winningBidFor($auction, $bidder, $deposit, 100_000);

        app(FinalizeAuctionAction::class)->execute($auction);

        $payment = PaymentTransaction::where('successful_obligation_key', 'deposit:'.$deposit->id)->firstOrFail();

        $this->actingAs($this->paymentUser('admin'), 'sanctum')
            ->postJson('/api/admin/auctions/payments/'.$payment->public_id.'/refund', ['reason' => 'double spend attempt'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'refund_exceeds_available');

        $this->assertSame(0, RefundTransaction::where('deposit_id', $deposit->id)->count());
    }

    public function test_an_applied_winner_deposit_reports_no_refundable_amount(): void
    {
        [$auction] = $this->paymentAuction(AuctionStatus::Ended);
        [$bidder, $deposit] = $this->qualifiedBidderWithHeldDeposit($auction, 1_000);
        $this->winningBidFor($auction, $bidder, $deposit, 100_000);

        app(FinalizeAuctionAction::class)->execute($auction);

        $payment = PaymentTransaction::where('successful_obligation_key', 'deposit:'.$deposit->id)->firstOrFail();

        $detail = $this->actingAs($this->paymentUser('admin'), 'sanctum')
            ->getJson('/api/admin/auctions/payments/'.$payment->public_id)
            ->assertOk()
            ->json('data');

        $this->assertSame(1_000, $detail['deposit']['applied_amount']['minor']);
        $this->assertSame(0, $detail['deposit']['refundable_amount']['minor']);
        $this->assertSame(0, $detail['deposit']['pending_refund_amount']['minor']);
    }

    public function test_cancellation_is_rejected_once_the_winner_settlement_is_paid(): void
    {
        [$auction] = $this->paymentAuction(AuctionStatus::PaymentPending);
        [, $settlement] = $this->winnerSettlement($auction);

        $settlement->forceFill([
            'status' => SettlementStatus::Paid,
            'amount_paid_minor' => 100_000,
            'remaining_amount_minor' => 0,
            'paid_at' => Carbon::now(),
        ])->save();

        $this->actingAs($this->paymentUser('admin'), 'sanctum')
            ->deleteJson('/api/soom/auctions/'.$auction->public_id, [
                'reason' => 'admin decision',
                'reason_code' => 'neutral',
                'liability' => 'neutral',
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'auction_cancellation_blocked_after_winner_payment');

        $this->assertSame(AuctionStatus::PaymentPending, $auction->refresh()->status);
    }

    public function test_cancellation_is_still_allowed_while_the_winner_has_not_paid(): void
    {
        [$auction] = $this->paymentAuction(AuctionStatus::PaymentPending);
        $this->winnerSettlement($auction);

        $this->actingAs($this->paymentUser('admin'), 'sanctum')
            ->deleteJson('/api/soom/auctions/'.$auction->public_id, [
                'reason' => 'admin decision',
                'reason_code' => 'neutral',
                'liability' => 'neutral',
            ])
            ->assertOk();

        $this->assertSame(AuctionStatus::Cancelled, $auction->refresh()->status);
    }

    private function assertDepositLedgerBalances(AuctionDeposit $deposit): void
    {
        $this->assertSame(
            (int) $deposit->required_amount_minor,
            (int) $deposit->held_amount_minor
                + (int) $deposit->applied_amount_minor
                + (int) $deposit->refunded_amount_minor
                + (int) $deposit->forfeited_amount_minor
        );
    }

    private function qualifiedBidderWithHeldDeposit(Auction $auction, int $amountMinor): array
    {
        $bidder = $this->paymentUser();

        $participant = AuctionParticipant::create([
            'auction_id' => $auction->id,
            'user_id' => $bidder->id,
            'status' => AuctionParticipantStatus::Qualified,
            'registered_at' => Carbon::now()->subDays(2),
            'qualified_at' => Carbon::now()->subDay(),
        ]);

        $deposit = AuctionDeposit::create([
            'auction_id' => $auction->id,
            'participant_id' => $participant->id,
            'user_id' => $bidder->id,
            'type' => 'bidder',
            'status' => AuctionDepositStatus::Held,
            'required_amount_minor' => $amountMinor,
            'held_amount_minor' => $amountMinor,
            'currency_code' => 'JOD',
            'held_at' => Carbon::now()->subDay(),
        ]);

        PaymentTransaction::create([
            'auction_id' => $auction->id,
            'user_id' => $bidder->id,
            'purpose' => PaymentPurpose::BidderDeposit,
            'status' => PaymentTransactionStatus::Succeeded,
            'amount_minor' => $amountMinor,
            'currency_code' => 'JOD',
            'provider' => 'fake',
            'provider_transaction_id' => 'txn-'.Str::ulid(),
            'successful_obligation_key' => 'deposit:'.$deposit->id,
            'idempotency_key' => 'deposit:'.$deposit->id.':fake:1',
            'processed_at' => Carbon::now()->subDay(),
        ]);

        return [$bidder, $deposit];
    }

    private function winningBidFor(Auction $auction, User $bidder, AuctionDeposit $deposit, int $amountMinor): AuctionBid
    {
        return AuctionBid::create([
            'auction_id' => $auction->id,
            'participant_id' => $deposit->participant_id,
            'bidder_id' => $bidder->id,
            'amount_minor' => $amountMinor,
            'currency_code' => 'JOD',
            'sequence_number' => 1,
            'idempotency_key' => 'bid-'.Str::ulid(),
            'server_received_at' => Carbon::now(),
            'accepted_at' => Carbon::now(),
        ]);
    }

    private function pendingHeldRefund(Auction $auction, AuctionDeposit $deposit, int $amountMinor): RefundTransaction
    {
        return RefundTransaction::create([
            'auction_id' => $auction->id,
            'deposit_id' => $deposit->id,
            'payment_transaction_id' => PaymentTransaction::where('successful_obligation_key', 'deposit:'.$deposit->id)->value('id'),
            'obligation_type' => 'deposit',
            'obligation_id' => $deposit->id,
            'user_id' => $deposit->user_id,
            'status' => RefundTransactionStatus::Pending,
            'amount_minor' => $amountMinor,
            'held_refund_amount_minor' => $amountMinor,
            'applied_refund_amount_minor' => 0,
            'currency_code' => 'JOD',
            'reason' => 'admin planned refund before finalization',
            'provider' => 'fake',
            'idempotency_key' => 'deposit:'.$deposit->id.':refund:held:'.$amountMinor.':applied:0',
        ]);
    }
}
