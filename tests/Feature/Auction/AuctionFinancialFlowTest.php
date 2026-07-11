<?php

declare(strict_types=1);

namespace Tests\Feature\Auction;

use App\Domain\Auction\Enums\AuctionDepositStatus;
use App\Domain\Auction\Enums\AuctionParticipantStatus;
use App\Domain\Auction\Enums\AuctionStatus;
use App\Domain\Auction\Enums\OutboxStatus;
use App\Domain\Auction\Enums\PaymentPurpose;
use App\Domain\Auction\Enums\PaymentSubmissionStatus;
use App\Domain\Auction\Enums\PaymentTransactionStatus;
use App\Domain\Auction\Enums\SettlementStatus;
use App\Events\Auction\AuctionOutboxEvent;
use App\Models\Auction\Auction;
use App\Models\Auction\AuctionActivityLog;
use App\Models\Auction\AuctionBid;
use App\Models\Auction\AuctionDeposit;
use App\Models\Auction\AuctionParticipant;
use App\Models\Auction\AuctionTermsAcceptance;
use App\Models\Auction\AuctionTermsVersion;
use App\Models\Auction\OutboxMessage;
use App\Models\Auction\PaymentMethod;
use App\Models\Auction\PaymentSubmission;
use App\Models\Auction\PaymentTransaction;
use App\Models\Auction\RefundTransaction;
use App\Models\Category;
use App\Models\Country;
use App\Models\User;
use App\Services\Auction\Actions\CancelAuctionAction;
use App\Services\Auction\Actions\DispatchOutboxMessagesAction;
use App\Services\Auction\Actions\FinalizeAuctionAction;
use App\Services\Auction\Actions\MarkWinnerDefaultedAction;
use App\Services\Auction\Actions\RefundAuctionDepositAction;
use App\Services\Auction\Actions\ReviewPaymentSubmissionAction;
use App\Services\Auction\Actions\SubmitPaymentSubmissionAction;
use App\Services\Auction\Support\AuctionMediaService;
use Database\Factories\Auction\PaymentMethodFactory;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AuctionFinancialFlowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate', ['--force' => true]);
    }

    public function test_finalize_does_not_count_deposit_as_paid(): void
    {
        [$auction, $winner] = $this->auctionWithBid(100_000, 10_000);

        $settlement = app(FinalizeAuctionAction::class)->execute($auction)->settlement;

        $this->assertSame(SettlementStatus::PaymentPending, $settlement->status);
        $this->assertSame(100_000, $settlement->winning_amount_minor);
        $this->assertSame(10_000, $settlement->deposit_applied_minor);
        $this->assertSame(90_000, $settlement->amount_due_minor);
        $this->assertSame(0, $settlement->amount_paid_minor);
        $this->assertSame(90_000, $settlement->remaining_amount_minor);
        $this->assertSame(1, $settlement->current_marker);
        $this->assertTrue($settlement->is_current);
    }

    public function test_full_deposit_coverage_moves_directly_to_handover(): void
    {
        [$auction] = $this->auctionWithBid(100_000, 125_000);

        $auction = app(FinalizeAuctionAction::class)->execute($auction);
        $settlement = $auction->settlement;

        $this->assertSame(AuctionStatus::HandoverPending, $auction->status);
        $this->assertSame(SettlementStatus::Paid, $settlement->status);
        $this->assertSame(100_000, $settlement->deposit_applied_minor);
        $this->assertSame(0, $settlement->amount_due_minor);
        $this->assertSame(0, $settlement->amount_paid_minor);
        $this->assertSame(0, $settlement->remaining_amount_minor);
        $this->assertNotNull($settlement->handover_due_at);
        $this->assertNotNull($settlement->paid_at);
    }

    public function test_winner_default_creates_new_current_settlement_for_alternative_winner(): void
    {
        [$auction, $winner, $winnerParticipant, $winnerBid] = $this->auctionWithBid(100_000, 10_000);
        [$alternative, $alternativeParticipant] = $this->qualifiedParticipant($auction, heldDeposit: 20_000);
        $alternativeBid = $this->bid($auction, $alternativeParticipant, $alternative, 95_000, 2);
        $this->acceptTerms($auction, $alternativeParticipant, $alternative);

        $auction = app(FinalizeAuctionAction::class)->execute($auction);
        $oldSettlement = $auction->settlement;
        $oldSettlement->forceFill(['payment_due_at' => Carbon::now()->subMinute()])->save();

        $updated = app(MarkWinnerDefaultedAction::class)->execute($auction->refresh(), $this->user('admin')->id, 'deadline expired', true);

        $oldSettlement->refresh();
        $newSettlement = $updated->settlement;

        $this->assertSame(SettlementStatus::Defaulted, $oldSettlement->status);
        $this->assertFalse($oldSettlement->is_current);
        $this->assertNull($oldSettlement->current_marker);
        $this->assertSame($alternativeBid->id, $newSettlement->winning_bid_id);
        $this->assertSame($alternative->id, $newSettlement->winner_id);
        $this->assertSame($oldSettlement->id, $newSettlement->previous_settlement_id);
        $this->assertSame(1, $newSettlement->current_marker);
        $this->assertSame(2, $newSettlement->sequence_number);
        $this->assertSame(0, $newSettlement->amount_paid_minor);
    }

    public function test_rejected_deposit_submission_can_be_resubmitted_and_approved(): void
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
            UploadedFile::fake()->create('receipt-1.pdf', 10, 'application/pdf'),
            'seller-deposit-1'
        );

        $review->reject($first, $admin->id, 'unclear receipt');
        $this->assertSame(AuctionDepositStatus::PendingSubmission, $first->deposit->refresh()->status);

        $second = $submit->execute(
            $auction->refresh(),
            $seller->id,
            PaymentPurpose::SellerDeposit,
            $method->public_id,
            UploadedFile::fake()->create('receipt-2.pdf', 10, 'application/pdf'),
            'seller-deposit-2'
        );

        $review->approve($second, $admin->id, 'approved');

        $this->assertSame(AuctionDepositStatus::Held, $second->deposit->refresh()->status);
        $this->assertSame(2, PaymentSubmission::where('auction_id', $auction->id)->count());
    }

    public function test_refund_confirmation_is_idempotent(): void
    {
        [$auction, $user, $participant] = $this->auctionWithoutBids();
        $deposit = AuctionDeposit::create([
            'auction_id' => $auction->id,
            'participant_id' => $participant?->id,
            'user_id' => $user->id,
            'type' => 'bidder',
            'status' => AuctionDepositStatus::RefundPending,
            'required_amount_minor' => 10_000,
            'held_amount_minor' => 10_000,
            'currency_code' => 'JOD',
        ]);

        $action = app(RefundAuctionDepositAction::class);
        $refund = $action->execute($deposit, 'non winner');
        $providerRefundId = 'provider-ref-'.uniqid();

        $action->confirmSucceeded($refund, $providerRefundId);
        $action->confirmSucceeded($refund->refresh(), $providerRefundId);

        $this->assertSame(10_000, $deposit->refresh()->refunded_amount_minor);
        $this->assertSame(0, $deposit->held_amount_minor);
        $this->assertSame(1, RefundTransaction::where('deposit_id', $deposit->id)->count());
    }

    public function test_outbox_without_consumer_is_not_marked_published(): void
    {
        Event::fake([AuctionOutboxEvent::class]);

        [$auction] = $this->auctionWithoutBids();
        OutboxMessage::query()->delete();

        $message = OutboxMessage::create([
            'event_id' => (string) Str::ulid(),
            'topic' => 'auction.events',
            'event_type' => 'auction.test',
            'aggregate_type' => Auction::class,
            'aggregate_id' => $auction->id,
            'payload' => ['auction_id' => $auction->id],
            'status' => OutboxStatus::Pending,
            'available_at' => Carbon::now()->subMinute(),
        ]);

        $processed = app(DispatchOutboxMessagesAction::class)->execute(1);

        $this->assertSame(0, $processed);
        $this->assertSame(OutboxStatus::Failed, $message->refresh()->status);
        $this->assertNotNull($message->last_error);
    }

    public function test_outbox_consumer_success_marks_message_published(): void
    {
        [$auction] = $this->auctionWithoutBids();
        OutboxMessage::query()->delete();
        $activityCount = AuctionActivityLog::where('event_type', 'auction.outbox_consumed')
            ->where('auction_id', $auction->id)
            ->count();

        $message = OutboxMessage::create([
            'event_id' => (string) Str::ulid(),
            'topic' => 'auction.events',
            'event_type' => 'auction.test',
            'aggregate_type' => Auction::class,
            'aggregate_id' => $auction->id,
            'payload' => ['auction_id' => $auction->id],
            'status' => OutboxStatus::Pending,
            'available_at' => Carbon::now()->subMinute(),
        ]);

        $processed = app(DispatchOutboxMessagesAction::class)->execute(1);

        $this->assertSame(1, $processed);
        $this->assertSame(OutboxStatus::Published, $message->refresh()->status);
        $this->assertNotNull($message->published_at);
        $this->assertSame(
            $activityCount + 1,
            AuctionActivityLog::where('event_type', 'auction.outbox_consumed')->where('auction_id', $auction->id)->count()
        );
    }

    public function test_cancellation_generates_deposit_refund_plan_once(): void
    {
        [$auction] = $this->auctionWithoutBids(AuctionStatus::Scheduled);
        [$bidder] = $this->qualifiedParticipant($auction, 10_000);
        $admin = $this->user('admin');

        $action = app(CancelAuctionAction::class);
        $action->execute($auction, $admin->id, 'admin', 'seller cancelled');
        $action->execute($auction->refresh(), $admin->id, 'admin', 'seller cancelled replay');

        $this->assertSame(AuctionStatus::Cancelled, $auction->refresh()->status);
        $this->assertSame(1, RefundTransaction::where('auction_id', $auction->id)->where('user_id', $bidder->id)->count());
        $this->assertSame(AuctionDepositStatus::RefundPending, AuctionDeposit::where('auction_id', $auction->id)->where('user_id', $bidder->id)->firstOrFail()->status);
    }

    public function test_cancellation_with_successful_payment_generates_payment_refund_once(): void
    {
        [$auction, $winner] = $this->auctionWithBid(100_000, 10_000);
        $auction = app(FinalizeAuctionAction::class)->execute($auction);
        $auction->forceFill(['status' => AuctionStatus::HandoverPending])->save();
        $settlement = $auction->settlement;
        $payment = $this->successfulPayment($auction, $settlement, $winner->id, 90_000);
        $admin = $this->user('admin');

        $action = app(CancelAuctionAction::class);
        $action->execute($auction->refresh(), $admin->id, 'admin', 'cancel after payment');
        $action->execute($auction->refresh(), $admin->id, 'admin', 'cancel replay');

        $this->assertSame(AuctionStatus::Cancelled, $auction->refresh()->status);
        $this->assertSame(PaymentTransactionStatus::Reversed, $payment->refresh()->status);
        $this->assertSame(1, RefundTransaction::where('payment_transaction_id', $payment->id)->count());
        $this->assertSame(90_000, RefundTransaction::where('payment_transaction_id', $payment->id)->firstOrFail()->amount_minor);
    }

    public function test_auction_media_upload_is_cleaned_when_database_insert_fails(): void
    {
        Storage::fake('spaces');

        $auction = new Auction;
        $auction->forceFill(['public_id' => 'media-failure']);

        $this->expectException(QueryException::class);

        try {
            app(AuctionMediaService::class)->storeAuctionMedia($auction, [
                UploadedFile::fake()->image('auction.jpg'),
            ]);
        } finally {
            $this->assertSame([], Storage::disk('spaces')->allFiles());
        }
    }

    private function auctionWithoutBids(AuctionStatus $status = AuctionStatus::Ended): array
    {
        $seller = $this->user();
        $terms = AuctionTermsVersion::create([
            'version_number' => ((int) AuctionTermsVersion::max('version_number')) + 1,
            'title' => 'Terms',
            'body' => 'Auction terms.',
            'is_active' => true,
            'published_at' => Carbon::now()->subDay(),
        ]);

        $category = Category::create(['name' => fake()->unique()->word(), 'display_order' => 0]);
        $country = Country::create(['name' => fake()->country(), 'code' => fake()->unique()->countryCode()]);

        $auction = Auction::create([
            'seller_id' => $seller->id,
            'category_id' => $category->id,
            'country_id' => $country->id,
            'terms_version_id' => $terms->id,
            'currency_code' => 'JOD',
            'title' => 'Auction',
            'description' => 'Auction description.',
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

        return [$auction, $seller, null];
    }

    private function auctionWithBid(int $amount, int $heldDeposit): array
    {
        [$auction] = $this->auctionWithoutBids();
        [$winner, $participant] = $this->qualifiedParticipant($auction, $heldDeposit);
        $bid = $this->bid($auction, $participant, $winner, $amount, 1);
        $this->acceptTerms($auction, $participant, $winner);

        return [$auction, $winner, $participant, $bid];
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
            'currency_code' => $auction->currency_code,
            'held_at' => Carbon::now()->subDay(),
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
            'currency_code' => $auction->currency_code,
            'sequence_number' => $sequence,
            'idempotency_key' => "bid-{$sequence}",
            'server_received_at' => Carbon::now()->subMinutes(10 - $sequence),
            'accepted_at' => Carbon::now()->subMinutes(10 - $sequence),
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

    private function successfulPayment(Auction $auction, $settlement, int $userId, int $amount): PaymentTransaction
    {
        $method = PaymentMethod::create([
            'name' => 'Cancellation payment method',
            'code' => 'cancel-payment-'.uniqid(),
            'instructions' => 'Test payment method.',
            'requires_manual_review' => true,
            'is_active' => true,
        ]);

        $submission = PaymentSubmission::create([
            'auction_id' => $auction->id,
            'settlement_id' => $settlement->id,
            'user_id' => $userId,
            'payment_method_id' => $method->id,
            'purpose' => PaymentPurpose::WinnerSettlement,
            'status' => PaymentSubmissionStatus::Approved,
            'amount_minor' => $amount,
            'currency_code' => $auction->currency_code,
            'receipt_disk' => 'spaces_private',
            'receipt_path' => 'cancel-payment.pdf',
            'receipt_mime_type' => 'application/pdf',
            'receipt_size_bytes' => 100,
            'idempotency_key' => 'cancel-payment-'.uniqid(),
            'submitted_at' => Carbon::now(),
            'reviewed_at' => Carbon::now(),
        ]);

        return PaymentTransaction::create([
            'payment_submission_id' => $submission->id,
            'auction_id' => $auction->id,
            'user_id' => $userId,
            'purpose' => PaymentPurpose::WinnerSettlement,
            'status' => PaymentTransactionStatus::Succeeded,
            'amount_minor' => $amount,
            'currency_code' => $auction->currency_code,
            'provider' => 'manual',
            'provider_transaction_id' => 'cancel-payment-'.uniqid(),
            'idempotency_key' => 'cancel-payment-'.uniqid(),
            'processed_at' => Carbon::now(),
        ]);
    }

    private function user(string $role = 'user'): User
    {
        return User::create([
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => '+96279'.fake()->unique()->numerify('#######'),
            'password' => Hash::make('password'),
            'role' => $role,
            'email_verified_at' => Carbon::now(),
        ]);
    }
}
