<?php

declare(strict_types=1);

namespace Tests\Feature\Auction;

use App\Domain\Auction\Enums\AuctionDepositStatus;
use App\Domain\Auction\Enums\AuctionParticipantStatus;
use App\Domain\Auction\Enums\AuctionStatus;
use App\Domain\Auction\Enums\PaymentPurpose;
use App\Domain\Auction\Enums\PaymentSubmissionStatus;
use App\Domain\Auction\Enums\PaymentTransactionStatus;
use App\Domain\Auction\Enums\RefundTransactionStatus;
use App\Domain\Auction\Enums\SettlementStatus;
use App\Models\Auction\Auction;
use App\Models\Auction\AuctionBid;
use App\Models\Auction\AuctionDeposit;
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
use App\Services\Auction\Actions\CancelAuctionAction;
use App\Services\Auction\Actions\RefundAuctionDepositAction;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CancellationRefundTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate', ['--force' => true]);
    }

    public function test_cancellation_plans_one_refund_for_paid_deposit_from_original_payment_transaction(): void
    {
        [$auction, $seller] = $this->auction(AuctionStatus::Scheduled);
        $deposit = $this->deposit($auction, $seller, 10_000);
        $payment = $this->paymentForDeposit($auction, $deposit, $seller, 10_000);

        $this->cancel($auction);

        $refund = RefundTransaction::where('auction_id', $auction->id)->sole();
        $this->assertSame($payment->id, $refund->payment_transaction_id);
        $this->assertSame($deposit->id, $refund->deposit_id);
        $this->assertSame('deposit', $refund->obligation_type);
        $this->assertSame($deposit->id, $refund->obligation_id);
        $this->assertSame(10_000, $refund->amount_minor);
        $this->assertSame(RefundTransactionStatus::Pending, $refund->status);
        $this->assertSame(PaymentTransactionStatus::Succeeded, $payment->refresh()->status);

        $this->cancel($auction->refresh());

        $this->assertSame(1, RefundTransaction::where('auction_id', $auction->id)->count());
        $this->assertSame(PaymentTransactionStatus::Succeeded, $payment->refresh()->status);
    }

    public function test_cancellation_plans_one_refund_for_paid_settlement_from_original_payment_transaction(): void
    {
        [$auction, $winner, $bid] = $this->auctionWithWinner(AuctionStatus::HandoverPending);
        $settlement = $this->settlement($auction, $bid, 90_000, paid: true);
        $payment = $this->paymentForSettlement($auction, $settlement, $winner, 90_000);

        $this->cancel($auction);

        $refund = RefundTransaction::where('auction_id', $auction->id)->sole();
        $this->assertSame($payment->id, $refund->payment_transaction_id);
        $this->assertNull($refund->deposit_id);
        $this->assertSame('settlement', $refund->obligation_type);
        $this->assertSame($settlement->id, $refund->obligation_id);
        $this->assertSame(90_000, $refund->amount_minor);
        $this->assertSame(PaymentTransactionStatus::Succeeded, $payment->refresh()->status);
    }

    public function test_cancellation_refunds_deposit_and_settlement_once_without_overlap(): void
    {
        [$auction, $winner, $bid] = $this->auctionWithWinner(AuctionStatus::HandoverPending);
        $deposit = $this->deposit($auction, $winner, 10_000);
        $depositPayment = $this->paymentForDeposit($auction, $deposit, $winner, 10_000);
        $settlement = $this->settlement($auction, $bid, 90_000, paid: true);
        $settlementPayment = $this->paymentForSettlement($auction, $settlement, $winner, 90_000);

        $this->cancel($auction);

        $refunds = RefundTransaction::where('auction_id', $auction->id)->orderBy('amount_minor')->get();

        $this->assertCount(2, $refunds);
        $this->assertSame(100_000, $refunds->sum('amount_minor'));
        $this->assertEqualsCanonicalizing(
            [$depositPayment->id, $settlementPayment->id],
            $refunds->pluck('payment_transaction_id')->all()
        );
        $this->assertSame(1, RefundTransaction::where('payment_transaction_id', $depositPayment->id)->count());
        $this->assertSame(1, RefundTransaction::where('payment_transaction_id', $settlementPayment->id)->count());
        $this->assertSame(PaymentTransactionStatus::Succeeded, $depositPayment->refresh()->status);
        $this->assertSame(PaymentTransactionStatus::Succeeded, $settlementPayment->refresh()->status);
    }

    public function test_cancellation_does_not_plan_real_refund_for_deposit_without_successful_payment(): void
    {
        [$auction, $seller] = $this->auction(AuctionStatus::Scheduled);
        $this->deposit($auction, $seller, 10_000);

        $this->cancel($auction);

        $this->assertSame(0, RefundTransaction::where('auction_id', $auction->id)->count());
    }

    public function test_a_succeeded_payment_and_its_refund_stay_two_separate_financial_events(): void
    {
        [$auction, $seller] = $this->auction(AuctionStatus::Scheduled);
        $deposit = $this->deposit($auction, $seller, 10_000);
        $payment = $this->paymentForDeposit($auction, $deposit, $seller, 10_000);

        $this->cancel($auction);
        $refund = RefundTransaction::where('auction_id', $auction->id)->sole();

        $this->assertSame(PaymentTransactionStatus::Succeeded, $payment->refresh()->status);

        app(RefundAuctionDepositAction::class)->confirmSucceeded($refund, 'provider-refund-'.Str::ulid());
        app(RefundAuctionDepositAction::class)->confirmSucceeded($refund->refresh(), 'provider-refund-'.Str::ulid());

        $this->assertSame(RefundTransactionStatus::Succeeded, $refund->refresh()->status);
        $this->assertSame(PaymentTransactionStatus::Succeeded, $payment->refresh()->status);
        $this->assertNotNull($payment->refresh()->successful_obligation_key);
        $this->assertSame(1, RefundTransaction::where('payment_transaction_id', $payment->id)->count());
    }

    public function test_cancellation_settlement_refund_can_actually_be_completed(): void
    {
        [$auction, $winner, $bid] = $this->auctionWithWinner(AuctionStatus::HandoverPending);
        $settlement = $this->settlement($auction, $bid, 90_000, paid: true);
        $payment = $this->paymentForSettlement($auction, $settlement, $winner, 90_000);

        $this->cancel($auction);

        $refund = RefundTransaction::where('auction_id', $auction->id)->sole();
        $this->assertNull($refund->deposit_id);
        $this->assertSame(0, (int) $refund->held_refund_amount_minor);
        $this->assertSame(0, (int) $refund->applied_refund_amount_minor);

        $completed = app(RefundAuctionDepositAction::class)
            ->confirmSucceeded($refund, 'provider-refund-'.Str::ulid());

        $this->assertSame(RefundTransactionStatus::Succeeded, $completed->status);
        $this->assertSame(90_000, (int) $completed->amount_minor);
        $this->assertSame(PaymentTransactionStatus::Succeeded, $payment->refresh()->status);
    }

    public function test_cancellation_deposit_and_settlement_refunds_both_complete_and_keep_deposit_math(): void
    {
        [$auction, $winner, $bid] = $this->auctionWithWinner(AuctionStatus::HandoverPending);
        $deposit = $this->deposit($auction, $winner, 10_000);
        $depositPayment = $this->paymentForDeposit($auction, $deposit, $winner, 10_000);
        $settlement = $this->settlement($auction, $bid, 90_000, paid: true);
        $settlementPayment = $this->paymentForSettlement($auction, $settlement, $winner, 90_000);

        $this->cancel($auction);

        foreach (RefundTransaction::where('auction_id', $auction->id)->get() as $refund) {
            app(RefundAuctionDepositAction::class)
                ->confirmSucceeded($refund, 'provider-refund-'.Str::ulid());
        }

        $this->assertSame(
            2,
            RefundTransaction::where('auction_id', $auction->id)
                ->where('status', RefundTransactionStatus::Succeeded->value)
                ->count()
        );
        $this->assertSame(PaymentTransactionStatus::Succeeded, $depositPayment->refresh()->status);
        $this->assertSame(PaymentTransactionStatus::Succeeded, $settlementPayment->refresh()->status);

        $deposit->refresh();
        $this->assertSame(10_000, (int) $deposit->refunded_amount_minor);
        $this->assertSame(0, (int) $deposit->held_amount_minor);
    }

    private function cancel(Auction $auction): Auction
    {
        return app(CancelAuctionAction::class)->execute($auction, $this->user('admin')->id, 'admin', 'test cancellation');
    }

    private function auction(AuctionStatus $status): array
    {
        $seller = $this->user();
        $terms = AuctionTermsVersion::create([
            'version_number' => ((int) AuctionTermsVersion::max('version_number')) + 1,
            'title' => 'Terms',
            'body' => 'Auction terms.',
            'is_active' => true,
            'published_at' => now()->subDay(),
        ]);
        $category = Category::create(['name' => 'cancel-refund-cat-'.Str::ulid(), 'display_order' => 0]);
        $country = Country::create(['name' => 'cancel-refund-country-'.Str::ulid(), 'code' => strtoupper(substr((string) Str::ulid(), 0, 6))]);
        $configuration = $this->auctionConfigurationVersion([
            'seller_deposit_minor' => 10_000,
            'bidder_deposit_minor' => 10_000,
        ]);

        $auction = Auction::create([
            'seller_id' => $seller->id,
            'category_id' => $category->id,
            'country_id' => $country->id,
            'terms_version_id' => $terms->id,
            'configuration_version_id' => $configuration->id,
            'currency_code' => 'JOD',
            'title' => 'Cancellation refund auction',
            'description' => 'Cancellation refund auction.',
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
            'starts_at' => now()->subDays(2),
            'original_ends_at' => now()->subHour(),
            'ends_at' => now()->subHour(),
        ]);

        $this->snapshotApprovedAuction($auction, $seller->id);

        return [$auction, $seller];
    }

    private function auctionWithWinner(AuctionStatus $status): array
    {
        [$auction] = $this->auction($status);
        $winner = $this->user();
        $participant = AuctionParticipant::create([
            'auction_id' => $auction->id,
            'user_id' => $winner->id,
            'status' => AuctionParticipantStatus::Qualified,
            'registered_at' => now()->subDays(2),
            'qualified_at' => now()->subDay(),
        ]);
        $bid = AuctionBid::create([
            'auction_id' => $auction->id,
            'participant_id' => $participant->id,
            'bidder_id' => $winner->id,
            'amount_minor' => 100_000,
            'currency_code' => 'JOD',
            'sequence_number' => 1,
            'idempotency_key' => 'cancel-refund-bid-'.Str::ulid(),
            'server_received_at' => now()->subHour(),
            'accepted_at' => now()->subHour(),
        ]);
        $auction->forceFill(['winning_bid_id' => $bid->id])->save();

        return [$auction->refresh(), $winner, $bid];
    }

    private function deposit(Auction $auction, User $user, int $amount): AuctionDeposit
    {
        return AuctionDeposit::create([
            'auction_id' => $auction->id,
            'user_id' => $user->id,
            'type' => 'bidder',
            'status' => AuctionDepositStatus::Held,
            'required_amount_minor' => $amount,
            'held_amount_minor' => $amount,
            'currency_code' => 'JOD',
            'held_at' => now()->subHour(),
        ]);
    }

    private function settlement(Auction $auction, AuctionBid $bid, int $amountDue, bool $paid = false): AuctionSettlement
    {
        return AuctionSettlement::create([
            'auction_id' => $auction->id,
            'winning_bid_id' => $bid->id,
            'winner_id' => $bid->bidder_id,
            'sequence_number' => 1,
            'is_current' => true,
            'current_marker' => 1,
            'status' => $paid ? SettlementStatus::Paid : SettlementStatus::PaymentPending,
            'winning_amount_minor' => 100_000,
            'deposit_applied_minor' => 10_000,
            'platform_fee_minor' => 2_500,
            'seller_net_amount_minor' => 97_500,
            'amount_due_minor' => $amountDue,
            'amount_paid_minor' => $paid ? $amountDue : 0,
            'remaining_amount_minor' => $paid ? 0 : $amountDue,
            'currency_code' => 'JOD',
            'payment_due_at' => now()->addDay(),
            'paid_at' => $paid ? now()->subMinute() : null,
        ]);
    }

    private function paymentForDeposit(Auction $auction, AuctionDeposit $deposit, User $user, int $amount): PaymentTransaction
    {
        $submission = PaymentSubmission::create([
            'auction_id' => $auction->id,
            'deposit_id' => $deposit->id,
            'user_id' => $user->id,
            'payment_method_id' => $this->paymentMethod()->id,
            'purpose' => PaymentPurpose::BidderDeposit,
            'status' => PaymentSubmissionStatus::Approved,
            'amount_minor' => $amount,
            'currency_code' => 'JOD',
            'receipt_disk' => 'spaces_private',
            'receipt_path' => 'deposit.pdf',
            'receipt_mime_type' => 'application/pdf',
            'receipt_size_bytes' => 100,
            'idempotency_key' => 'deposit-'.Str::ulid(),
            'submitted_at' => now()->subHour(),
            'reviewed_at' => now()->subHour(),
        ]);

        return $this->payment($auction, $submission, $user, PaymentPurpose::BidderDeposit, $amount);
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
            'receipt_path' => 'settlement.pdf',
            'receipt_mime_type' => 'application/pdf',
            'receipt_size_bytes' => 100,
            'idempotency_key' => 'settlement-'.Str::ulid(),
            'submitted_at' => now()->subHour(),
            'reviewed_at' => now()->subHour(),
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
            'provider_transaction_id' => 'provider-'.Str::ulid(),
            'idempotency_key' => 'payment-'.Str::ulid(),
            'successful_obligation_key' => $submission->deposit_id ? "deposit:{$submission->deposit_id}" : "settlement:{$submission->settlement_id}",
            'processed_at' => now()->subHour(),
        ]);
    }

    private function paymentMethod(): PaymentMethod
    {
        return PaymentMethod::create([
            'name' => 'Manual transfer',
            'code' => 'cancel-refund-'.Str::ulid(),
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
            'email' => "cancel-refund-{$unique}@example.test",
            'phone' => '+96272'.$phoneSuffix,
            'password' => Hash::make('password'),
            'role' => $role,
            'email_verified_at' => now(),
        ]);
    }
}
