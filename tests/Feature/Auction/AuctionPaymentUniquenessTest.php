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
use App\Domain\Auction\Exceptions\AuctionException;
use App\Models\Auction\Auction;
use App\Models\Auction\AuctionActivityLog;
use App\Models\Auction\AuctionBid;
use App\Models\Auction\AuctionDeposit;
use App\Models\Auction\AuctionParticipant;
use App\Models\Auction\AuctionTermsAcceptance;
use App\Models\Auction\AuctionTermsVersion;
use App\Models\Auction\PaymentMethod;
use App\Models\Auction\PaymentSubmission;
use App\Models\Auction\PaymentTransaction;
use App\Models\Category;
use App\Models\Country;
use App\Models\User;
use App\Services\Auction\Actions\ReviewPaymentSubmissionAction;
use App\Services\Auction\Actions\SubmitPaymentSubmissionAction;
use Database\Factories\Auction\PaymentMethodFactory;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AuctionPaymentUniquenessTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate', ['--force' => true]);
    }

    public function test_auction_payment_approval_is_idempotent_for_same_submission(): void
    {
        [$auction, $seller] = $this->auctionWithoutBids(AuctionStatus::AwaitingSellerDeposit);
        $submission = $this->sellerDepositSubmission($auction, $seller);
        $admin = $this->user('admin');

        $review = app(ReviewPaymentSubmissionAction::class);
        $review->approve($submission, $admin->id, 'first approval');
        $review->approve($submission->refresh(), $admin->id, 'second approval');

        $this->assertSame(1, PaymentTransaction::where('payment_submission_id', $submission->id)->count());
        $this->assertSame(1, PaymentTransaction::where('successful_obligation_key', "deposit:{$submission->deposit_id}")->count());
        $this->assertSame(AuctionDepositStatus::Held, $submission->deposit->refresh()->status);
        $this->assertSame(
            1,
            AuctionActivityLog::where('auction_id', $auction->id)
                ->where('event_type', 'auction.payment_approved')
                ->count()
        );
    }

    public function test_auction_payment_pending_submission_blocks_duplicate_but_reject_allows_resubmit(): void
    {
        Storage::fake('spaces_private');

        [$auction, $seller] = $this->auctionWithoutBids(AuctionStatus::AwaitingSellerDeposit);
        $method = PaymentMethodFactory::new()->create();
        $admin = $this->user('admin');
        $submit = app(SubmitPaymentSubmissionAction::class);
        $review = app(ReviewPaymentSubmissionAction::class);

        $first = $submit->execute(
            $auction,
            $seller->id,
            PaymentPurpose::SellerDeposit,
            $method->public_id,
            UploadedFile::fake()->create('receipt-a.pdf', 10, 'application/pdf'),
            'seller-deposit-a'
        );

        try {
            $submit->execute(
                $auction->refresh(),
                $seller->id,
                PaymentPurpose::SellerDeposit,
                $method->public_id,
                UploadedFile::fake()->create('receipt-b.pdf', 10, 'application/pdf'),
                'seller-deposit-b'
            );
            $this->fail('A second pending submission for the same obligation should fail.');
        } catch (AuctionException $exception) {
            $this->assertSame(__('auction.errors.active_payment_submission_exists'), $exception->getMessage());
        }

        $review->reject($first, $admin->id, 'unclear receipt');
        $second = $submit->execute(
            $auction->refresh(),
            $seller->id,
            PaymentPurpose::SellerDeposit,
            $method->public_id,
            UploadedFile::fake()->create('receipt-c.pdf', 10, 'application/pdf'),
            'seller-deposit-c'
        );
        $review->approve($second, $admin->id, 'approved');

        $this->assertSame(2, PaymentSubmission::where('auction_id', $auction->id)->count());
        $this->assertSame(1, PaymentTransaction::where('successful_obligation_key', "deposit:{$second->deposit_id}")->count());
        $this->assertSame(AuctionDepositStatus::Held, $second->deposit->refresh()->status);
    }

    public function test_auction_payment_second_deposit_submission_cannot_be_approved(): void
    {
        [$auction, $seller] = $this->auctionWithoutBids(AuctionStatus::AwaitingSellerDeposit);
        $deposit = $this->sellerDeposit($auction, $seller);
        $first = $this->depositSubmission($auction, $seller, $deposit, 'deposit-first');
        $second = $this->depositSubmission($auction, $seller, $deposit, 'deposit-second');
        $admin = $this->user('admin');
        $review = app(ReviewPaymentSubmissionAction::class);

        $review->approve($first, $admin->id, 'approved');

        $this->expectException(AuctionException::class);
        $this->expectExceptionMessage(__('auction.errors.payment_obligation_already_paid'));

        try {
            $review->approve($second, $admin->id, 'must fail');
        } finally {
            $this->assertSame(1, PaymentTransaction::where('successful_obligation_key', "deposit:{$deposit->id}")->count());
            $this->assertSame(PaymentSubmissionStatus::PendingReview, $second->refresh()->status);
        }
    }

    public function test_auction_payment_second_settlement_submission_cannot_be_approved(): void
    {
        [$auction, $winner, $bid] = $this->auctionWithBid(AuctionStatus::PaymentPending);
        $settlement = $this->settlementForBid($auction, $bid, 90_000);
        $first = $this->settlementSubmission($auction, $settlement, $winner, 'settlement-first');
        $second = $this->settlementSubmission($auction, $settlement, $winner, 'settlement-second');
        $admin = $this->user('admin');
        $review = app(ReviewPaymentSubmissionAction::class);

        $review->approve($first, $admin->id, 'approved');

        $this->expectException(AuctionException::class);
        $this->expectExceptionMessage(__('auction.errors.payment_obligation_already_paid'));

        try {
            $review->approve($second, $admin->id, 'must fail');
        } finally {
            $this->assertSame(1, PaymentTransaction::where('successful_obligation_key', "settlement:{$settlement->id}")->count());
            $this->assertSame(90_000, $settlement->refresh()->amount_paid_minor);
            $this->assertSame(PaymentSubmissionStatus::PendingReview, $second->refresh()->status);
        }
    }

    public function test_auction_payment_database_rejects_duplicate_successful_obligation_key(): void
    {
        [$auction, $seller] = $this->auctionWithoutBids(AuctionStatus::AwaitingSellerDeposit);
        $deposit = $this->sellerDeposit($auction, $seller);
        $first = $this->depositSubmission($auction, $seller, $deposit, 'db-first');
        $second = $this->depositSubmission($auction, $seller, $deposit, 'db-second');

        $this->paymentTransaction($auction, $first, "deposit:{$deposit->id}", 'db-provider-first');

        $this->expectException(QueryException::class);

        $this->paymentTransaction($auction, $second, "deposit:{$deposit->id}", 'db-provider-second');
    }

    public function test_auction_payment_duplicate_provider_transaction_is_rejected_for_different_obligations(): void
    {
        [$firstAuction, $firstSeller] = $this->auctionWithoutBids(AuctionStatus::AwaitingSellerDeposit);
        [$secondAuction, $secondSeller] = $this->auctionWithoutBids(AuctionStatus::AwaitingSellerDeposit);
        $first = $this->sellerDepositSubmission($firstAuction, $firstSeller);
        $second = $this->sellerDepositSubmission($secondAuction, $secondSeller);
        $admin = $this->user('admin');
        $providerTransactionId = 'provider-'.Str::ulid();
        $review = app(ReviewPaymentSubmissionAction::class);

        $review->approve($first, $admin->id, 'approved', $providerTransactionId);

        $this->expectException(AuctionException::class);
        $this->expectExceptionMessage(__('auction.errors.duplicate_provider_transaction'));

        $review->approve($second, $admin->id, 'must fail', $providerTransactionId);
    }

    private function auctionWithoutBids(AuctionStatus $status): array
    {
        $seller = $this->user();
        $terms = AuctionTermsVersion::create([
            'version_number' => ((int) AuctionTermsVersion::max('version_number')) + 1,
            'title' => 'Terms',
            'body' => 'Auction terms.',
            'is_active' => true,
            'published_at' => Carbon::now()->subDay(),
        ]);
        $category = Category::create(['name' => 'cat-'.Str::ulid(), 'display_order' => 0]);
        $country = Country::create(['name' => 'country-'.Str::ulid(), 'code' => strtoupper(substr((string) Str::ulid(), 0, 6))]);

        $auction = Auction::create([
            'seller_id' => $seller->id,
            'category_id' => $category->id,
            'country_id' => $country->id,
            'terms_version_id' => $terms->id,
            'currency_code' => 'JOD',
            'title' => 'Payment uniqueness auction',
            'description' => 'Payment uniqueness auction.',
            'status' => $status,
            'starting_amount_minor' => 10_000,
            'reserve_amount_minor' => null,
            'minimum_bid_increment_minor' => 500,
            'seller_deposit_amount_minor' => 2_000,
            'bidder_deposit_amount_minor' => 1_000,
            'platform_fee_type' => 'percentage',
            'platform_fee_basis_points' => 250,
            'platform_fee_fixed_minor' => 0,
            'winner_payment_deadline_hours' => 48,
            'handover_deadline_hours' => 72,
            'starts_at' => Carbon::now()->subDays(2),
            'original_ends_at' => Carbon::now()->subMinute(),
            'ends_at' => Carbon::now()->subMinute(),
        ]);

        return [$auction, $seller];
    }

    private function auctionWithBid(AuctionStatus $status): array
    {
        [$auction] = $this->auctionWithoutBids($status);
        $winner = $this->user();
        $participant = AuctionParticipant::create([
            'auction_id' => $auction->id,
            'user_id' => $winner->id,
            'status' => AuctionParticipantStatus::Qualified,
            'registered_at' => Carbon::now()->subDays(2),
            'qualified_at' => Carbon::now()->subDay(),
        ]);

        AuctionDeposit::create([
            'auction_id' => $auction->id,
            'participant_id' => $participant->id,
            'user_id' => $winner->id,
            'type' => 'bidder',
            'status' => AuctionDepositStatus::Held,
            'required_amount_minor' => 10_000,
            'held_amount_minor' => 10_000,
            'currency_code' => 'JOD',
            'held_at' => Carbon::now()->subDay(),
        ]);

        AuctionTermsAcceptance::create([
            'auction_id' => $auction->id,
            'participant_id' => $participant->id,
            'user_id' => $winner->id,
            'terms_version_id' => $auction->terms_version_id,
            'accepted_at' => Carbon::now()->subDay(),
        ]);

        $bid = AuctionBid::create([
            'auction_id' => $auction->id,
            'participant_id' => $participant->id,
            'bidder_id' => $winner->id,
            'amount_minor' => 100_000,
            'currency_code' => 'JOD',
            'sequence_number' => 1,
            'idempotency_key' => 'bid-'.Str::ulid(),
            'server_received_at' => Carbon::now(),
            'accepted_at' => Carbon::now(),
        ]);

        return [$auction, $winner, $bid];
    }

    private function sellerDeposit(Auction $auction, User $seller): AuctionDeposit
    {
        return AuctionDeposit::create([
            'auction_id' => $auction->id,
            'user_id' => $seller->id,
            'type' => 'seller',
            'status' => AuctionDepositStatus::PendingReview,
            'required_amount_minor' => $auction->seller_deposit_amount_minor,
            'held_amount_minor' => 0,
            'currency_code' => $auction->currency_code,
        ]);
    }

    private function sellerDepositSubmission(Auction $auction, User $seller): PaymentSubmission
    {
        return $this->depositSubmission($auction, $seller, $this->sellerDeposit($auction, $seller), 'seller-deposit-'.Str::ulid());
    }

    private function depositSubmission(Auction $auction, User $seller, AuctionDeposit $deposit, string $idempotencyKey): PaymentSubmission
    {
        return PaymentSubmission::create([
            'auction_id' => $auction->id,
            'deposit_id' => $deposit->id,
            'user_id' => $seller->id,
            'payment_method_id' => $this->paymentMethod()->id,
            'purpose' => PaymentPurpose::SellerDeposit,
            'status' => PaymentSubmissionStatus::PendingReview,
            'amount_minor' => $auction->seller_deposit_amount_minor,
            'currency_code' => $auction->currency_code,
            'receipt_disk' => 'spaces_private',
            'receipt_path' => $idempotencyKey.'.pdf',
            'receipt_mime_type' => 'application/pdf',
            'receipt_size_bytes' => 100,
            'idempotency_key' => $idempotencyKey,
            'submitted_at' => Carbon::now(),
        ]);
    }

    private function settlementForBid(Auction $auction, AuctionBid $bid, int $amountDue): \App\Models\Auction\AuctionSettlement
    {
        return \App\Models\Auction\AuctionSettlement::create([
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
            'amount_due_minor' => $amountDue,
            'amount_paid_minor' => 0,
            'remaining_amount_minor' => $amountDue,
            'currency_code' => 'JOD',
            'payment_due_at' => Carbon::now()->addDay(),
        ]);
    }

    private function settlementSubmission(Auction $auction, \App\Models\Auction\AuctionSettlement $settlement, User $winner, string $idempotencyKey): PaymentSubmission
    {
        return PaymentSubmission::create([
            'auction_id' => $auction->id,
            'settlement_id' => $settlement->id,
            'user_id' => $winner->id,
            'payment_method_id' => $this->paymentMethod()->id,
            'purpose' => PaymentPurpose::WinnerSettlement,
            'status' => PaymentSubmissionStatus::PendingReview,
            'amount_minor' => $settlement->remaining_amount_minor,
            'currency_code' => $auction->currency_code,
            'receipt_disk' => 'spaces_private',
            'receipt_path' => $idempotencyKey.'.pdf',
            'receipt_mime_type' => 'application/pdf',
            'receipt_size_bytes' => 100,
            'idempotency_key' => $idempotencyKey,
            'submitted_at' => Carbon::now(),
        ]);
    }

    private function paymentTransaction(Auction $auction, PaymentSubmission $submission, string $obligationKey, string $providerTransactionId): PaymentTransaction
    {
        return PaymentTransaction::create([
            'payment_submission_id' => $submission->id,
            'auction_id' => $auction->id,
            'user_id' => $submission->user_id,
            'purpose' => $submission->purpose,
            'status' => PaymentTransactionStatus::Succeeded,
            'amount_minor' => $submission->amount_minor,
            'currency_code' => $auction->currency_code,
            'provider' => 'manual',
            'provider_transaction_id' => $providerTransactionId,
            'idempotency_key' => 'txn-'.Str::ulid(),
            'successful_obligation_key' => $obligationKey,
            'processed_at' => Carbon::now(),
        ]);
    }

    private function paymentMethod(): PaymentMethod
    {
        return PaymentMethod::create([
            'name' => 'Manual bank transfer',
            'code' => 'manual-'.Str::ulid(),
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
            'email' => "auction-payment-{$unique}@example.test",
            'phone' => '+96277'.$phoneSuffix,
            'password' => Hash::make('password'),
            'role' => $role,
            'email_verified_at' => Carbon::now(),
        ]);
    }
}
