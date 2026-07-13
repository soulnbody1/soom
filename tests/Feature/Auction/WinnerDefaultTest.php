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
use App\Models\Auction\AuctionBid;
use App\Models\Auction\AuctionConfigurationVersion;
use App\Models\Auction\AuctionDeposit;
use App\Models\Auction\AuctionParticipant;
use App\Models\Auction\AuctionSettlement;
use App\Models\Auction\AuctionTermsAcceptance;
use App\Models\Auction\AuctionTermsVersion;
use App\Models\Auction\AuctionWinnerReassignment;
use App\Models\Auction\PaymentMethod;
use App\Models\Auction\PaymentSubmission;
use App\Models\Auction\PaymentTransaction;
use App\Models\Auction\RefundTransaction;
use App\Models\Category;
use App\Models\Country;
use App\Models\User;
use App\Repositories\Auction\AuctionConfigurationSnapshotRepository;
use App\Services\Auction\Actions\FinalizeAuctionAction;
use App\Services\Auction\Actions\MarkWinnerDefaultedAction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

final class WinnerDefaultTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate', ['--force' => true]);
    }

    public function test_rejects_default_before_deadline_without_override(): void
    {
        [$auction] = $this->finalizedAuctionWithBids([100_000]);

        $this->expectException(AuctionException::class);
        $this->expectExceptionMessage(__('auction.errors.payment_deadline_not_expired'));

        app(MarkWinnerDefaultedAction::class)->execute($auction, $this->user('admin')->id, 'too early');
    }

    public function test_rejects_missing_payment_due_at(): void
    {
        [$auction] = $this->finalizedAuctionWithBids([100_000]);
        $auction->settlement->forceFill(['payment_due_at' => null])->save();

        $this->expectException(AuctionException::class);
        $this->expectExceptionMessage(__('auction.errors.payment_deadline_missing'));

        app(MarkWinnerDefaultedAction::class)->execute($auction->refresh(), $this->user('admin')->id, 'missing due');
    }

    public function test_rejects_paid_settlement(): void
    {
        [$auction] = $this->finalizedAuctionWithBids([100_000]);
        $auction->settlement->forceFill([
            'status' => SettlementStatus::Paid,
            'amount_paid_minor' => $auction->settlement->amount_due_minor,
            'remaining_amount_minor' => 0,
            'paid_at' => Carbon::now(),
        ])->save();

        $this->expectException(AuctionException::class);
        $this->expectExceptionMessage(__('auction.errors.payment_obligation_already_paid'));

        app(MarkWinnerDefaultedAction::class)->execute($auction->refresh(), $this->user('admin')->id, 'already paid');
    }

    public function test_override_requires_permission_and_reason_and_records_deadline_metadata(): void
    {
        [$auction] = $this->finalizedAuctionWithBids([100_000]);
        $admin = $this->user('admin');

        config(['auction.admin_permissions' => ['auction.winners.mark_defaulted']]);

        try {
            app(MarkWinnerDefaultedAction::class)->execute($auction->refresh(), $admin->id, 'override', false, true, 'urgent review');
            $this->fail('Override without dedicated permission should fail.');
        } catch (AuctionException $exception) {
            $this->assertSame(__('auction.errors.winner_default_override_not_authorized'), $exception->getMessage());
        }

        $admin->forceFill(['auction_permissions' => ['auction.payment.override_deadline']])->save();
        app(MarkWinnerDefaultedAction::class)->execute($auction->refresh(), $admin->id, 'override', false, true, 'urgent review');

        $settlement = $auction->settlement->refresh();
        $this->assertSame(SettlementStatus::Defaulted, $settlement->status);
        $this->assertSame($admin->id, $settlement->overridden_by);
        $this->assertSame('urgent review', $settlement->override_reason);
        $this->assertNotNull($settlement->original_payment_due_at);
    }

    public function test_defaulted_bidder_is_excluded_even_with_multiple_bids(): void
    {
        [$auction, $bids] = $this->finalizedAuctionWithBids([100_000]);
        $winnerParticipant = AuctionParticipant::where('user_id', $bids[0]->bidder_id)->firstOrFail();
        $this->bid($auction, $winnerParticipant, $bids[0]->bidder, 98_000, 2);
        [$alternative, $alternativeParticipant] = $this->qualifiedParticipant($auction, 10_000);
        $alternativeBid = $this->bid($auction, $alternativeParticipant, $alternative, 95_000, 3);
        $this->acceptTerms($auction, $alternativeParticipant, $alternative);
        $auction->settlement->forceFill(['payment_due_at' => Carbon::now()->subMinute()])->save();

        $updated = app(MarkWinnerDefaultedAction::class)->execute($auction->refresh(), $this->user('admin')->id, 'deadline expired', true);

        $this->assertSame($alternativeBid->id, $updated->settlement->winning_bid_id);
        $this->assertSame($alternative->id, $updated->settlement->winner_id);
    }

    public function test_skips_candidates_without_terms_or_with_non_held_deposit(): void
    {
        [$auction, $bids] = $this->finalizedAuctionWithBids([100_000]);
        [$missingTerms, $missingTermsParticipant] = $this->qualifiedParticipant($auction, 10_000);
        $this->bid($auction, $missingTermsParticipant, $missingTerms, 95_000, 2);
        [$refunded, $refundedParticipant] = $this->qualifiedParticipant($auction, 10_000);
        $this->bid($auction, $refundedParticipant, $refunded, 90_000, 3);
        $this->acceptTerms($auction, $refundedParticipant, $refunded);
        AuctionDeposit::where('user_id', $refunded->id)->firstOrFail()->forceFill([
            'status' => AuctionDepositStatus::RefundPending,
            'held_amount_minor' => 0,
        ])->save();
        $auction->settlement->forceFill(['payment_due_at' => Carbon::now()->subMinute()])->save();

        $updated = app(MarkWinnerDefaultedAction::class)->execute($auction->refresh(), $this->user('admin')->id, 'deadline expired', true);

        $this->assertSame(AuctionStatus::Unsold, $updated->status);
        $this->assertSame(0, AuctionWinnerReassignment::where('auction_id', $auction->id)->count());
        $this->assertSame(0, RefundTransaction::where('deposit_id', AuctionDeposit::where('user_id', $bids[0]->bidder_id)->value('id'))->count());
    }

    public function test_default_closes_old_settlement_supersedes_submission_and_forfeits_applied_deposit(): void
    {
        [$auction, $bids] = $this->finalizedAuctionWithBids([100_000, 95_000]);
        $oldSettlement = $auction->settlement;
        $oldSettlement->forceFill(['payment_due_at' => Carbon::now()->subMinute()])->save();
        $submission = $this->paymentSubmission($auction, $oldSettlement, $bids[0]->bidder_id, (int) $oldSettlement->remaining_amount_minor);

        $updated = app(MarkWinnerDefaultedAction::class)->execute($auction->refresh(), $this->user('admin')->id, 'deadline expired', true);

        $oldSettlement->refresh();
        $defaultedDeposit = AuctionDeposit::where('user_id', $bids[0]->bidder_id)->firstOrFail();
        $this->assertFalse($oldSettlement->is_current);
        $this->assertNull($oldSettlement->current_marker);
        $this->assertSame(SettlementStatus::Defaulted, $oldSettlement->status);
        $this->assertNotNull($oldSettlement->defaulted_at);
        $this->assertSame(PaymentSubmissionStatus::Rejected, $submission->refresh()->status);
        $this->assertSame('winner_default_superseded', $submission->review_note);
        $this->assertSame(AuctionDepositStatus::Forfeited, $defaultedDeposit->status);
        $this->assertSame(0, $defaultedDeposit->held_amount_minor);
        $this->assertSame(0, $defaultedDeposit->applied_amount_minor);
        $this->assertSame(10_000, $defaultedDeposit->forfeited_amount_minor);
        $this->assertSame(2, AuctionSettlement::where('auction_id', $auction->id)->count());
        $this->assertSame(1, AuctionWinnerReassignment::where('auction_id', $auction->id)->count());
        $this->assertSame($bids[1]->bidder_id, $updated->settlement->winner_id);
    }

    public function test_no_alternative_finishes_unsold_and_has_no_current_settlement(): void
    {
        [$auction] = $this->finalizedAuctionWithBids([100_000]);
        $auction->settlement->forceFill(['payment_due_at' => Carbon::now()->subMinute()])->save();

        $updated = app(MarkWinnerDefaultedAction::class)->execute($auction->refresh(), $this->user('admin')->id, 'deadline expired', false);

        $this->assertSame(AuctionStatus::Unsold, $updated->status);
        $this->assertNull($updated->winning_bid_id);
        $this->assertSame(0, AuctionSettlement::where('auction_id', $auction->id)->where('current_marker', 1)->count());
    }

    private function finalizedAuctionWithBids(array $amounts): array
    {
        [$auction] = $this->auctionWithoutBids();
        $bids = [];
        foreach ($amounts as $index => $amount) {
            [$user, $participant] = $this->qualifiedParticipant($auction, 10_000);
            $bids[] = $this->bid($auction, $participant, $user, $amount, $index + 1);
            $this->acceptTerms($auction, $participant, $user);
        }

        $auction = app(FinalizeAuctionAction::class)->execute($auction);

        return [$auction, $bids];
    }

    private function auctionWithoutBids(AuctionStatus $status = AuctionStatus::Ended): array
    {
        $seller = $this->user();
        $terms = AuctionTermsVersion::create([
            'version_number' => (int) (AuctionTermsVersion::max('version_number') ?? 0) + 1,
            'title' => 'Terms',
            'body' => 'Auction terms.',
            'is_active' => true,
            'published_at' => Carbon::now()->subDay(),
        ]);
        $configuration = AuctionConfigurationVersion::create([
            'version_number' => (int) (AuctionConfigurationVersion::max('version_number') ?? 0) + 1,
            'configuration' => [
                'non_winner_deposit_policy' => config('auction.non_winner_deposit_policy'),
                'non_winner_deposit_hold_count' => (int) config('auction.non_winner_deposit_hold_count', 1),
                'seller_deposit_policy' => config('auction.seller_deposit_policy'),
                'winner_default_deposit_policy' => config('auction.winner_default_deposit_policy'),
            ],
            'is_active' => true,
            'published_at' => Carbon::now()->subDay(),
        ]);
        $category = Category::create(['name' => 'cat-'.uniqid(), 'display_order' => 0]);
        $country = Country::create(['name' => 'country-'.uniqid(), 'code' => 'C'.strtoupper(substr(md5(uniqid()), 0, 5))]);

        $auction = Auction::create([
            'seller_id' => $seller->id,
            'category_id' => $category->id,
            'country_id' => $country->id,
            'terms_version_id' => $terms->id,
            'configuration_version_id' => $configuration->id,
            'currency_code' => 'JOD',
            'title' => 'Winner default auction',
            'description' => 'Winner default auction.',
            'status' => $status,
            'starting_amount_minor' => 10_000,
            'reserve_amount_minor' => null,
            'minimum_bid_increment_minor' => 500,
            'seller_deposit_amount_minor' => 2_000,
            'bidder_deposit_amount_minor' => 10_000,
            'platform_fee_type' => 'percentage',
            'platform_fee_basis_points' => 250,
            'platform_fee_fixed_minor' => 0,
            'winner_payment_deadline_hours' => 48,
            'handover_deadline_hours' => 72,
            'starts_at' => Carbon::now()->subDays(2),
            'original_ends_at' => Carbon::now()->subMinute(),
            'ends_at' => Carbon::now()->subMinute(),
        ]);
        app(AuctionConfigurationSnapshotRepository::class)->createForApprovedAuction($auction, $seller->id);

        return [$auction->refresh(), $seller];
    }

    private function qualifiedParticipant(Auction $auction, int $heldDeposit): array
    {
        $user = $this->user();
        $participant = AuctionParticipant::create([
            'auction_id' => $auction->id,
            'user_id' => $user->id,
            'status' => AuctionParticipantStatus::Qualified,
            'registered_at' => Carbon::now()->subDays(2),
            'qualified_at' => Carbon::now()->subDay(),
        ]);

        $deposit = AuctionDeposit::create([
            'auction_id' => $auction->id,
            'participant_id' => $participant->id,
            'user_id' => $user->id,
            'type' => 'bidder',
            'status' => AuctionDepositStatus::Held,
            'required_amount_minor' => $heldDeposit,
            'held_amount_minor' => $heldDeposit,
            'currency_code' => $auction->currency_code,
            'held_at' => Carbon::now()->subDay(),
        ]);
        $this->successfulDepositPayment($auction, $deposit, $user->id, $heldDeposit);

        return [$user, $participant];
    }

    private function bid(Auction $auction, AuctionParticipant $participant, User $user, int $amount, int $sequence): AuctionBid
    {
        return AuctionBid::create([
            'auction_id' => $auction->id,
            'participant_id' => $participant->id,
            'bidder_id' => $user->id,
            'amount_minor' => $amount,
            'currency_code' => $auction->currency_code,
            'sequence_number' => $sequence,
            'idempotency_key' => 'winner-default-bid-'.$sequence.'-'.uniqid(),
            'server_received_at' => Carbon::now(),
            'accepted_at' => Carbon::now(),
        ]);
    }

    private function acceptTerms(Auction $auction, AuctionParticipant $participant, User $user): void
    {
        AuctionTermsAcceptance::create([
            'auction_id' => $auction->id,
            'participant_id' => $participant->id,
            'user_id' => $user->id,
            'terms_version_id' => $auction->terms_version_id,
            'accepted_at' => Carbon::now()->subDay(),
        ]);
    }

    private function paymentSubmission(Auction $auction, AuctionSettlement $settlement, int $userId, int $amount): PaymentSubmission
    {
        $method = PaymentMethod::create([
            'name' => 'Winner default method',
            'code' => 'winner-default-'.uniqid(),
            'instructions' => 'Test method.',
            'requires_manual_review' => true,
            'is_active' => true,
        ]);

        return PaymentSubmission::create([
            'auction_id' => $auction->id,
            'settlement_id' => $settlement->id,
            'user_id' => $userId,
            'payment_method_id' => $method->id,
            'purpose' => PaymentPurpose::WinnerSettlement,
            'status' => PaymentSubmissionStatus::PendingReview,
            'amount_minor' => $amount,
            'currency_code' => $auction->currency_code,
            'receipt_disk' => 'spaces_private',
            'receipt_path' => 'winner-default.pdf',
            'receipt_mime_type' => 'application/pdf',
            'receipt_size_bytes' => 100,
            'idempotency_key' => 'winner-default-payment-'.uniqid(),
            'submitted_at' => Carbon::now(),
        ]);
    }

    private function successfulDepositPayment(Auction $auction, AuctionDeposit $deposit, int $userId, int $amount): PaymentTransaction
    {
        $method = PaymentMethod::create([
            'name' => 'Deposit payment method',
            'code' => 'winner-default-deposit-'.uniqid(),
            'instructions' => 'Test method.',
            'requires_manual_review' => true,
            'is_active' => true,
        ]);

        $submission = PaymentSubmission::create([
            'auction_id' => $auction->id,
            'deposit_id' => $deposit->id,
            'user_id' => $userId,
            'payment_method_id' => $method->id,
            'purpose' => PaymentPurpose::BidderDeposit,
            'status' => PaymentSubmissionStatus::Approved,
            'amount_minor' => $amount,
            'currency_code' => $auction->currency_code,
            'receipt_disk' => 'spaces_private',
            'receipt_path' => 'deposit-payment.pdf',
            'receipt_mime_type' => 'application/pdf',
            'receipt_size_bytes' => 100,
            'idempotency_key' => 'winner-default-deposit-'.uniqid(),
            'submitted_at' => Carbon::now(),
            'reviewed_at' => Carbon::now(),
        ]);

        return PaymentTransaction::create([
            'payment_submission_id' => $submission->id,
            'auction_id' => $auction->id,
            'user_id' => $userId,
            'purpose' => PaymentPurpose::BidderDeposit,
            'status' => PaymentTransactionStatus::Succeeded,
            'amount_minor' => $amount,
            'currency_code' => $auction->currency_code,
            'provider' => 'manual',
            'provider_transaction_id' => 'winner-default-deposit-'.uniqid(),
            'idempotency_key' => 'winner-default-deposit-'.uniqid(),
            'successful_obligation_key' => "deposit:{$deposit->id}",
            'processed_at' => Carbon::now(),
        ]);
    }

    private function user(string $role = 'user'): User
    {
        $unique = strtolower((string) Str::ulid());
        $phoneSuffix = str_pad((string) (abs(crc32($unique)) % 10_000_000), 7, '0', STR_PAD_LEFT);

        return User::create([
            'name' => fake()->name(),
            'email' => "winner-default-{$unique}@example.test",
            'phone' => '+96277'.$phoneSuffix,
            'password' => Hash::make('password'),
            'role' => $role,
            'email_verified_at' => Carbon::now(),
        ]);
    }
}
