<?php

declare(strict_types=1);

namespace Tests\Feature\Auction;

use App\Domain\Auction\Enums\AuctionDepositStatus;
use App\Domain\Auction\Enums\AuctionParticipantStatus;
use App\Domain\Auction\Enums\AuctionStatus;
use App\Domain\Auction\Enums\PaymentPurpose;
use App\Domain\Auction\Enums\PaymentSubmissionStatus;
use App\Domain\Auction\Enums\SettlementStatus;
use App\Models\Auction\Auction;
use App\Models\Auction\AuctionBid;
use App\Models\Auction\AuctionDeposit;
use App\Models\Auction\AuctionParticipant;
use App\Models\Auction\AuctionSettlement;
use App\Models\Auction\AuctionTermsAcceptance;
use App\Models\Auction\AuctionTermsVersion;
use App\Models\Auction\PaymentMethod;
use App\Models\Auction\PaymentSubmission;
use App\Models\Auction\PaymentTransaction;
use App\Models\Auction\RefundTransaction;
use App\Models\Category;
use App\Models\Country;
use App\Models\User;
use App\Services\Auction\Actions\FinalizeAuctionAction;
use App\Services\Auction\Actions\MarkWinnerDefaultedAction;
use App\Services\Auction\Actions\PlaceBidAction;
use App\Services\Auction\Actions\RefundAuctionDepositAction;
use App\Services\Auction\Actions\ReviewPaymentSubmissionAction;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('mysql-concurrency')]
final class AuctionMysqlConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('MySQL-only concurrency/integrity test.');
        }

        Artisan::call('migrate', ['--force' => true]);
    }

    public function test_database_prevents_two_current_settlements_for_same_auction(): void
    {
        [$auction, $firstBid, $secondBid] = $this->auctionWithTwoBids();

        AuctionSettlement::create([
            'auction_id' => $auction->id,
            'winning_bid_id' => $firstBid->id,
            'winner_id' => $firstBid->bidder_id,
            'sequence_number' => 1,
            'is_current' => true,
            'current_marker' => 1,
            'status' => SettlementStatus::PaymentPending,
            'winning_amount_minor' => 100_000,
            'deposit_applied_minor' => 10_000,
            'platform_fee_minor' => 2_500,
            'seller_net_amount_minor' => 97_500,
            'amount_due_minor' => 90_000,
            'amount_paid_minor' => 0,
            'remaining_amount_minor' => 90_000,
            'currency_code' => 'JOD',
            'payment_due_at' => Carbon::now()->addDay(),
        ]);

        $this->expectException(QueryException::class);

        AuctionSettlement::create([
            'auction_id' => $auction->id,
            'winning_bid_id' => $secondBid->id,
            'winner_id' => $secondBid->bidder_id,
            'sequence_number' => 2,
            'is_current' => true,
            'current_marker' => 1,
            'status' => SettlementStatus::PaymentPending,
            'winning_amount_minor' => 95_000,
            'deposit_applied_minor' => 10_000,
            'platform_fee_minor' => 2_375,
            'seller_net_amount_minor' => 92_625,
            'amount_due_minor' => 85_000,
            'amount_paid_minor' => 0,
            'remaining_amount_minor' => 85_000,
            'currency_code' => 'JOD',
            'payment_due_at' => Carbon::now()->addDay(),
        ]);
    }

    public function test_two_replayed_bid_submissions_do_not_create_duplicate_bid(): void
    {
        [$auction] = $this->auctionWithTwoBids(AuctionStatus::Live);
        [$bidder, $participant] = $this->qualifiedParticipant($auction, 10_000);
        $this->acceptTerms($auction, $participant, $bidder);

        $action = app(PlaceBidAction::class);

        $first = $action->execute($auction, $bidder->id, '110.000', 'JOD', 'same-bid-key');
        $second = $action->execute($auction->refresh(), $bidder->id, '110.000', 'JOD', 'same-bid-key');

        $this->assertSame($first->id, $second->id);
        $this->assertSame(3, AuctionBid::where('auction_id', $auction->id)->count());
        $this->assertSame($first->id, $auction->refresh()->current_leading_bid_id);
    }

    public function test_two_scheduler_finalizations_create_one_settlement(): void
    {
        [$auction] = $this->auctionWithTwoBids(AuctionStatus::Ended);

        $action = app(FinalizeAuctionAction::class);
        $action->execute($auction);
        $action->execute($auction->refresh());

        $this->assertSame(1, AuctionSettlement::where('auction_id', $auction->id)->where('current_marker', 1)->count());
        $this->assertSame(1, AuctionSettlement::where('auction_id', $auction->id)->count());
    }

    public function test_two_payment_approvals_apply_winner_payment_once(): void
    {
        [$auction, $firstBid] = $this->auctionWithTwoBids(AuctionStatus::PaymentPending);
        $settlement = $this->currentSettlementForBid($auction, $firstBid, 90_000);
        $submission = $this->paymentSubmission($auction, $settlement, $firstBid->bidder_id, 90_000);
        $admin = $this->user('admin');

        $action = app(ReviewPaymentSubmissionAction::class);
        $action->approve($submission, $admin->id, 'approved once');
        $action->approve($submission->refresh(), $admin->id, 'approved twice');

        $settlement->refresh();
        $this->assertSame(90_000, $settlement->amount_paid_minor);
        $this->assertSame(0, $settlement->remaining_amount_minor);
        $this->assertSame(1, PaymentTransaction::where('payment_submission_id', $submission->id)->count());
    }

    public function test_two_refund_confirmations_apply_amount_once(): void
    {
        [$auction] = $this->auctionWithTwoBids(AuctionStatus::PaymentPending);
        $deposit = AuctionDeposit::where('auction_id', $auction->id)
            ->where('type', 'bidder')
            ->firstOrFail();

        $action = app(RefundAuctionDepositAction::class);
        $refund = $action->execute($deposit, 'mysql replay refund');
        $providerRefundId = 'mysql-refund-'.uniqid();

        $action->confirmSucceeded($refund, $providerRefundId);
        $action->confirmSucceeded($refund->refresh(), $providerRefundId);

        $deposit->refresh();
        $this->assertSame(10_000, $deposit->refunded_amount_minor);
        $this->assertSame(0, $deposit->held_amount_minor);
        $this->assertSame(1, RefundTransaction::where('deposit_id', $deposit->id)->count());
    }

    public function test_two_winner_default_executions_do_not_duplicate_reassignment_settlement(): void
    {
        [$auction] = $this->auctionWithTwoBids(AuctionStatus::Ended);
        $auction = app(FinalizeAuctionAction::class)->execute($auction);
        $oldSettlement = $auction->settlement;
        $oldSettlement->forceFill(['payment_due_at' => Carbon::now()->subMinute()])->save();
        $admin = $this->user('admin');

        $action = app(MarkWinnerDefaultedAction::class);
        $action->execute($auction->refresh(), $admin->id, 'deadline expired', true);

        try {
            $action->execute($auction->refresh(), $admin->id, 'replayed default', true);
        } catch (\Throwable) {
            // A replay may be rejected by the new current winner's deadline; it must not create another settlement.
        }

        $this->assertSame(2, AuctionSettlement::where('auction_id', $auction->id)->count());
        $this->assertSame(1, AuctionSettlement::where('auction_id', $auction->id)->where('current_marker', 1)->count());
    }

    private function auctionWithTwoBids(AuctionStatus $status = AuctionStatus::PaymentPending): array
    {
        $seller = $this->user();
        $terms = AuctionTermsVersion::create([
            'version_number' => (int) (AuctionTermsVersion::max('version_number') ?? 0) + 1,
            'title' => 'Terms',
            'body' => 'Auction terms.',
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
            'currency_code' => 'JOD',
            'title' => 'MySQL concurrency auction',
            'description' => 'MySQL concurrency auction.',
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
            'original_ends_at' => $status === AuctionStatus::Live ? Carbon::now()->addHour() : Carbon::now()->subMinute(),
            'ends_at' => $status === AuctionStatus::Live ? Carbon::now()->addHour() : Carbon::now()->subMinute(),
        ]);

        $firstBid = $this->bidFor($auction, 100_000, 1);
        $secondBid = $this->bidFor($auction, 95_000, 2);

        return [$auction, $firstBid, $secondBid];
    }

    private function bidFor(Auction $auction, int $amount, int $sequence): AuctionBid
    {
        [$user, $participant] = $this->qualifiedParticipant($auction, 10_000);
        $this->acceptTerms($auction, $participant, $user);

        return AuctionBid::create([
            'auction_id' => $auction->id,
            'participant_id' => $participant->id,
            'bidder_id' => $user->id,
            'amount_minor' => $amount,
            'currency_code' => 'JOD',
            'sequence_number' => $sequence,
            'idempotency_key' => 'mysql-bid-'.uniqid(),
            'server_received_at' => Carbon::now(),
            'accepted_at' => Carbon::now(),
        ]);
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

        AuctionDeposit::create([
            'auction_id' => $auction->id,
            'participant_id' => $participant->id,
            'user_id' => $user->id,
            'type' => 'bidder',
            'status' => AuctionDepositStatus::Held,
            'required_amount_minor' => $heldDeposit,
            'held_amount_minor' => $heldDeposit,
            'currency_code' => 'JOD',
            'held_at' => Carbon::now()->subDay(),
        ]);

        return [$user, $participant];
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

    private function currentSettlementForBid(Auction $auction, AuctionBid $bid, int $amountDue): AuctionSettlement
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
            'amount_due_minor' => $amountDue,
            'amount_paid_minor' => 0,
            'remaining_amount_minor' => $amountDue,
            'currency_code' => 'JOD',
            'payment_due_at' => Carbon::now()->addDay(),
        ]);
    }

    private function paymentSubmission(Auction $auction, AuctionSettlement $settlement, int $userId, int $amount): PaymentSubmission
    {
        $method = PaymentMethod::create([
            'name' => 'MySQL test method',
            'code' => 'mysql-test-'.uniqid(),
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
            'currency_code' => 'JOD',
            'receipt_disk' => 'spaces_private',
            'receipt_path' => 'mysql-test.pdf',
            'receipt_mime_type' => 'application/pdf',
            'receipt_size_bytes' => 100,
            'provider_reference' => 'mysql-payment-'.uniqid(),
            'idempotency_key' => 'mysql-payment-'.uniqid(),
            'submitted_at' => Carbon::now(),
        ]);
    }

    private function user(string $role = 'user'): User
    {
        $unique = strtolower((string) Str::ulid());
        $phoneSuffix = str_pad((string) (abs(crc32($unique)) % 10_000_000), 7, '0', STR_PAD_LEFT);

        return User::create([
            'name' => fake()->name(),
            'email' => "auction-mysql-{$unique}@example.test",
            'phone' => '+96278'.$phoneSuffix,
            'password' => Hash::make('password'),
            'role' => $role,
            'email_verified_at' => Carbon::now(),
        ]);
    }
}
