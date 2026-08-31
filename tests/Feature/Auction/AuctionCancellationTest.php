<?php

declare(strict_types=1);

namespace Tests\Feature\Auction;

use App\Domain\Auction\Enums\AuctionCancellationTrigger;
use App\Domain\Auction\Enums\AuctionDepositStatus;
use App\Domain\Auction\Enums\AuctionParticipantStatus;
use App\Domain\Auction\Enums\AuctionStatus;
use App\Domain\Auction\Enums\PaymentPurpose;
use App\Domain\Auction\Enums\PaymentSubmissionStatus;
use App\Domain\Auction\Enums\PaymentTransactionStatus;
use App\Domain\Auction\Enums\SettlementStatus;
use App\Domain\Auction\Exceptions\AuctionException;
use App\DTO\Auction\AuctionCancellationContextDTO;
use App\Models\Auction\Auction;
use App\Models\Auction\AuctionActivityLog;
use App\Models\Auction\AuctionBid;
use App\Models\Auction\AuctionConfigurationVersion;
use App\Models\Auction\AuctionDeposit;
use App\Models\Auction\AuctionDispute;
use App\Models\Auction\AuctionParticipant;
use App\Models\Auction\AuctionSettlement;
use App\Models\Auction\AuctionTermsVersion;
use App\Models\Auction\PaymentMethod;
use App\Models\Auction\PaymentSubmission;
use App\Models\Auction\PaymentTransaction;
use App\Models\Auction\RefundTransaction;
use App\Models\Category;
use App\Models\Country;
use App\Models\User;
use App\Repositories\Auction\AuctionConfigurationSnapshotRepository;
use App\Services\Auction\Actions\CancelAuctionAction;
use App\Services\Auction\Actions\CancelAuctionFinanciallyAction;
use App\Services\Auction\Actions\ReconcileAuctionsAction;
use App\Services\Auction\Actions\ResolveAuctionDisputeAction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AuctionCancellationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate', ['--force' => true]);
    }

    public function test_seller_admin_and_system_paths_use_financial_service(): void
    {
        [$sellerAuction, $seller] = $this->auction(AuctionStatus::Scheduled);
        app(CancelAuctionAction::class)->execute($sellerAuction, $seller->id, 'user', 'seller changed mind');

        [$adminAuction] = $this->auction(AuctionStatus::Scheduled);
        app(CancelAuctionAction::class)->execute($adminAuction, $this->user('admin')->id, 'admin', 'platform_fault: duplicate listing');

        [$systemAuction] = $this->auction(AuctionStatus::Scheduled);
        app(CancelAuctionFinanciallyAction::class)->execute(new AuctionCancellationContextDTO(
            auctionId: $systemAuction->id,
            trigger: AuctionCancellationTrigger::SystemTriggered,
            actorId: $this->user('admin')->id,
            actorType: 'system',
            reasonCode: 'seller_deposit_timeout',
            reasonText: 'seller_fault: seller deposit deadline expired',
            liability: 'seller',
            requestedAt: Carbon::now(),
        ));

        foreach ([$sellerAuction, $adminAuction, $systemAuction] as $auction) {
            $auction->refresh();
            $this->assertSame(AuctionStatus::Cancelled, $auction->status);
            $this->assertNotNull($auction->cancellation_operation_key);
            $this->assertNotNull($auction->financial_cancellation_completed_at);
        }
    }

    public function test_dispute_cancel_uses_central_service_and_not_status_only(): void
    {
        [$auction, $winner, $bid] = $this->auctionWithWinner(AuctionStatus::Disputed);
        $settlement = $this->settlement($auction, $bid, paid: false);
        $this->pendingWinnerSubmission($auction, $settlement, $winner);
        $dispute = AuctionDispute::create([
            'auction_id' => $auction->id,
            'settlement_id' => $settlement->id,
            'opened_by' => $winner->id,
            'status' => 'open',
            'reason' => 'item unavailable',
            'opened_at' => Carbon::now()->subHour(),
        ]);

        $cancelled = app(ResolveAuctionDisputeAction::class)->execute(
            $auction,
            $dispute,
            $this->user('admin')->id,
            'cancel',
            'seller breach confirmed',
            'forfeit'
        );

        $this->assertSame(AuctionStatus::Cancelled, $cancelled->status);
        $this->assertNotNull($cancelled->cancellation_operation_key);
        $this->assertSame('resolved', $dispute->refresh()->status);
        $this->assertSame(0, PaymentSubmission::where('auction_id', $auction->id)->where('status', PaymentSubmissionStatus::PendingReview)->count());
        $this->assertSame(0, AuctionSettlement::where('auction_id', $auction->id)->where('current_marker', 1)->count());
    }

    public function test_admin_cancellation_uses_explicit_reason_code_and_liability(): void
    {
        [$auction] = $this->auction(AuctionStatus::Scheduled);

        $cancelled = app(CancelAuctionAction::class)->execute(
            $auction,
            $this->user('admin')->id,
            'admin',
            'duplicate listing',
            'platform_fault',
            'platform'
        );

        $this->assertSame(AuctionCancellationTrigger::PlatformFault->value, $cancelled->cancellation_trigger);
        $this->assertSame('platform_fault', $cancelled->cancellation_reason_code);
        $this->assertSame('platform', $cancelled->cancellation_liability);
    }

    public function test_closes_current_settlement_supersedes_submission_and_refunds_winner_payment(): void
    {
        [$auction, $winner, $bid] = $this->auctionWithWinner(AuctionStatus::HandoverPending);
        $winnerDeposit = $this->bidderDeposit($auction, $winner, 10_000, applied: true);
        $depositPayment = $this->paymentForDeposit($auction, $winnerDeposit, $winner, 10_000);
        $settlement = $this->settlement($auction, $bid, paid: true);
        $winnerPayment = $this->paymentForSettlement($auction, $settlement, $winner, 60_000);
        $pendingSubmission = $this->pendingWinnerSubmission($auction, $settlement, $winner);

        app(CancelAuctionAction::class)->execute($auction, $this->user('admin')->id, 'admin', 'platform_fault: cancellation');

        $settlement->refresh();
        $winnerDeposit->refresh();
        $pendingSubmission->refresh();
        $this->assertSame(SettlementStatus::Cancelled, $settlement->status);
        $this->assertFalse($settlement->is_current);
        $this->assertNull($settlement->current_marker);
        $this->assertNotNull($settlement->cancelled_at);
        $this->assertSame(PaymentSubmissionStatus::Rejected, $pendingSubmission->status);
        $this->assertSame('auction_cancelled', $pendingSubmission->review_note);
        $this->assertSame(AuctionDepositStatus::RefundPending, $winnerDeposit->status);
        $this->assertSame(1, RefundTransaction::where('payment_transaction_id', $depositPayment->id)->count());
        $this->assertSame(1, RefundTransaction::where('payment_transaction_id', $winnerPayment->id)->count());
        $this->assertSame(PaymentTransactionStatus::Succeeded, $winnerPayment->refresh()->status);
    }

    public function test_refund_failure_prevents_financial_cancellation_completion(): void
    {
        [$auction, $winner, $bid] = $this->auctionWithWinner(AuctionStatus::PaymentPending);
        $winnerDeposit = $this->bidderDeposit($auction, $winner, 10_000);
        $this->paymentForDeposit($auction, $winnerDeposit, $winner, 10_000);
        $this->settlement($auction, $bid, paid: false);
        $winnerDeposit->forceFill([
            'held_amount_minor' => 0,
            'applied_amount_minor' => 0,
        ])->save();

        try {
            app(CancelAuctionFinanciallyAction::class)->execute(new AuctionCancellationContextDTO(
                auctionId: $auction->id,
                trigger: AuctionCancellationTrigger::PlatformFault,
                actorId: $this->user('admin')->id,
                actorType: 'admin',
                reasonCode: 'platform_fault',
                reasonText: 'platform_fault: refund cannot be planned',
                liability: 'platform',
                requestedAt: Carbon::now(),
            ));

            $this->fail('Cancellation should fail when a required refund cannot be planned.');
        } catch (AuctionException $exception) {
            $this->assertSame(__('auction.errors.zero_refund_not_allowed'), $exception->getMessage());
        }

        $auction->refresh();
        $this->assertSame(AuctionStatus::PaymentPending, $auction->status);
        $this->assertNull($auction->financial_cancellation_completed_at);
        $this->assertFalse($auction->financial_cancellation_manual_review_required);
        $this->assertSame(0, RefundTransaction::where('auction_id', $auction->id)->count());
    }

    public function test_is_idempotent_for_plan_refunds_and_audit(): void
    {
        [$auction, $seller] = $this->auction(AuctionStatus::Scheduled);
        $deposit = $this->sellerDeposit($auction, $seller, 10_000);
        $this->paymentForDeposit($auction, $deposit, $seller, 10_000, PaymentPurpose::SellerDeposit);

        $action = app(CancelAuctionAction::class);
        $action->execute($auction, $this->user('admin')->id, 'admin', 'platform_fault: first');
        $action->execute($auction->refresh(), $this->user('admin')->id, 'admin', 'platform_fault: replay');

        $this->assertSame(1, RefundTransaction::where('auction_id', $auction->id)->count());
        $this->assertSame(1, AuctionActivityLog::where('auction_id', $auction->id)->where('event_type', 'auction.cancellation_started')->count());
        $this->assertSame(1, AuctionActivityLog::where('auction_id', $auction->id)->where('event_type', 'auction.cancellation_financial_plan_created')->count());
    }

    public function test_rejects_completed_auction(): void
    {
        [$auction] = $this->auction(AuctionStatus::Completed);

        $this->expectException(AuctionException::class);
        $this->expectExceptionMessage(__('auction.errors.auction_cancellation_not_allowed'));

        app(CancelAuctionAction::class)->execute($auction, $this->user('admin')->id, 'admin', 'late cancellation');
    }

    public function test_reconciliation_detects_unbalanced_cancelled_auction(): void
    {
        [$auction, $winner, $bid] = $this->auctionWithWinner(AuctionStatus::Cancelled);
        $this->settlement($auction, $bid, paid: false);
        $this->pendingWinnerSubmission($auction, $auction->settlement, $winner);
        $this->bidderDeposit($auction, $winner, 10_000);

        $report = app(ReconcileAuctionsAction::class)->execute();

        $this->assertGreaterThanOrEqual(1, $report['cancelled_auctions_financially_unbalanced']);
        $this->assertGreaterThanOrEqual(1, $report['cancelled_current_settlements_active']);
        $this->assertGreaterThanOrEqual(1, $report['cancelled_pending_payment_submissions']);
        $this->assertGreaterThanOrEqual(1, $report['cancelled_bidder_deposits_held']);
    }

    private function auction(AuctionStatus $status): array
    {
        $seller = $this->user();
        $terms = AuctionTermsVersion::create([
            'version_number' => ((int) AuctionTermsVersion::max('version_number')) + 1,
            'title' => 'Terms',
            'body' => 'Auction terms.',
            'is_active' => true,
            'published_at' => Carbon::now()->subDay(),
        ]);
        $configuration = AuctionConfigurationVersion::create([
            'version_number' => ((int) AuctionConfigurationVersion::max('version_number')) + 1,
            'configuration' => [
                'non_winner_deposit_policy' => config('auction.non_winner_deposit_policy'),
                'non_winner_deposit_hold_count' => (int) config('auction.non_winner_deposit_hold_count', 1),
                'seller_deposit_policy' => config('auction.seller_deposit_policy'),
                'winner_default_deposit_policy' => config('auction.winner_default_deposit_policy'),
            ],
            'is_active' => true,
            'published_at' => Carbon::now()->subDay(),
        ]);
        $category = Category::create(['name' => 'auction-cancel-cat-'.Str::ulid(), 'display_order' => 0]);
        $country = Country::create(['name' => 'auction-cancel-country-'.Str::ulid(), 'code' => strtoupper(substr((string) Str::ulid(), 0, 6))]);

        $auction = Auction::create([
            'seller_id' => $seller->id,
            'category_id' => $category->id,
            'country_id' => $country->id,
            'terms_version_id' => $terms->id,
            'configuration_version_id' => $configuration->id,
            'currency_code' => 'JOD',
            'title' => 'Auction cancellation',
            'description' => 'Auction cancellation.',
            'status' => $status,
            'starting_amount_minor' => 10_000,
            'reserve_amount_minor' => null,
            'minimum_bid_increment_minor' => 500,
            'seller_deposit_amount_minor' => 10_000,
            'bidder_deposit_amount_minor' => 10_000,
            'platform_fee_type' => 'percentage',
            'platform_fee_basis_points' => 250,
            'platform_fee_fixed_minor' => 0,
            'winner_payment_deadline_hours' => 48,
            'handover_deadline_hours' => 72,
            'starts_at' => Carbon::now()->subDays(2),
            'original_ends_at' => Carbon::now()->subHour(),
            'ends_at' => Carbon::now()->subHour(),
        ]);
        app(AuctionConfigurationSnapshotRepository::class)->createForApprovedAuction($auction, $seller->id);

        return [$auction->refresh(), $seller];
    }

    private function auctionWithWinner(AuctionStatus $status): array
    {
        [$auction] = $this->auction($status);
        $winner = $this->user();
        $participant = AuctionParticipant::create([
            'auction_id' => $auction->id,
            'user_id' => $winner->id,
            'status' => AuctionParticipantStatus::Qualified,
            'registered_at' => Carbon::now()->subDays(2),
            'qualified_at' => Carbon::now()->subDay(),
        ]);
        $bid = AuctionBid::create([
            'auction_id' => $auction->id,
            'participant_id' => $participant->id,
            'bidder_id' => $winner->id,
            'amount_minor' => 100_000,
            'currency_code' => 'JOD',
            'sequence_number' => 1,
            'idempotency_key' => 'auction-cancel-bid-'.Str::ulid(),
            'server_received_at' => Carbon::now()->subHour(),
            'accepted_at' => Carbon::now()->subHour(),
        ]);
        $auction->forceFill(['winning_bid_id' => $bid->id])->save();

        return [$auction->refresh(), $winner, $bid];
    }

    private function settlement(Auction $auction, AuctionBid $bid, bool $paid): AuctionSettlement
    {
        return AuctionSettlement::create([
            'auction_id' => $auction->id,
            'winning_bid_id' => $bid->id,
            'winner_id' => $bid->bidder_id,
            'sequence_number' => 1,
            'is_current' => true,
            'current_marker' => 1,
            'status' => SettlementStatus::PaymentPending,
            'winning_amount_minor' => 100_000,
            'deposit_applied_minor' => 10_000,
            'platform_fee_minor' => 2_500,
            'seller_net_amount_minor' => 97_500,
            'amount_due_minor' => 90_000,
            'amount_paid_minor' => $paid ? 60_000 : 0,
            'remaining_amount_minor' => $paid ? 30_000 : 90_000,
            'currency_code' => 'JOD',
            'payment_due_at' => Carbon::now()->addDay(),
            'paid_at' => null,
        ]);
    }

    private function sellerDeposit(Auction $auction, User $seller, int $amount): AuctionDeposit
    {
        return AuctionDeposit::create([
            'auction_id' => $auction->id,
            'user_id' => $seller->id,
            'type' => 'seller',
            'status' => AuctionDepositStatus::Held,
            'required_amount_minor' => $amount,
            'held_amount_minor' => $amount,
            'currency_code' => 'JOD',
            'held_at' => Carbon::now()->subHour(),
        ]);
    }

    private function bidderDeposit(Auction $auction, User $user, int $amount, bool $applied = false): AuctionDeposit
    {
        return AuctionDeposit::create([
            'auction_id' => $auction->id,
            'user_id' => $user->id,
            'type' => 'bidder',
            'status' => $applied ? AuctionDepositStatus::AppliedToSettlement : AuctionDepositStatus::Held,
            'required_amount_minor' => $amount,
            'held_amount_minor' => $applied ? 0 : $amount,
            'applied_amount_minor' => $applied ? $amount : 0,
            'currency_code' => 'JOD',
            'held_at' => Carbon::now()->subHour(),
        ]);
    }

    private function pendingWinnerSubmission(Auction $auction, AuctionSettlement $settlement, User $winner): PaymentSubmission
    {
        return PaymentSubmission::create([
            'auction_id' => $auction->id,
            'settlement_id' => $settlement->id,
            'user_id' => $winner->id,
            'payment_method_id' => $this->paymentMethod()->id,
            'purpose' => PaymentPurpose::WinnerSettlement,
            'status' => PaymentSubmissionStatus::PendingReview,
            'amount_minor' => 90_000,
            'currency_code' => 'JOD',
            'receipt_disk' => 'spaces_private',
            'receipt_path' => 'auction-cancel.pdf',
            'receipt_mime_type' => 'application/pdf',
            'receipt_size_bytes' => 100,
            'idempotency_key' => 'auction-cancel-submission-'.Str::ulid(),
            'submitted_at' => Carbon::now()->subMinute(),
        ]);
    }

    private function paymentForDeposit(
        Auction $auction,
        AuctionDeposit $deposit,
        User $user,
        int $amount,
        PaymentPurpose $purpose = PaymentPurpose::BidderDeposit,
    ): PaymentTransaction {
        $submission = PaymentSubmission::create([
            'auction_id' => $auction->id,
            'deposit_id' => $deposit->id,
            'user_id' => $user->id,
            'payment_method_id' => $this->paymentMethod()->id,
            'purpose' => $purpose,
            'status' => PaymentSubmissionStatus::Approved,
            'amount_minor' => $amount,
            'currency_code' => 'JOD',
            'receipt_disk' => 'spaces_private',
            'receipt_path' => 'auction-cancel-deposit.pdf',
            'receipt_mime_type' => 'application/pdf',
            'receipt_size_bytes' => 100,
            'idempotency_key' => 'auction-cancel-deposit-'.Str::ulid(),
            'submitted_at' => Carbon::now()->subHour(),
            'reviewed_at' => Carbon::now()->subHour(),
        ]);

        return $this->payment($auction, $submission, $user, $purpose, $amount);
    }

    private function paymentForSettlement(Auction $auction, AuctionSettlement $settlement, User $user, int $amount): PaymentTransaction
    {
        $submission = PaymentSubmission::create([
            'auction_id' => $auction->id,
            'settlement_id' => $settlement->id,
            'user_id' => $user->id,
            'payment_method_id' => $this->paymentMethod()->id,
            'purpose' => PaymentPurpose::WinnerSettlement,
            'status' => PaymentSubmissionStatus::Approved,
            'amount_minor' => $amount,
            'currency_code' => 'JOD',
            'receipt_disk' => 'spaces_private',
            'receipt_path' => 'auction-cancel-settlement.pdf',
            'receipt_mime_type' => 'application/pdf',
            'receipt_size_bytes' => 100,
            'idempotency_key' => 'auction-cancel-settlement-'.Str::ulid(),
            'submitted_at' => Carbon::now()->subHour(),
            'reviewed_at' => Carbon::now()->subHour(),
        ]);

        return $this->payment($auction, $submission, $user, PaymentPurpose::WinnerSettlement, $amount);
    }

    private function payment(Auction $auction, PaymentSubmission $submission, User $user, PaymentPurpose $purpose, int $amount): PaymentTransaction
    {
        return PaymentTransaction::create([
            'payment_submission_id' => $submission->id,
            'auction_id' => $auction->id,
            'user_id' => $user->id,
            'purpose' => $purpose,
            'status' => PaymentTransactionStatus::Succeeded,
            'amount_minor' => $amount,
            'currency_code' => 'JOD',
            'provider' => 'manual',
            'provider_transaction_id' => 'auction-cancel-provider-'.Str::ulid(),
            'idempotency_key' => 'auction-cancel-payment-'.Str::ulid(),
            'successful_obligation_key' => $submission->deposit_id ? "deposit:{$submission->deposit_id}" : "settlement:{$submission->settlement_id}",
            'processed_at' => Carbon::now()->subHour(),
        ]);
    }

    private function paymentMethod(): PaymentMethod
    {
        return PaymentMethod::create([
            'name' => 'Auction cancellation method',
            'code' => 'auction-cancel-'.Str::ulid(),
            'instructions' => 'Upload receipt.',
            'requires_manual_review' => true,
            'is_active' => true,
        ]);
    }

    private function user(string $role = 'user'): User
    {
        $unique = strtolower((string) Str::ulid());
        $phoneSuffix = str_pad((string) (abs(crc32($unique)) % 10_000_000), 7, '0', STR_PAD_LEFT);

        return User::create([
            'name' => fake()->name(),
            'email' => "auction-cancel-{$unique}@example.test",
            'phone' => '+96270'.$phoneSuffix,
            'password' => Hash::make('password'),
            'role' => $role,
            'email_verified_at' => Carbon::now(),
        ]);
    }
}
