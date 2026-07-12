<?php

declare(strict_types=1);

namespace Tests\Feature\Auction;

use App\Domain\Auction\Enums\AuctionDepositStatus;
use App\Domain\Auction\Enums\AuctionParticipantStatus;
use App\Domain\Auction\Enums\AuctionStatus;
use App\Domain\Auction\Enums\PaymentPurpose;
use App\Domain\Auction\Enums\PaymentSubmissionStatus;
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
use App\Models\Category;
use App\Models\Country;
use App\Models\User;
use App\Services\Auction\Actions\ReviewPaymentSubmissionAction;
use App\Services\Auction\Actions\SubmitPaymentSubmissionAction;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PaymentStateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate', ['--force' => true]);
        Storage::fake('spaces_private');
    }

    public function test_seller_deposit_submission_and_approval_require_correct_state_owner_amount_and_currency(): void
    {
        [$auction, $seller] = $this->auction(AuctionStatus::AwaitingSellerDeposit);
        $submission = $this->submit($auction, $seller, PaymentPurpose::SellerDeposit);

        $this->assertSame(PaymentSubmissionStatus::PendingReview, $submission->status);

        $this->expectAuctionException(__('auction.errors.seller_deposit_state_not_allowed'), function (): void {
            [$draft, $seller] = $this->auction(AuctionStatus::Draft);
            $this->submit($draft, $seller, PaymentPurpose::SellerDeposit);
        });

        $this->expectAuctionException(__('auction.errors.seller_deposit_state_not_allowed'), function (): void {
            [$scheduled, $seller] = $this->auction(AuctionStatus::Scheduled);
            $this->submit($scheduled, $seller, PaymentPurpose::SellerDeposit);
        });

        $this->expectAuctionException(__('auction.errors.payment_target_owner_mismatch'), function (): void {
            [$ownedAuction] = $this->auction(AuctionStatus::AwaitingSellerDeposit);
            $this->submit($ownedAuction, $this->user(), PaymentPurpose::SellerDeposit);
        });

        $this->expectAuctionException(__('auction.errors.zero_deposit_not_required'), function (): void {
            [$zero, $seller] = $this->auction(AuctionStatus::AwaitingSellerDeposit, sellerDeposit: 0);
            $this->submit($zero, $seller, PaymentPurpose::SellerDeposit);
        });

        [$wrongAmountAuction, $wrongAmountSeller] = $this->auction(AuctionStatus::AwaitingSellerDeposit);
        $wrongAmount = $this->sellerDepositSubmission($wrongAmountAuction, $wrongAmountSeller, amount: $wrongAmountAuction->seller_deposit_amount_minor - 1);
        $this->expectAuctionException(__('auction.errors.payment_amount_mismatch'), fn () => $this->approve($wrongAmount));

        [$wrongCurrencyAuction, $wrongCurrencySeller] = $this->auction(AuctionStatus::AwaitingSellerDeposit);
        $wrongCurrency = $this->sellerDepositSubmission($wrongCurrencyAuction, $wrongCurrencySeller, currency: 'USD');
        $this->expectAuctionException(__('auction.errors.payment_currency_mismatch'), fn () => $this->approve($wrongCurrency));
    }

    public function test_bidder_deposit_requires_registration_eligible_participant_live_window_and_exact_target(): void
    {
        foreach ([AuctionStatus::Scheduled, AuctionStatus::Live] as $status) {
            [$auction] = $this->auction($status, startsAt: now()->subHour(), endsAt: now()->addHour());
            [$bidder] = $this->participant($auction, AuctionParticipantStatus::Registered);

            $this->assertSame(PaymentSubmissionStatus::PendingReview, $this->submit($auction, $bidder, PaymentPurpose::BidderDeposit)->status);
        }

        foreach ([AuctionStatus::Ended, AuctionStatus::Cancelled] as $status) {
            $this->expectAuctionException(__('auction.errors.bidder_deposit_state_not_allowed'), function () use ($status): void {
                [$auction] = $this->auction($status);
                [$bidder] = $this->participant($auction, AuctionParticipantStatus::Registered);
                $this->submit($auction, $bidder, PaymentPurpose::BidderDeposit);
            });
        }

        $this->expectAuctionException(__('auction.errors.registration_required'), function (): void {
            [$auction] = $this->auction(AuctionStatus::Scheduled, endsAt: now()->addHour());
            $this->submit($auction, $this->user(), PaymentPurpose::BidderDeposit);
        });

        $this->expectAuctionException(__('auction.errors.participant_not_eligible'), function (): void {
            [$auction] = $this->auction(AuctionStatus::Scheduled, endsAt: now()->addHour());
            [$bidder] = $this->participant($auction, AuctionParticipantStatus::Blocked);
            $this->submit($auction, $bidder, PaymentPurpose::BidderDeposit);
        });

        [$auction] = $this->auction(AuctionStatus::Scheduled, endsAt: now()->addHour());
        [$bidder, $participant] = $this->participant($auction, AuctionParticipantStatus::Registered);
        $otherDeposit = AuctionDeposit::create([
            'auction_id' => $auction->id,
            'participant_id' => $participant->id,
            'user_id' => $this->user()->id,
            'type' => 'bidder',
            'status' => AuctionDepositStatus::PendingReview,
            'required_amount_minor' => $auction->bidder_deposit_amount_minor,
            'currency_code' => $auction->currency_code,
        ]);
        $submission = $this->depositSubmission($auction, $bidder, $otherDeposit, PaymentPurpose::BidderDeposit);

        $this->expectAuctionException(__('auction.errors.payment_target_owner_mismatch'), fn () => $this->approve($submission));
    }

    public function test_winner_settlement_requires_current_winner_current_settlement_state_amount_and_currency(): void
    {
        [$auction, $winner, $bid] = $this->auctionWithWinningBid();
        $settlement = $this->settlement($auction, $bid, 90_000);

        $this->assertSame(
            PaymentSubmissionStatus::PendingReview,
            $this->submit($auction->refresh(), $winner, PaymentPurpose::WinnerSettlement)->status
        );

        $this->expectAuctionException(__('auction.errors.payment_target_owner_mismatch'), fn () => $this->submit($auction->refresh(), $this->user(), PaymentPurpose::WinnerSettlement));

        $less = $this->settlementSubmission($auction, $settlement, $winner, 89_999);
        $this->expectAuctionException(__('auction.errors.payment_amount_mismatch'), fn () => $this->approve($less));

        $more = $this->settlementSubmission($auction, $settlement, $winner, 90_001);
        $this->expectAuctionException(__('auction.errors.payment_amount_exceeds_remaining'), fn () => $this->approve($more));

        $wrongCurrency = $this->settlementSubmission($auction, $settlement, $winner, 90_000, 'USD');
        $this->expectAuctionException(__('auction.errors.payment_currency_mismatch'), fn () => $this->approve($wrongCurrency));

        $paid = $this->settlementSubmission($auction, $settlement, $winner, 90_000);
        $settlement->forceFill(['status' => SettlementStatus::Paid, 'remaining_amount_minor' => 0, 'amount_paid_minor' => 90_000])->save();
        $this->expectAuctionException(__('auction.errors.payment_obligation_already_paid'), fn () => $this->approve($paid));

        [$handoverAuction, $handoverWinner, $handoverBid] = $this->auctionWithWinningBid(AuctionStatus::HandoverPending);
        $handoverSettlement = $this->settlement($handoverAuction, $handoverBid, 90_000);
        $this->expectAuctionException(
            __('auction.errors.winner_payment_state_not_allowed'),
            fn () => $this->approve($this->settlementSubmission($handoverAuction, $handoverSettlement, $handoverWinner, 90_000))
        );
    }

    public function test_winner_change_before_approval_rejects_old_submission_without_transaction(): void
    {
        [$auction, $winner, $oldBid] = $this->auctionWithWinningBid();
        $oldSettlement = $this->settlement($auction, $oldBid, 90_000);
        $submission = $this->settlementSubmission($auction, $oldSettlement, $winner, 90_000);

        [$newWinner, $participant] = $this->participant($auction, AuctionParticipantStatus::Qualified);
        $newBid = $this->bid($auction, $participant, $newWinner, 95_000, 2);
        $oldSettlement->forceFill(['is_current' => false, 'current_marker' => null, 'superseded_at' => now()])->save();
        $auction->forceFill(['winning_bid_id' => $newBid->id])->save();
        $this->settlement($auction->refresh(), $newBid, 85_000, sequence: 2, previous: $oldSettlement);

        $this->expectAuctionException(__('auction.errors.payment_target_not_current'), fn () => $this->approve($submission));
        $this->assertSame(0, PaymentTransaction::where('payment_submission_id', $submission->id)->count());
    }

    private function submit(Auction $auction, User $user, PaymentPurpose $purpose): PaymentSubmission
    {
        return app(SubmitPaymentSubmissionAction::class)->execute(
            $auction,
            $user->id,
            $purpose,
            $this->paymentMethod()->public_id,
            UploadedFile::fake()->create('receipt.pdf', 10, 'application/pdf'),
            'payment-'.Str::ulid()
        );
    }

    private function approve(PaymentSubmission $submission): PaymentSubmission
    {
        return app(ReviewPaymentSubmissionAction::class)->approve($submission, $this->user('admin')->id, 'approved');
    }

    private function expectAuctionException(string $message, callable $callback): void
    {
        try {
            $callback();
            $this->fail('Expected auction exception was not thrown.');
        } catch (AuctionException $exception) {
            $this->assertSame($message, $exception->getMessage());
        }
    }

    private function auction(
        AuctionStatus $status,
        int $sellerDeposit = 2_000,
        ?Carbon $startsAt = null,
        ?Carbon $endsAt = null
    ): array {
        $seller = $this->user();
        $terms = AuctionTermsVersion::create([
            'version_number' => ((int) AuctionTermsVersion::max('version_number')) + 1,
            'title' => 'Terms',
            'body' => 'Auction terms.',
            'is_active' => true,
            'published_at' => now()->subDay(),
        ]);
        $category = Category::create(['name' => 'payment-state-cat-'.Str::ulid(), 'display_order' => 0]);
        $country = Country::create(['name' => 'payment-state-country-'.Str::ulid(), 'code' => strtoupper(substr((string) Str::ulid(), 0, 6))]);

        $auction = Auction::create([
            'seller_id' => $seller->id,
            'category_id' => $category->id,
            'country_id' => $country->id,
            'terms_version_id' => $terms->id,
            'currency_code' => 'JOD',
            'title' => 'Payment state auction',
            'description' => 'Payment state auction.',
            'status' => $status,
            'starting_amount_minor' => 10_000,
            'reserve_amount_minor' => null,
            'minimum_bid_increment_minor' => 500,
            'seller_deposit_amount_minor' => $sellerDeposit,
            'bidder_deposit_amount_minor' => 1_000,
            'platform_fee_type' => 'percentage',
            'platform_fee_basis_points' => 250,
            'platform_fee_fixed_minor' => 0,
            'winner_payment_deadline_hours' => 48,
            'handover_deadline_hours' => 72,
            'starts_at' => $startsAt ?? now()->subDays(2),
            'original_ends_at' => $endsAt ?? now()->subMinute(),
            'ends_at' => $endsAt ?? now()->subMinute(),
        ]);

        return [$auction, $seller];
    }

    private function auctionWithWinningBid(AuctionStatus $status = AuctionStatus::PaymentPending): array
    {
        [$auction] = $this->auction($status);
        [$winner, $participant] = $this->participant($auction, AuctionParticipantStatus::Qualified);
        $bid = $this->bid($auction, $participant, $winner, 100_000, 1);
        $auction->forceFill(['winning_bid_id' => $bid->id])->save();

        return [$auction->refresh(), $winner, $bid];
    }

    private function participant(Auction $auction, AuctionParticipantStatus $status): array
    {
        $user = $this->user();
        $participant = AuctionParticipant::create([
            'auction_id' => $auction->id,
            'user_id' => $user->id,
            'status' => $status,
            'registered_at' => now()->subDay(),
            'qualified_at' => $status === AuctionParticipantStatus::Qualified ? now()->subHour() : null,
        ]);

        return [$user, $participant];
    }

    private function bid(Auction $auction, AuctionParticipant $participant, User $user, int $amount, int $sequence): AuctionBid
    {
        return AuctionBid::create([
            'auction_id' => $auction->id,
            'participant_id' => $participant->id,
            'bidder_id' => $user->id,
            'amount_minor' => $amount,
            'currency_code' => 'JOD',
            'sequence_number' => $sequence,
            'idempotency_key' => 'payment-state-bid-'.Str::ulid(),
            'server_received_at' => now(),
            'accepted_at' => now(),
        ]);
    }

    private function settlement(
        Auction $auction,
        AuctionBid $bid,
        int $amountDue,
        int $sequence = 1,
        ?AuctionSettlement $previous = null
    ): AuctionSettlement {
        $winningAmount = (int) $bid->amount_minor;
        $depositApplied = $winningAmount - $amountDue;

        return AuctionSettlement::create([
            'auction_id' => $auction->id,
            'winning_bid_id' => $bid->id,
            'winner_id' => $bid->bidder_id,
            'sequence_number' => $sequence,
            'is_current' => true,
            'current_marker' => 1,
            'previous_settlement_id' => $previous?->id,
            'status' => SettlementStatus::PaymentPending,
            'winning_amount_minor' => $winningAmount,
            'deposit_applied_minor' => $depositApplied,
            'platform_fee_minor' => 2_500,
            'seller_net_amount_minor' => max(0, $winningAmount - 2_500),
            'amount_due_minor' => $amountDue,
            'amount_paid_minor' => 0,
            'remaining_amount_minor' => $amountDue,
            'currency_code' => 'JOD',
            'payment_due_at' => now()->addHour(),
        ]);
    }

    private function sellerDepositSubmission(Auction $auction, User $seller, ?int $amount = null, ?string $currency = null): PaymentSubmission
    {
        $deposit = AuctionDeposit::create([
            'auction_id' => $auction->id,
            'user_id' => $seller->id,
            'type' => 'seller',
            'status' => AuctionDepositStatus::PendingReview,
            'required_amount_minor' => $auction->seller_deposit_amount_minor,
            'currency_code' => $auction->currency_code,
        ]);

        return $this->depositSubmission($auction, $seller, $deposit, PaymentPurpose::SellerDeposit, $amount, $currency);
    }

    private function depositSubmission(
        Auction $auction,
        User $user,
        AuctionDeposit $deposit,
        PaymentPurpose $purpose,
        ?int $amount = null,
        ?string $currency = null
    ): PaymentSubmission {
        return PaymentSubmission::create([
            'auction_id' => $auction->id,
            'deposit_id' => $deposit->id,
            'user_id' => $user->id,
            'payment_method_id' => $this->paymentMethod()->id,
            'purpose' => $purpose,
            'status' => PaymentSubmissionStatus::PendingReview,
            'amount_minor' => $amount ?? $deposit->required_amount_minor,
            'currency_code' => $currency ?? $auction->currency_code,
            'receipt_disk' => 'spaces_private',
            'receipt_path' => 'deposit.pdf',
            'receipt_mime_type' => 'application/pdf',
            'receipt_size_bytes' => 100,
            'idempotency_key' => 'deposit-'.Str::ulid(),
            'submitted_at' => now(),
        ]);
    }

    private function settlementSubmission(
        Auction $auction,
        AuctionSettlement $settlement,
        User $winner,
        int $amount,
        string $currency = 'JOD'
    ): PaymentSubmission {
        return PaymentSubmission::create([
            'auction_id' => $auction->id,
            'settlement_id' => $settlement->id,
            'user_id' => $winner->id,
            'payment_method_id' => $this->paymentMethod()->id,
            'purpose' => PaymentPurpose::WinnerSettlement,
            'status' => PaymentSubmissionStatus::PendingReview,
            'amount_minor' => $amount,
            'currency_code' => $currency,
            'receipt_disk' => 'spaces_private',
            'receipt_path' => 'settlement.pdf',
            'receipt_mime_type' => 'application/pdf',
            'receipt_size_bytes' => 100,
            'idempotency_key' => 'settlement-'.Str::ulid(),
            'submitted_at' => now(),
        ]);
    }

    private function paymentMethod(): PaymentMethod
    {
        return PaymentMethod::create([
            'name' => 'Manual transfer',
            'code' => 'payment-state-'.Str::ulid(),
            'instructions' => 'Upload receipt.',
            'requires_manual_review' => true,
            'is_active' => true,
        ]);
    }

    private function user(string $role = 'user', array $permissions = []): User
    {
        $unique = strtolower((string) Str::ulid());
        $phoneSuffix = str_pad((string) (abs(crc32($unique)) % 10_000_000), 7, '0', STR_PAD_LEFT);

        return User::create([
            'name' => fake()->name(),
            'email' => "payment-state-{$unique}@example.test",
            'phone' => '+96275'.$phoneSuffix,
            'password' => Hash::make('password'),
            'role' => $role,
            'auction_permissions' => $permissions,
            'email_verified_at' => now(),
        ]);
    }
}
