<?php

declare(strict_types=1);

namespace Tests\Feature\Auction;

use App\Domain\Auction\Enums\AuctionDepositStatus;
use App\Domain\Auction\Enums\AuctionParticipantStatus;
use App\Domain\Auction\Enums\AuctionStatus;
use App\Domain\Auction\Enums\PaymentPurpose;
use App\Domain\Auction\Enums\PaymentSubmissionStatus;
use App\Domain\Auction\Enums\PaymentTransactionStatus;
use App\Domain\Auction\Enums\SettlementStatus;
use App\Models\Auction\Auction;
use App\Models\Auction\AuctionActivityLog;
use App\Models\Auction\AuctionBid;
use App\Models\Auction\AuctionConfigurationVersion;
use App\Models\Auction\AuctionDeposit;
use App\Models\Auction\AuctionDispute;
use App\Models\Auction\AuctionParticipant;
use App\Models\Auction\AuctionSettlement;
use App\Models\Auction\AuctionTermsVersion;
use App\Models\Auction\OutboxMessage;
use App\Models\Auction\PaymentMethod;
use App\Models\Auction\PaymentSubmission;
use App\Models\Auction\PaymentTransaction;
use App\Models\Auction\RefundTransaction;
use App\Models\Category;
use App\Models\Country;
use App\Models\User;
use App\Repositories\Auction\AuctionConfigurationSnapshotRepository;
use App\Services\Auction\Actions\CancelAuctionAction;
use App\Services\Auction\Actions\ConfirmAuctionReceiptByWinnerAction;
use App\Services\Auction\Actions\FinalizeAuctionAction;
use App\Services\Auction\Actions\MarkWinnerDefaultedAction;
use App\Services\Auction\Actions\ReconcileAuctionsAction;
use App\Services\Auction\Actions\ResolveAuctionDisputeAction;
use App\Services\Auction\Actions\ResolveSellerDepositDispositionAction;
use App\Services\Auction\Actions\ReviewAuctionAction;
use App\Services\Auction\Actions\ReviewPaymentSubmissionAction;
use App\Services\Auction\Actions\SubmitPaymentSubmissionAction;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class SellerDepositLifecycleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate', ['--force' => true]);
        Storage::fake('spaces_private');
    }

    public function test_zero_seller_deposit_approval_goes_directly_to_scheduled_without_payment_flow(): void
    {
        [$auction] = $this->auction(AuctionStatus::PendingReview, sellerDeposit: 0);

        $approved = app(ReviewAuctionAction::class)->approve($auction, $this->user('admin')->id, 'approved');

        $this->assertSame(AuctionStatus::Scheduled, $approved->status);
        $this->assertSame(0, AuctionDeposit::where('auction_id', $auction->id)->where('type', 'seller')->count());
        $this->assertSame(0, PaymentSubmission::where('auction_id', $auction->id)->where('purpose', PaymentPurpose::SellerDeposit)->count());
    }

    public function test_rejected_auction_before_payment_closes_seller_deposit_obligation_without_refund(): void
    {
        [$auction, $seller] = $this->auction(AuctionStatus::PendingReview);
        $deposit = $this->sellerDeposit($auction, $seller, AuctionDepositStatus::PendingSubmission);

        app(ReviewAuctionAction::class)->reject($auction, $this->user('admin')->id, 'not accepted');

        $this->assertSame(AuctionDepositStatus::Rejected, $deposit->refresh()->status);
        $this->assertSame('seller_deposit_closed_without_payment', $deposit->hold_reason);
        $this->assertSame(0, RefundTransaction::where('deposit_id', $deposit->id)->count());
    }

    public function test_seller_deposit_payment_can_be_rejected_resubmitted_and_approved_once(): void
    {
        [$auction, $seller] = $this->auction(AuctionStatus::AwaitingSellerDeposit);
        $method = $this->paymentMethod();

        $first = app(SubmitPaymentSubmissionAction::class)->execute(
            $auction,
            $seller->id,
            PaymentPurpose::SellerDeposit,
            $method->public_id,
            UploadedFile::fake()->create('first.pdf', 20, 'application/pdf'),
            'seller-deposit-first'
        );
        app(ReviewPaymentSubmissionAction::class)->reject($first, $this->user('admin')->id, 'unclear receipt');

        $second = app(SubmitPaymentSubmissionAction::class)->execute(
            $auction->refresh(),
            $seller->id,
            PaymentPurpose::SellerDeposit,
            $method->public_id,
            UploadedFile::fake()->create('second.pdf', 20, 'application/pdf'),
            'seller-deposit-second'
        );
        app(ReviewPaymentSubmissionAction::class)->approve($second, $this->user('admin')->id, 'approved');

        $deposit = AuctionDeposit::where('auction_id', $auction->id)->where('type', 'seller')->sole();
        $this->assertSame(AuctionDepositStatus::Held, $deposit->status);
        $this->assertSame(10_000, $deposit->held_amount_minor);
        $this->assertSame(1, PaymentTransaction::where('successful_obligation_key', "deposit:{$deposit->id}")->count());
        $this->assertSame(AuctionStatus::Scheduled, $auction->refresh()->status);
    }

    public function test_unsold_auction_plans_one_seller_deposit_refund(): void
    {
        [$auction, $seller] = $this->auction(AuctionStatus::Ended);
        $deposit = $this->paidSellerDeposit($auction, $seller);

        app(FinalizeAuctionAction::class)->execute($auction);
        app(FinalizeAuctionAction::class)->execute($auction->refresh());

        $this->assertSame(AuctionStatus::Unsold, $auction->refresh()->status);
        $this->assertSame(AuctionDepositStatus::RefundPending, $deposit->refresh()->status);
        $this->assertSame(1, RefundTransaction::where('deposit_id', $deposit->id)->count());
    }

    public function test_completed_auction_plans_seller_deposit_refund_without_marking_refunded(): void
    {
        [$auction, $seller] = $this->auction(AuctionStatus::HandoverPending);
        $deposit = $this->paidSellerDeposit($auction, $seller);
        [$winner, $bid] = $this->winnerBid($auction);
        $this->settlement($auction, $bid, SettlementStatus::Paid, sellerHandover: true);

        app(ConfirmAuctionReceiptByWinnerAction::class)->execute($auction, $winner->id);

        $this->assertSame(AuctionDepositStatus::RefundPending, $deposit->refresh()->status);
        $this->assertSame(1, RefundTransaction::where('deposit_id', $deposit->id)->count());
        $this->assertSame(0, $deposit->refunded_amount_minor);
    }

    public function test_seller_cancellation_before_start_refunds_but_after_start_requires_manual_review(): void
    {
        [$scheduled, $seller] = $this->auction(AuctionStatus::Scheduled);
        $scheduledDeposit = $this->paidSellerDeposit($scheduled, $seller);

        app(CancelAuctionAction::class)->execute($scheduled, $seller->id, 'user', 'seller changed mind');
        $this->assertSame(1, RefundTransaction::where('deposit_id', $scheduledDeposit->id)->count());

        [$live, $liveSeller] = $this->auction(AuctionStatus::Live);
        $liveDeposit = $this->paidSellerDeposit($live, $liveSeller);

        app(CancelAuctionAction::class)->execute($live, $liveSeller->id, 'user', 'seller changed mind after start');
        $this->assertSame(AuctionDepositStatus::Held, $liveDeposit->refresh()->status);
        $this->assertSame('seller_deposit_manual_review', $liveDeposit->hold_reason);
        $this->assertSame(0, RefundTransaction::where('deposit_id', $liveDeposit->id)->count());
    }

    public function test_admin_cancellation_platform_fault_refunds_and_seller_fault_forfeits(): void
    {
        [$platformAuction, $seller] = $this->auction(AuctionStatus::Scheduled);
        $platformDeposit = $this->paidSellerDeposit($platformAuction, $seller);

        app(CancelAuctionAction::class)->execute($platformAuction, $this->user('admin')->id, 'admin', 'platform_fault: duplicate listing bug');
        $this->assertSame(1, RefundTransaction::where('deposit_id', $platformDeposit->id)->count());

        [$sellerFaultAuction, $faultSeller] = $this->auction(AuctionStatus::Scheduled);
        $sellerFaultDeposit = $this->paidSellerDeposit($sellerFaultAuction, $faultSeller);

        app(CancelAuctionAction::class)->execute($sellerFaultAuction, $this->user('admin')->id, 'admin', 'seller_fault: prohibited item');
        $sellerFaultDeposit->refresh();
        $this->assertSame(AuctionDepositStatus::Forfeited, $sellerFaultDeposit->status);
        $this->assertSame(10_000, $sellerFaultDeposit->forfeited_amount_minor);
        $this->assertSame(0, RefundTransaction::where('deposit_id', $sellerFaultDeposit->id)->count());
    }

    public function test_system_cancellation_distinguishes_platform_and_seller_fault(): void
    {
        $systemActor = $this->user('admin')->id;
        [$platformAuction, $seller] = $this->auction(AuctionStatus::Scheduled);
        $platformDeposit = $this->paidSellerDeposit($platformAuction, $seller);

        app(CancelAuctionAction::class)->execute($platformAuction, $systemActor, 'system', 'platform_fault: setup invalid');
        $this->assertSame(1, RefundTransaction::where('deposit_id', $platformDeposit->id)->count());

        [$sellerFaultAuction, $faultSeller] = $this->auction(AuctionStatus::Scheduled);
        $sellerFaultDeposit = $this->paidSellerDeposit($sellerFaultAuction, $faultSeller);

        app(CancelAuctionAction::class)->execute($sellerFaultAuction, $systemActor, 'system', 'seller_fault: seller deposit deadline expired after payment');
        $this->assertSame(AuctionDepositStatus::Forfeited, $sellerFaultDeposit->refresh()->status);
        $this->assertSame(10_000, $sellerFaultDeposit->forfeited_amount_minor);
    }

    public function test_winner_default_keeps_seller_deposit_held_with_reason(): void
    {
        [$auction, $seller] = $this->auction(AuctionStatus::PaymentPending);
        $sellerDeposit = $this->paidSellerDeposit($auction, $seller);
        [$winner, $bid] = $this->winnerBid($auction);
        $this->settlement($auction, $bid, SettlementStatus::PaymentPending);

        app(MarkWinnerDefaultedAction::class)->execute($auction, $this->user('admin')->id, 'deadline expired', false, true);

        $this->assertSame(AuctionDepositStatus::Held, $sellerDeposit->refresh()->status);
        $this->assertSame('seller_deposit_keep_held', $sellerDeposit->hold_reason);
        $this->assertSame(0, RefundTransaction::where('deposit_id', $sellerDeposit->id)->count());
    }

    public function test_seller_breach_forfeits_and_partial_forfeiture_refunds_remainder(): void
    {
        [$auction, $seller] = $this->auction(AuctionStatus::Disputed);
        $deposit = $this->paidSellerDeposit($auction, $seller);

        app(ResolveSellerDepositDispositionAction::class)->execute($auction, 'seller_breach', $this->user('admin')->id, 'admin', 'seller no-show');
        $this->assertSame(AuctionDepositStatus::Forfeited, $deposit->refresh()->status);
        $this->assertSame(10_000, $deposit->forfeited_amount_minor);

        [$partialAuction, $partialSeller] = $this->auction(AuctionStatus::Disputed, policy: [
            'seller_deposit_policy' => [
                'seller_breach' => 'partial_forfeit',
                'seller_breach_forfeit_amount_minor' => 3_000,
            ],
        ]);
        $partialDeposit = $this->paidSellerDeposit($partialAuction, $partialSeller);

        app(ResolveSellerDepositDispositionAction::class)->execute($partialAuction, 'seller_breach', $this->user('admin')->id, 'admin', 'material misrepresentation');
        $partialDeposit->refresh();
        $refund = RefundTransaction::where('deposit_id', $partialDeposit->id)->sole();

        $this->assertSame(3_000, $partialDeposit->forfeited_amount_minor);
        $this->assertSame(7_000, $partialDeposit->held_amount_minor);
        $this->assertSame(7_000, $refund->amount_minor);
        $this->assertSame(7_000, $refund->held_refund_amount_minor);
    }

    public function test_dispute_resolution_can_explicitly_forfeit_seller_deposit(): void
    {
        [$auction, $seller] = $this->auction(AuctionStatus::Disputed);
        $deposit = $this->paidSellerDeposit($auction, $seller);
        [$winner, $bid] = $this->winnerBid($auction);
        $settlement = $this->settlement($auction, $bid, SettlementStatus::Disputed);
        $dispute = AuctionDispute::create([
            'auction_id' => $auction->id,
            'settlement_id' => $settlement->id,
            'opened_by' => $winner->id,
            'status' => 'open',
            'reason' => 'seller breach',
            'opened_at' => now()->subHour(),
        ]);

        app(ResolveAuctionDisputeAction::class)->execute($auction, $dispute, $this->user('admin')->id, 'cancel', 'seller breach confirmed', 'forfeit');

        $this->assertSame(AuctionDepositStatus::Forfeited, $deposit->refresh()->status);
        $this->assertSame(10_000, $deposit->forfeited_amount_minor);
    }

    public function test_seller_deposit_disposition_is_idempotent_for_refund_forfeit_audit_and_outbox(): void
    {
        [$refundAuction, $seller] = $this->auction(AuctionStatus::Completed);
        $refundDeposit = $this->paidSellerDeposit($refundAuction, $seller);
        $action = app(ResolveSellerDepositDispositionAction::class);

        $action->execute($refundAuction, 'completed');
        $action->execute($refundAuction->refresh(), 'completed');

        $this->assertSame(1, RefundTransaction::where('deposit_id', $refundDeposit->id)->count());
        $this->assertSame(1, AuctionActivityLog::where('auction_id', $refundAuction->id)->where('event_type', 'auction.seller_deposit_refund_planned')->count());
        $this->assertSame(1, OutboxMessage::where('aggregate_id', $refundAuction->id)->where('event_type', 'auction.seller_deposit_refund_planned')->count());

        [$forfeitAuction, $forfeitSeller] = $this->auction(AuctionStatus::Disputed);
        $forfeitDeposit = $this->paidSellerDeposit($forfeitAuction, $forfeitSeller);

        $action->execute($forfeitAuction, 'seller_breach');
        $action->execute($forfeitAuction->refresh(), 'seller_breach');

        $this->assertSame(10_000, $forfeitDeposit->refresh()->forfeited_amount_minor);
        $this->assertSame(1, AuctionActivityLog::where('auction_id', $forfeitAuction->id)->where('event_type', 'auction.seller_deposit_forfeited')->count());
        $this->assertSame(1, OutboxMessage::where('aggregate_id', $forfeitAuction->id)->where('event_type', 'auction.seller_deposit_forfeited')->count());
    }

    public function test_reconciliation_detects_terminal_held_seller_deposit_without_reason(): void
    {
        [$auction, $seller] = $this->auction(AuctionStatus::Completed);
        $this->paidSellerDeposit($auction, $seller);

        $report = app(ReconcileAuctionsAction::class)->execute();

        $this->assertSame(1, $report['terminal_seller_deposits_held_without_active_need']);
    }

    private function auction(AuctionStatus $status, int $sellerDeposit = 10_000, array $policy = []): array
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
            'configuration' => array_replace_recursive([
                'seller_deposit_minor' => $sellerDeposit,
                'bidder_deposit_minor' => 10_000,
                'minimum_bid_increment_minor' => 500,
                'seller_deposit_policy' => config('auction.seller_deposit_policy'),
                'winner_default_deposit_policy' => config('auction.winner_default_deposit_policy'),
                'non_winner_deposit_policy' => config('auction.non_winner_deposit_policy'),
                'non_winner_deposit_hold_count' => (int) config('auction.non_winner_deposit_hold_count', 1),
            ], $policy),
            'is_active' => true,
            'published_at' => now()->subDay(),
        ]);
        $category = Category::create(['name' => 'seller-deposit-cat-'.Str::ulid(), 'display_order' => 0]);
        $country = Country::create(['name' => 'seller-deposit-country-'.Str::ulid(), 'code' => strtoupper(substr((string) Str::ulid(), 0, 6))]);

        $auction = Auction::create([
            'seller_id' => $seller->id,
            'category_id' => $category->id,
            'country_id' => $country->id,
            'terms_version_id' => $terms->id,
            'configuration_version_id' => $configuration->id,
            'currency_code' => 'JOD',
            'title' => 'Seller deposit auction',
            'description' => 'Seller deposit auction.',
            'status' => $status,
            'starting_amount_minor' => 10_000,
            'reserve_amount_minor' => null,
            'minimum_bid_increment_minor' => 500,
            'seller_deposit_amount_minor' => $sellerDeposit,
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

        return [$auction, $seller];
    }

    private function sellerDeposit(Auction $auction, User $seller, AuctionDepositStatus $status): AuctionDeposit
    {
        return AuctionDeposit::create([
            'auction_id' => $auction->id,
            'user_id' => $seller->id,
            'type' => 'seller',
            'status' => $status,
            'required_amount_minor' => $auction->seller_deposit_amount_minor,
            'held_amount_minor' => $status === AuctionDepositStatus::Held ? $auction->seller_deposit_amount_minor : 0,
            'currency_code' => 'JOD',
            'held_at' => $status === AuctionDepositStatus::Held ? now()->subHour() : null,
        ]);
    }

    private function paidSellerDeposit(Auction $auction, User $seller): AuctionDeposit
    {
        $deposit = $this->sellerDeposit($auction, $seller, AuctionDepositStatus::Held);
        $this->paymentForDeposit($auction, $deposit, $seller, PaymentPurpose::SellerDeposit);

        return $deposit;
    }

    private function winnerBid(Auction $auction): array
    {
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
            'idempotency_key' => 'seller-deposit-bid-'.Str::ulid(),
            'server_received_at' => now()->subHour(),
            'accepted_at' => now()->subHour(),
        ]);
        $auction->forceFill(['winning_bid_id' => $bid->id])->save();

        return [$winner, $bid];
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
            'amount_paid_minor' => $status === SettlementStatus::Paid ? 100_000 : 0,
            'remaining_amount_minor' => $status === SettlementStatus::Paid ? 0 : 100_000,
            'currency_code' => 'JOD',
            'payment_due_at' => now()->subHour(),
            'paid_at' => $status === SettlementStatus::Paid ? now()->subHour() : null,
            'seller_handover_confirmed_at' => $sellerHandover ? now()->subMinute() : null,
        ]);
    }

    private function paymentForDeposit(Auction $auction, AuctionDeposit $deposit, User $user, PaymentPurpose $purpose): PaymentTransaction
    {
        $submission = PaymentSubmission::create([
            'auction_id' => $auction->id,
            'deposit_id' => $deposit->id,
            'user_id' => $user->id,
            'payment_method_id' => $this->paymentMethod()->id,
            'purpose' => $purpose,
            'status' => PaymentSubmissionStatus::Approved,
            'amount_minor' => $deposit->required_amount_minor,
            'currency_code' => 'JOD',
            'receipt_disk' => 'spaces_private',
            'receipt_path' => 'seller-deposit.pdf',
            'receipt_mime_type' => 'application/pdf',
            'receipt_size_bytes' => 100,
            'idempotency_key' => 'seller-deposit-'.Str::ulid(),
            'submitted_at' => now()->subHour(),
            'reviewed_at' => now()->subHour(),
        ]);

        return PaymentTransaction::create([
            'payment_submission_id' => $submission->id,
            'auction_id' => $auction->id,
            'user_id' => $user->id,
            'purpose' => $purpose,
            'status' => PaymentTransactionStatus::Succeeded,
            'amount_minor' => $deposit->required_amount_minor,
            'currency_code' => 'JOD',
            'provider' => 'manual',
            'provider_transaction_id' => 'provider-'.Str::ulid(),
            'idempotency_key' => 'payment-'.Str::ulid(),
            'successful_obligation_key' => "deposit:{$deposit->id}",
            'processed_at' => now()->subHour(),
        ]);
    }

    private function paymentMethod(): PaymentMethod
    {
        return PaymentMethod::create([
            'name' => 'Manual transfer',
            'code' => 'seller-deposit-'.Str::ulid(),
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
            'email' => "seller-deposit-{$unique}@example.test",
            'phone' => '+96270'.$phoneSuffix,
            'password' => Hash::make('password'),
            'role' => $role,
            'email_verified_at' => now(),
        ]);
    }
}
