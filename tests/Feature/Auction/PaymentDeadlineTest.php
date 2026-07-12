<?php

declare(strict_types=1);

namespace Tests\Feature\Auction;

use App\Domain\Auction\Enums\AuctionParticipantStatus;
use App\Domain\Auction\Enums\AuctionStatus;
use App\Domain\Auction\Enums\PaymentPurpose;
use App\Domain\Auction\Enums\PaymentSubmissionStatus;
use App\Domain\Auction\Enums\SettlementStatus;
use App\Domain\Auction\Exceptions\AuctionException;
use App\Models\Auction\Auction;
use App\Models\Auction\AuctionBid;
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
use App\Services\Auction\Support\PaymentEligibilityRule;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PaymentDeadlineTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate', ['--force' => true]);
        Storage::fake('spaces_private');
    }

    public function test_bidder_deposit_deadline_is_auction_end_and_override_only_bypasses_deadline(): void
    {
        [$auction] = $this->auction(AuctionStatus::Scheduled, endsAt: now()->addHour());
        [$bidder] = $this->participant($auction);

        $this->assertSame(
            PaymentSubmissionStatus::PendingReview,
            $this->submit($auction, $bidder, PaymentPurpose::BidderDeposit)->status
        );

        [$expiredAuction] = $this->auction(AuctionStatus::Live, endsAt: now()->subMinute());
        [$lateBidder] = $this->participant($expiredAuction);
        $this->expectAuctionException(__('auction.errors.payment_deadline_expired'), fn () => $this->submit($expiredAuction, $lateBidder, PaymentPurpose::BidderDeposit));

        [$reviewAuction] = $this->auction(AuctionStatus::Live, endsAt: now()->addHour());
        [$reviewBidder] = $this->participant($reviewAuction);
        $submission = $this->submit($reviewAuction, $reviewBidder, PaymentPurpose::BidderDeposit);
        $reviewAuction->forceFill(['ends_at' => now()->subMinute()])->save();

        $this->expectAuctionException(__('auction.errors.payment_deadline_expired'), fn () => $this->approve($submission));
        $this->expectAuctionException(__('auction.errors.payment_override_not_authorized'), fn () => $this->approve($submission, $this->admin(), true, 'late bank confirmation'));
        $this->expectAuctionException(__('auction.errors.payment_override_reason_required'), fn () => $this->approve($submission, $this->overrideAdmin(), true, ''));

        $approved = $this->approve($submission, $this->overrideAdmin(), true, 'late bank confirmation');

        $this->assertSame(PaymentSubmissionStatus::Approved, $approved->status);
        $this->assertNotNull($approved->overridden_at);
        $this->assertNotNull($approved->original_deadline);
        $this->assertSame('late bank confirmation', $approved->override_reason);
    }

    public function test_winner_payment_deadline_blocks_submit_and_review_without_authorized_override(): void
    {
        [$auction, $winner, $bid] = $this->auctionWithWinningBid();
        $expiredSettlement = $this->settlement($auction, $bid, now()->subMinute());

        $this->expectAuctionException(__('auction.errors.payment_deadline_expired'), fn () => $this->submit($auction, $winner, PaymentPurpose::WinnerSettlement));

        $expiredSettlement->forceFill(['payment_due_at' => now()->addHour()])->save();
        $submission = $this->submit($auction->refresh(), $winner, PaymentPurpose::WinnerSettlement);
        $expiredSettlement->forceFill(['payment_due_at' => now()->subMinute()])->save();

        $this->expectAuctionException(__('auction.errors.payment_deadline_expired'), fn () => $this->approve($submission));
        $this->expectAuctionException(__('auction.errors.payment_override_not_authorized'), fn () => $this->approve($submission, $this->admin(), true, 'manual bank delay'));
        $this->expectAuctionException(__('auction.errors.payment_override_reason_required'), fn () => $this->approve($submission, $this->overrideAdmin(), true, ''));

        $approved = $this->approve($submission, $this->overrideAdmin(), true, 'manual bank delay');

        $this->assertSame(PaymentSubmissionStatus::Approved, $approved->status);
        $this->assertSame($expiredSettlement->id, $approved->settlement_id);
        $this->assertSame(1, PaymentTransaction::where('payment_submission_id', $approved->id)->count());
    }

    public function test_deadline_override_does_not_bypass_current_target_cancelled_auction_or_paid_state(): void
    {
        [$auction, $winner, $bid] = $this->auctionWithWinningBid();
        $oldSettlement = $this->settlement($auction, $bid, now()->subMinute());
        $submission = $this->settlementSubmission($auction, $oldSettlement, $winner);

        [$newWinner, $participant] = $this->participant($auction);
        $newBid = $this->bid($auction, $participant, $newWinner, 95_000, 2);
        $oldSettlement->forceFill(['is_current' => false, 'current_marker' => null])->save();
        $auction->forceFill(['winning_bid_id' => $newBid->id])->save();
        $this->settlement($auction->refresh(), $newBid, now()->addHour(), sequence: 2, previous: $oldSettlement);

        $this->expectAuctionException(
            __('auction.errors.payment_target_not_current'),
            fn () => $this->approve($submission, $this->overrideAdmin(), true, 'late but stale')
        );

        [$cancelledAuction, $cancelledWinner, $cancelledBid] = $this->auctionWithWinningBid(AuctionStatus::Cancelled);
        $cancelledSettlement = $this->settlement($cancelledAuction, $cancelledBid, now()->subMinute());
        $cancelledSubmission = $this->settlementSubmission($cancelledAuction, $cancelledSettlement, $cancelledWinner);
        $this->expectAuctionException(
            __('auction.errors.winner_payment_state_not_allowed'),
            fn () => $this->approve($cancelledSubmission, $this->overrideAdmin(), true, 'cancelled auction')
        );

        [$paidAuction, $paidWinner, $paidBid] = $this->auctionWithWinningBid();
        $paidSettlement = $this->settlement($paidAuction, $paidBid, now()->subMinute());
        $paidSettlement->forceFill(['status' => SettlementStatus::Paid, 'amount_paid_minor' => 90_000, 'remaining_amount_minor' => 0])->save();
        $paidSubmission = $this->settlementSubmission($paidAuction, $paidSettlement, $paidWinner, amount: 90_000);
        $this->expectAuctionException(
            __('auction.errors.payment_obligation_already_paid'),
            fn () => $this->approve($paidSubmission, $this->overrideAdmin(), true, 'paid already')
        );
    }

    private function submit(Auction $auction, User $user, PaymentPurpose $purpose): PaymentSubmission
    {
        return app(SubmitPaymentSubmissionAction::class)->execute(
            $auction,
            $user->id,
            $purpose,
            $this->paymentMethod()->public_id,
            UploadedFile::fake()->create('receipt.pdf', 10, 'application/pdf'),
            'deadline-'.Str::ulid()
        );
    }

    private function approve(
        PaymentSubmission $submission,
        ?User $admin = null,
        bool $overrideDeadline = false,
        string $overrideReason = ''
    ): PaymentSubmission {
        $admin ??= $this->admin();

        return app(ReviewPaymentSubmissionAction::class)->approve(
            $submission,
            $admin->id,
            'approved',
            '',
            $overrideDeadline,
            $overrideReason
        );
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

    private function auction(AuctionStatus $status, ?Carbon $endsAt = null): array
    {
        $seller = $this->user();
        $terms = AuctionTermsVersion::create([
            'version_number' => ((int) AuctionTermsVersion::max('version_number')) + 1,
            'title' => 'Terms',
            'body' => 'Auction terms.',
            'is_active' => true,
            'published_at' => now()->subDay(),
        ]);
        $category = Category::create(['name' => 'deadline-cat-'.Str::ulid(), 'display_order' => 0]);
        $country = Country::create(['name' => 'deadline-country-'.Str::ulid(), 'code' => strtoupper(substr((string) Str::ulid(), 0, 6))]);

        $auction = Auction::create([
            'seller_id' => $seller->id,
            'category_id' => $category->id,
            'country_id' => $country->id,
            'terms_version_id' => $terms->id,
            'currency_code' => 'JOD',
            'title' => 'Deadline auction',
            'description' => 'Deadline auction.',
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
            'starts_at' => now()->subDay(),
            'original_ends_at' => $endsAt ?? now()->subMinute(),
            'ends_at' => $endsAt ?? now()->subMinute(),
        ]);

        return [$auction, $seller];
    }

    private function auctionWithWinningBid(AuctionStatus $status = AuctionStatus::PaymentPending): array
    {
        [$auction] = $this->auction($status);
        [$winner, $participant] = $this->participant($auction);
        $bid = $this->bid($auction, $participant, $winner, 100_000, 1);
        $auction->forceFill(['winning_bid_id' => $bid->id])->save();

        return [$auction->refresh(), $winner, $bid];
    }

    private function participant(Auction $auction): array
    {
        $user = $this->user();
        $participant = AuctionParticipant::create([
            'auction_id' => $auction->id,
            'user_id' => $user->id,
            'status' => AuctionParticipantStatus::Registered,
            'registered_at' => now()->subDay(),
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
            'idempotency_key' => 'deadline-bid-'.Str::ulid(),
            'server_received_at' => now(),
            'accepted_at' => now(),
        ]);
    }

    private function settlement(
        Auction $auction,
        AuctionBid $bid,
        Carbon $dueAt,
        int $sequence = 1,
        ?AuctionSettlement $previous = null
    ): AuctionSettlement {
        return AuctionSettlement::create([
            'auction_id' => $auction->id,
            'winning_bid_id' => $bid->id,
            'winner_id' => $bid->bidder_id,
            'sequence_number' => $sequence,
            'is_current' => true,
            'current_marker' => 1,
            'previous_settlement_id' => $previous?->id,
            'status' => SettlementStatus::PaymentPending,
            'winning_amount_minor' => 100_000,
            'deposit_applied_minor' => 10_000,
            'platform_fee_minor' => 2_500,
            'seller_net_amount_minor' => 97_500,
            'amount_due_minor' => 90_000,
            'amount_paid_minor' => 0,
            'remaining_amount_minor' => 90_000,
            'currency_code' => 'JOD',
            'payment_due_at' => $dueAt,
        ]);
    }

    private function settlementSubmission(
        Auction $auction,
        AuctionSettlement $settlement,
        User $winner,
        int $amount = 90_000
    ): PaymentSubmission {
        return PaymentSubmission::create([
            'auction_id' => $auction->id,
            'settlement_id' => $settlement->id,
            'user_id' => $winner->id,
            'payment_method_id' => $this->paymentMethod()->id,
            'purpose' => PaymentPurpose::WinnerSettlement,
            'status' => PaymentSubmissionStatus::PendingReview,
            'amount_minor' => $amount,
            'currency_code' => 'JOD',
            'receipt_disk' => 'spaces_private',
            'receipt_path' => 'deadline-settlement.pdf',
            'receipt_mime_type' => 'application/pdf',
            'receipt_size_bytes' => 100,
            'idempotency_key' => 'deadline-settlement-'.Str::ulid(),
            'submitted_at' => now(),
        ]);
    }

    private function paymentMethod(): PaymentMethod
    {
        return PaymentMethod::create([
            'name' => 'Manual deadline transfer',
            'code' => 'deadline-method-'.Str::ulid(),
            'instructions' => 'Upload receipt.',
            'requires_manual_review' => true,
            'is_active' => true,
        ]);
    }

    private function admin(): User
    {
        return $this->user('admin');
    }

    private function overrideAdmin(): User
    {
        return $this->user('admin', [PaymentEligibilityRule::OVERRIDE_DEADLINE_PERMISSION]);
    }

    private function user(string $role = 'user', array $permissions = []): User
    {
        $unique = strtolower((string) Str::ulid());
        $phoneSuffix = str_pad((string) (abs(crc32($unique)) % 10_000_000), 7, '0', STR_PAD_LEFT);

        return User::create([
            'name' => fake()->name(),
            'email' => "payment-deadline-{$unique}@example.test",
            'phone' => '+96274'.$phoneSuffix,
            'password' => Hash::make('password'),
            'role' => $role,
            'auction_permissions' => $permissions,
            'email_verified_at' => now(),
        ]);
    }
}
