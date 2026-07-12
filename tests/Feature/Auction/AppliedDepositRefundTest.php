<?php

declare(strict_types=1);

namespace Tests\Feature\Auction;

use App\Domain\Auction\Enums\AuctionDepositStatus;
use App\Domain\Auction\Enums\AuctionStatus;
use App\Domain\Auction\Enums\PaymentPurpose;
use App\Domain\Auction\Enums\PaymentSubmissionStatus;
use App\Domain\Auction\Enums\PaymentTransactionStatus;
use App\Domain\Auction\Enums\RefundTransactionStatus;
use App\Domain\Auction\Enums\SettlementStatus;
use App\Domain\Auction\Exceptions\AuctionException;
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
use App\Services\Auction\Actions\RefundAuctionDepositAction;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AppliedDepositRefundTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate', ['--force' => true]);
    }

    public function test_refunds_held_only_deposit(): void
    {
        [$auction, $user] = $this->auction();
        $deposit = $this->deposit($auction, $user, held: 10_000);
        $this->paymentForDeposit($auction, $deposit, $user, 10_000);

        $refund = app(RefundAuctionDepositAction::class)->execute($deposit, 'held refund');
        app(RefundAuctionDepositAction::class)->confirmSucceeded($refund, 'held-refund-'.Str::ulid());

        $deposit->refresh();
        $this->assertSame(10_000, $refund->refresh()->held_refund_amount_minor);
        $this->assertSame(0, $refund->applied_refund_amount_minor);
        $this->assertSame(0, $deposit->held_amount_minor);
        $this->assertSame(0, $deposit->applied_amount_minor);
        $this->assertSame(10_000, $deposit->refunded_amount_minor);
        $this->assertSame(AuctionDepositStatus::Refunded, $deposit->status);
    }

    public function test_refunds_applied_deposit_after_settlement_is_cancelled(): void
    {
        [$auction, $user, $bid] = $this->auctionWithBid();
        $deposit = $this->deposit($auction, $user, held: 0, applied: 10_000);
        $this->settlement($auction, $bid, SettlementStatus::Cancelled);
        $this->paymentForDeposit($auction, $deposit, $user, 10_000);

        $refund = app(RefundAuctionDepositAction::class)->execute($deposit, 'applied refund');
        app(RefundAuctionDepositAction::class)->confirmSucceeded($refund, 'applied-refund-'.Str::ulid());

        $deposit->refresh();
        $this->assertSame(0, $refund->refresh()->held_refund_amount_minor);
        $this->assertSame(10_000, $refund->applied_refund_amount_minor);
        $this->assertSame(0, $deposit->applied_amount_minor);
        $this->assertSame(10_000, $deposit->refunded_amount_minor);
    }

    public function test_rejects_applied_deposit_refund_while_settlement_is_active(): void
    {
        [$auction, $user, $bid] = $this->auctionWithBid();
        $deposit = $this->deposit($auction, $user, held: 0, applied: 10_000);
        $this->settlement($auction, $bid, SettlementStatus::Paid);
        $this->paymentForDeposit($auction, $deposit, $user, 10_000);

        $this->expectException(AuctionException::class);
        $this->expectExceptionMessage(__('auction.errors.refund_exceeds_available'));

        app(RefundAuctionDepositAction::class)->execute($deposit, 'active settlement refund');
    }

    public function test_refunds_mixed_held_and_applied_buckets_after_settlement_is_cancelled(): void
    {
        [$auction, $user, $bid] = $this->auctionWithBid();
        $deposit = $this->deposit($auction, $user, held: 4_000, applied: 6_000);
        $this->settlement($auction, $bid, SettlementStatus::Cancelled, applied: 6_000);
        $this->paymentForDeposit($auction, $deposit, $user, 10_000);

        $refund = app(RefundAuctionDepositAction::class)->execute($deposit, 'mixed refund');
        app(RefundAuctionDepositAction::class)->confirmSucceeded($refund, 'mixed-refund-'.Str::ulid());

        $deposit->refresh();
        $this->assertSame(4_000, $refund->refresh()->held_refund_amount_minor);
        $this->assertSame(6_000, $refund->applied_refund_amount_minor);
        $this->assertSame(0, $deposit->held_amount_minor);
        $this->assertSame(0, $deposit->applied_amount_minor);
        $this->assertSame(10_000, $deposit->refunded_amount_minor);
    }

    public function test_refunds_held_then_applied_from_same_payment_without_reversing_until_full_refund(): void
    {
        [$auction, $user, $bid] = $this->auctionWithBid();
        $deposit = $this->deposit($auction, $user, held: 4_000, applied: 6_000);
        $settlement = $this->settlement($auction, $bid, SettlementStatus::Paid, applied: 6_000);
        $payment = $this->paymentForDeposit($auction, $deposit, $user, 10_000);

        $heldRefund = app(RefundAuctionDepositAction::class)->execute($deposit, 'held first refund');
        app(RefundAuctionDepositAction::class)->confirmSucceeded($heldRefund, 'held-first-'.Str::ulid());

        $deposit->refresh();
        $this->assertSame(4_000, $deposit->refunded_amount_minor);
        $this->assertSame(0, $deposit->held_amount_minor);
        $this->assertSame(6_000, $deposit->applied_amount_minor);
        $this->assertSame(PaymentTransactionStatus::Succeeded, $payment->refresh()->status);

        $settlement->forceFill(['status' => SettlementStatus::Cancelled])->save();

        $appliedRefund = app(RefundAuctionDepositAction::class)->execute($deposit->refresh(), 'applied second refund');
        app(RefundAuctionDepositAction::class)->confirmSucceeded($appliedRefund, 'applied-second-'.Str::ulid());

        $deposit->refresh();
        $this->assertSame(2, RefundTransaction::where('payment_transaction_id', $payment->id)->count());
        $this->assertSame(10_000, $deposit->refunded_amount_minor);
        $this->assertSame(0, $deposit->applied_amount_minor);
        $this->assertSame(PaymentTransactionStatus::Reversed, $payment->refresh()->status);
    }

    public function test_rejects_forfeited_deposit_refund(): void
    {
        [$auction, $user] = $this->auction();
        $deposit = $this->deposit($auction, $user, held: 0, forfeited: 10_000);
        $this->paymentForDeposit($auction, $deposit, $user, 10_000);

        $this->expectException(AuctionException::class);
        $this->expectExceptionMessage(__('auction.errors.zero_refund_not_allowed'));

        app(RefundAuctionDepositAction::class)->execute($deposit, 'forfeited refund');
    }

    public function test_rejects_over_refund_at_confirmation(): void
    {
        [$auction, $user] = $this->auction();
        $deposit = $this->deposit($auction, $user, held: 10_000);
        $payment = $this->paymentForDeposit($auction, $deposit, $user, 10_000);
        $refund = $this->refund($auction, $deposit, $user, amount: 11_000, held: 11_000, payment: $payment);

        $this->expectException(AuctionException::class);
        $this->expectExceptionMessage(__('auction.errors.refund_exceeds_available'));

        app(RefundAuctionDepositAction::class)->confirmSucceeded($refund, 'over-refund-'.Str::ulid());
    }

    public function test_pending_refund_reservation_blocks_duplicate_refund(): void
    {
        [$auction, $user] = $this->auction();
        $deposit = $this->deposit($auction, $user, held: 10_000);
        $this->paymentForDeposit($auction, $deposit, $user, 10_000);
        $this->refund($auction, $deposit, $user, amount: 10_000, held: 10_000);

        $this->expectException(AuctionException::class);
        $this->expectExceptionMessage(__('auction.errors.zero_refund_not_allowed'));

        app(RefundAuctionDepositAction::class)->execute($deposit, 'duplicate pending refund');
    }

    public function test_repeated_confirmation_is_idempotent(): void
    {
        [$auction, $user, $bid] = $this->auctionWithBid();
        $deposit = $this->deposit($auction, $user, held: 0, applied: 10_000);
        $this->settlement($auction, $bid, SettlementStatus::Cancelled);
        $this->paymentForDeposit($auction, $deposit, $user, 10_000);
        $refund = app(RefundAuctionDepositAction::class)->execute($deposit, 'repeat applied refund');

        $providerRefundId = 'repeat-refund-'.Str::ulid();
        app(RefundAuctionDepositAction::class)->confirmSucceeded($refund, $providerRefundId);
        app(RefundAuctionDepositAction::class)->confirmSucceeded($refund->refresh(), $providerRefundId);

        $deposit->refresh();
        $this->assertSame(10_000, $deposit->refunded_amount_minor);
        $this->assertSame(0, $deposit->applied_amount_minor);
        $this->assertSame($providerRefundId, $refund->refresh()->provider_refund_id);
    }

    private function auction(): array
    {
        $seller = $this->user();
        $terms = AuctionTermsVersion::create([
            'version_number' => ((int) AuctionTermsVersion::max('version_number')) + 1,
            'title' => 'Terms',
            'body' => 'Auction terms.',
            'is_active' => true,
            'published_at' => now()->subDay(),
        ]);
        $category = Category::create(['name' => 'applied-refund-cat-'.Str::ulid(), 'display_order' => 0]);
        $country = Country::create(['name' => 'applied-refund-country-'.Str::ulid(), 'code' => strtoupper(substr((string) Str::ulid(), 0, 6))]);

        return [Auction::create([
            'seller_id' => $seller->id,
            'category_id' => $category->id,
            'country_id' => $country->id,
            'terms_version_id' => $terms->id,
            'currency_code' => 'JOD',
            'title' => 'Applied deposit refund auction',
            'description' => 'Applied deposit refund auction.',
            'status' => AuctionStatus::PaymentPending,
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
        ]), $seller];
    }

    private function auctionWithBid(): array
    {
        [$auction] = $this->auction();
        $winner = $this->user();
        $participant = AuctionParticipant::create([
            'auction_id' => $auction->id,
            'user_id' => $winner->id,
            'status' => 'qualified',
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
            'idempotency_key' => 'applied-refund-bid-'.Str::ulid(),
            'server_received_at' => now()->subHour(),
            'accepted_at' => now()->subHour(),
        ]);
        $auction->forceFill(['winning_bid_id' => $bid->id])->save();

        return [$auction->refresh(), $winner, $bid];
    }

    private function deposit(Auction $auction, User $user, int $held, int $applied = 0, int $forfeited = 0): AuctionDeposit
    {
        return AuctionDeposit::create([
            'auction_id' => $auction->id,
            'user_id' => $user->id,
            'type' => 'bidder',
            'status' => $forfeited > 0 ? AuctionDepositStatus::Forfeited : ($applied > 0 ? AuctionDepositStatus::AppliedToSettlement : AuctionDepositStatus::Held),
            'required_amount_minor' => $held + $applied + $forfeited,
            'held_amount_minor' => $held,
            'applied_amount_minor' => $applied,
            'forfeited_amount_minor' => $forfeited,
            'currency_code' => 'JOD',
            'held_at' => now()->subHour(),
        ]);
    }

    private function settlement(Auction $auction, AuctionBid $bid, SettlementStatus $status, int $applied = 10_000): AuctionSettlement
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
            'deposit_applied_minor' => $applied,
            'platform_fee_minor' => 2_500,
            'seller_net_amount_minor' => 97_500,
            'amount_due_minor' => 90_000,
            'amount_paid_minor' => $status === SettlementStatus::Paid ? 90_000 : 0,
            'remaining_amount_minor' => $status === SettlementStatus::Paid ? 0 : 90_000,
            'currency_code' => 'JOD',
            'payment_due_at' => now()->addDay(),
            'paid_at' => $status === SettlementStatus::Paid ? now()->subMinute() : null,
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

        return PaymentTransaction::create([
            'payment_submission_id' => $submission->id,
            'auction_id' => $auction->id,
            'user_id' => $user->id,
            'purpose' => PaymentPurpose::BidderDeposit,
            'status' => PaymentTransactionStatus::Succeeded,
            'amount_minor' => $amount,
            'currency_code' => 'JOD',
            'provider' => 'manual',
            'provider_transaction_id' => 'provider-'.Str::ulid(),
            'idempotency_key' => 'payment-'.Str::ulid(),
            'successful_obligation_key' => "deposit:{$deposit->id}",
            'processed_at' => now()->subHour(),
        ]);
    }

    private function refund(Auction $auction, AuctionDeposit $deposit, User $user, int $amount, int $held, int $applied = 0, ?PaymentTransaction $payment = null): RefundTransaction
    {
        return RefundTransaction::create([
            'auction_id' => $auction->id,
            'deposit_id' => $deposit->id,
            'payment_transaction_id' => $payment?->id,
            'obligation_type' => 'deposit',
            'obligation_id' => $deposit->id,
            'user_id' => $user->id,
            'status' => RefundTransactionStatus::Pending,
            'amount_minor' => $amount,
            'held_refund_amount_minor' => $held,
            'applied_refund_amount_minor' => $applied,
            'currency_code' => 'JOD',
            'provider' => 'manual',
            'idempotency_key' => 'refund-'.Str::ulid(),
            'reason' => 'test refund',
        ]);
    }

    private function paymentMethod(): PaymentMethod
    {
        return PaymentMethod::create([
            'name' => 'Manual transfer',
            'code' => 'applied-refund-'.Str::ulid(),
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
            'email' => "applied-refund-{$unique}@example.test",
            'phone' => '+96273'.$phoneSuffix,
            'password' => Hash::make('password'),
            'role' => $role,
            'email_verified_at' => now(),
        ]);
    }
}
