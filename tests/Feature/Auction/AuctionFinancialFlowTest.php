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
use App\Domain\Auction\Enums\RefundTransactionStatus;
use App\Domain\Auction\Enums\SettlementStatus;
use App\Domain\Auction\Exceptions\AuctionException;
use App\Events\Auction\AuctionOutboxEvent;
use App\Http\Resources\Auction\AuctionBidResource;
use App\Http\Resources\Auction\PublicAuctionResource;
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
use App\Services\Auction\Actions\ReviewAuctionAction;
use App\Services\Auction\Actions\ReviewPaymentSubmissionAction;
use App\Services\Auction\Actions\SubmitPaymentSubmissionAction;
use App\Services\Auction\Support\AuctionMediaService;
use Database\Factories\Auction\PaymentMethodFactory;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
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

        $refund = RefundTransaction::where('auction_id', $auction->id)->firstOrFail();
        $this->assertSame(RefundTransactionStatus::Pending, $refund->status);
        $this->assertSame(25_000, $refund->amount_minor);
    }

    public function test_exact_deposit_coverage_does_not_create_excess_refund(): void
    {
        [$auction] = $this->auctionWithBid(100_000, 100_000);

        $auction = app(FinalizeAuctionAction::class)->execute($auction);
        $settlement = $auction->settlement;

        $this->assertSame(AuctionStatus::HandoverPending, $auction->status);
        $this->assertSame(SettlementStatus::Paid, $settlement->status);
        $this->assertSame(100_000, $settlement->deposit_applied_minor);
        $this->assertSame(0, $settlement->amount_due_minor);
        $this->assertSame(0, $settlement->remaining_amount_minor);
        $this->assertSame(0, RefundTransaction::where('auction_id', $auction->id)->count());
    }

    public function test_zero_deposit_keeps_full_winning_amount_due(): void
    {
        [$auction] = $this->auctionWithBid(100_000, 0);

        $auction = app(FinalizeAuctionAction::class)->execute($auction);
        $settlement = $auction->settlement;

        $this->assertSame(AuctionStatus::PaymentPending, $auction->status);
        $this->assertSame(SettlementStatus::PaymentPending, $settlement->status);
        $this->assertSame(0, $settlement->deposit_applied_minor);
        $this->assertSame(100_000, $settlement->amount_due_minor);
        $this->assertSame(0, $settlement->amount_paid_minor);
        $this->assertSame(100_000, $settlement->remaining_amount_minor);
    }

    public function test_winner_settlement_overpayment_is_rejected_on_approval(): void
    {
        [$auction, $winner] = $this->auctionWithBid(100_000, 10_000);

        $auction = app(FinalizeAuctionAction::class)->execute($auction);
        $settlement = $auction->settlement;
        $method = PaymentMethodFactory::new()->create();
        $submission = PaymentSubmission::create([
            'auction_id' => $auction->id,
            'settlement_id' => $settlement->id,
            'user_id' => $winner->id,
            'payment_method_id' => $method->id,
            'purpose' => PaymentPurpose::WinnerSettlement,
            'status' => PaymentSubmissionStatus::PendingReview,
            'amount_minor' => 90_001,
            'currency_code' => $auction->currency_code,
            'receipt_disk' => 'spaces_private',
            'receipt_path' => 'winner-overpayment.pdf',
            'receipt_mime_type' => 'application/pdf',
            'receipt_size_bytes' => 100,
            'idempotency_key' => 'winner-overpayment-'.Str::ulid(),
            'submitted_at' => Carbon::now(),
        ]);

        try {
            app(ReviewPaymentSubmissionAction::class)->approve($submission, $this->user('admin')->id, 'approved');
            $this->fail('Overpayment approval should have failed.');
        } catch (AuctionException $exception) {
            $this->assertSame(__('auction.errors.payment_amount_exceeds_remaining'), $exception->getMessage());
        }

        $this->assertSame(PaymentSubmissionStatus::PendingReview, $submission->refresh()->status);
        $this->assertSame(SettlementStatus::PaymentPending, $settlement->refresh()->status);
        $this->assertSame(0, $settlement->amount_paid_minor);
        $this->assertSame(90_000, $settlement->remaining_amount_minor);
        $this->assertSame(0, PaymentTransaction::where('payment_submission_id', $submission->id)->count());
    }

    public function test_zero_payment_submission_is_rejected_on_approval(): void
    {
        [$auction, $winner] = $this->auctionWithBid(100_000, 10_000);

        $auction = app(FinalizeAuctionAction::class)->execute($auction);
        $settlement = $auction->settlement;
        $method = PaymentMethodFactory::new()->create();
        $submission = PaymentSubmission::create([
            'auction_id' => $auction->id,
            'settlement_id' => $settlement->id,
            'user_id' => $winner->id,
            'payment_method_id' => $method->id,
            'purpose' => PaymentPurpose::WinnerSettlement,
            'status' => PaymentSubmissionStatus::PendingReview,
            'amount_minor' => 0,
            'currency_code' => $auction->currency_code,
            'receipt_disk' => 'spaces_private',
            'receipt_path' => 'winner-zero-payment.pdf',
            'receipt_mime_type' => 'application/pdf',
            'receipt_size_bytes' => 100,
            'idempotency_key' => 'winner-zero-payment-'.Str::ulid(),
            'submitted_at' => Carbon::now(),
        ]);

        try {
            app(ReviewPaymentSubmissionAction::class)->approve($submission, $this->user('admin')->id, 'approved');
            $this->fail('Zero payment approval should have failed.');
        } catch (AuctionException $exception) {
            $this->assertSame(__('auction.errors.zero_payment_not_allowed'), $exception->getMessage());
        }

        $this->assertSame(PaymentSubmissionStatus::PendingReview, $submission->refresh()->status);
        $this->assertSame(SettlementStatus::PaymentPending, $settlement->refresh()->status);
        $this->assertSame(0, $settlement->amount_paid_minor);
        $this->assertSame(90_000, $settlement->remaining_amount_minor);
        $this->assertSame(0, PaymentTransaction::where('payment_submission_id', $submission->id)->count());
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
            'seller-deposit-2',
            'user-supplied-reference'
        );

        $review->approve($second, $admin->id, 'approved');

        $this->assertSame(AuctionDepositStatus::Held, $second->deposit->refresh()->status);
        $this->assertSame(2, PaymentSubmission::where('auction_id', $auction->id)->count());
        $this->assertSame(
            "manual:submission:{$second->id}:approved",
            PaymentTransaction::where('payment_submission_id', $second->id)->firstOrFail()->provider_transaction_id
        );
    }

    public function test_approved_auction_with_zero_seller_deposit_goes_directly_to_scheduled(): void
    {
        [$auction] = $this->auctionWithoutBids(AuctionStatus::PendingReview);
        $auction->forceFill(['seller_deposit_amount_minor' => 0])->save();
        $admin = $this->user('admin');

        $approved = app(ReviewAuctionAction::class)->approve($auction, $admin->id, 'approved');

        $this->assertSame(AuctionStatus::Scheduled, $approved->status);
        $this->assertNotNull($approved->published_at);
    }

    public function test_approved_auction_with_required_seller_deposit_waits_for_deposit(): void
    {
        [$auction] = $this->auctionWithoutBids(AuctionStatus::PendingReview);
        $auction->forceFill(['seller_deposit_amount_minor' => 2_000])->save();
        $admin = $this->user('admin');

        $approved = app(ReviewAuctionAction::class)->approve($auction, $admin->id, 'approved');

        $this->assertSame(AuctionStatus::AwaitingSellerDeposit, $approved->status);
        $this->assertNull($approved->published_at);
    }

    public function test_duplicate_provider_transaction_id_is_rejected_for_different_submissions(): void
    {
        [$firstAuction, $firstSeller] = $this->auctionWithoutBids(AuctionStatus::AwaitingSellerDeposit);
        [$secondAuction, $secondSeller] = $this->auctionWithoutBids(AuctionStatus::AwaitingSellerDeposit);
        $method = PaymentMethodFactory::new()->create();
        $admin = $this->user('admin');
        $review = app(ReviewPaymentSubmissionAction::class);
        $providerTransactionId = 'provider-txn-'.Str::ulid();

        $first = $this->pendingSellerDepositSubmission($firstAuction, $firstSeller->id, $method->id);
        $second = $this->pendingSellerDepositSubmission($secondAuction, $secondSeller->id, $method->id);

        $review->approve($first, $admin->id, 'approved', $providerTransactionId);

        $this->expectException(AuctionException::class);
        $this->expectExceptionMessage(__('auction.errors.duplicate_provider_transaction'));

        $review->approve($second, $admin->id, 'approved', $providerTransactionId);
    }

    public function test_payment_approval_is_rejected_after_auction_cancelled(): void
    {
        [$auction, $seller] = $this->auctionWithoutBids(AuctionStatus::AwaitingSellerDeposit);
        $method = PaymentMethodFactory::new()->create();
        $admin = $this->user('admin');
        $submission = $this->pendingSellerDepositSubmission($auction, $seller->id, $method->id);

        app(CancelAuctionAction::class)->execute($auction, $admin->id, 'admin', 'cancel before payment approval');

        $this->expectException(AuctionException::class);
        $this->expectExceptionMessage(__('auction.errors.payment_approval_auction_not_active'));

        app(ReviewPaymentSubmissionAction::class)->approve($submission, $admin->id, 'approved');
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
        $this->assertSame(OutboxStatus::Pending, $message->refresh()->status);
        $this->assertNotNull($message->last_error);
        $this->assertNotNull($message->next_retry_at);
        $this->assertTrue($message->available_at->greaterThan(Carbon::now()));
    }

    public function test_outbox_failure_after_max_attempts_moves_to_dead_letter(): void
    {
        config(['auction.outbox.max_attempts' => 3]);
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
            'attempts' => 2,
            'available_at' => Carbon::now()->subMinute(),
        ]);

        $processed = app(DispatchOutboxMessagesAction::class)->execute(1);

        $this->assertSame(0, $processed);
        $this->assertSame(OutboxStatus::DeadLetter, $message->refresh()->status);
        $this->assertSame(3, $message->attempts);
        $this->assertNotNull($message->dead_lettered_at);
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

    public function test_cancellation_is_allowed_from_required_lifecycle_states(): void
    {
        $admin = $this->user('admin');
        $action = app(CancelAuctionAction::class);

        foreach ([
            AuctionStatus::Draft,
            AuctionStatus::PendingReview,
            AuctionStatus::AwaitingSellerDeposit,
            AuctionStatus::Scheduled,
            AuctionStatus::Live,
            AuctionStatus::Ended,
            AuctionStatus::PaymentPending,
            AuctionStatus::HandoverPending,
        ] as $status) {
            [$auction] = $this->auctionWithoutBids($status);

            $cancelled = $action->execute($auction, $admin->id, 'admin', "cancel from {$status->value}");

            $this->assertSame(AuctionStatus::Cancelled, $cancelled->status);
            $this->assertNotNull($cancelled->cancelled_at);
        }
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

    public function test_public_auction_resource_does_not_expose_private_financial_data(): void
    {
        [$auction] = $this->auctionWithBid(100_000, 10_000);
        $auction = app(FinalizeAuctionAction::class)->execute($auction);
        $auction->load(['media', 'category', 'country', 'state', 'city', 'metric', 'currentLeadingBid', 'settlement']);

        $payload = (new PublicAuctionResource($auction))->toArray(Request::create('/'));

        $this->assertArrayNotHasKey('reserve_amount', $payload);
        $this->assertArrayNotHasKey('settlement', $payload);
        $this->assertStringNotContainsString('seller_net', json_encode($payload, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('platform_fee', json_encode($payload, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('amount_due', json_encode($payload, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('amount_paid', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    public function test_public_bid_resource_anonymizes_other_bidders(): void
    {
        [$auction, $bidder, $participant, $bid] = $this->auctionWithBid(100_000, 10_000);
        $bid->load(['auction', 'bidder']);

        $payload = (new AuctionBidResource($bid))->toArray(Request::create('/'));

        $this->assertSame(['anonymous' => true], $payload['bidder']);
        $this->assertStringNotContainsString($bidder->email, json_encode($payload, JSON_THROW_ON_ERROR));
        $this->assertArrayNotHasKey('id', $payload['bidder']);
        $this->assertArrayNotHasKey('name', $payload['bidder']);
    }

    public function test_public_bid_history_rejects_non_public_auction(): void
    {
        [$auction] = $this->auctionWithBid(100_000, 10_000);
        $auction->forceFill(['status' => AuctionStatus::Draft])->save();

        $this->getJson("/api/auctions/{$auction->public_id}/bids")->assertForbidden();
    }

    public function test_public_bid_history_for_live_auction_anonymizes_bidders(): void
    {
        [$auction] = $this->auctionWithBid(100_000, 10_000);
        $auction->forceFill(['status' => AuctionStatus::Live])->save();

        $response = $this->getJson("/api/auctions/{$auction->public_id}/bids")->assertOk();

        $this->assertSame(['anonymous' => true], $response->json('data.0.bidder'));
    }

    public function test_unauthorized_user_cannot_access_another_payment_receipt_url(): void
    {
        [$auction, $seller] = $this->auctionWithoutBids(AuctionStatus::AwaitingSellerDeposit);
        $method = PaymentMethodFactory::new()->create();
        $submission = PaymentSubmission::create([
            'auction_id' => $auction->id,
            'user_id' => $seller->id,
            'payment_method_id' => $method->id,
            'purpose' => PaymentPurpose::SellerDeposit,
            'status' => PaymentSubmissionStatus::PendingReview,
            'amount_minor' => 2_000,
            'currency_code' => $auction->currency_code,
            'receipt_disk' => 'spaces_private',
            'receipt_path' => 'private/receipt.pdf',
            'receipt_mime_type' => 'application/pdf',
            'receipt_size_bytes' => 100,
            'idempotency_key' => 'receipt-private-'.uniqid(),
            'submitted_at' => Carbon::now(),
        ]);

        $this->actingAs($this->user(), 'sanctum')
            ->getJson("/api/soom/payment-submissions/{$submission->public_id}/receipt-url")
            ->assertForbidden();
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

    private function pendingSellerDepositSubmission(Auction $auction, int $sellerId, int $paymentMethodId): PaymentSubmission
    {
        $deposit = AuctionDeposit::create([
            'auction_id' => $auction->id,
            'user_id' => $sellerId,
            'type' => 'seller',
            'status' => AuctionDepositStatus::PendingReview,
            'required_amount_minor' => $auction->seller_deposit_amount_minor,
            'held_amount_minor' => 0,
            'currency_code' => $auction->currency_code,
        ]);

        return PaymentSubmission::create([
            'auction_id' => $auction->id,
            'deposit_id' => $deposit->id,
            'user_id' => $sellerId,
            'payment_method_id' => $paymentMethodId,
            'purpose' => PaymentPurpose::SellerDeposit,
            'status' => PaymentSubmissionStatus::PendingReview,
            'amount_minor' => $auction->seller_deposit_amount_minor,
            'currency_code' => $auction->currency_code,
            'receipt_disk' => 'spaces_private',
            'receipt_path' => 'seller-deposit.pdf',
            'receipt_mime_type' => 'application/pdf',
            'receipt_size_bytes' => 100,
            'idempotency_key' => 'seller-deposit-'.Str::ulid(),
            'submitted_at' => Carbon::now(),
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
        $unique = strtolower((string) Str::ulid());
        $phoneSuffix = str_pad((string) (abs(crc32($unique)) % 10_000_000), 7, '0', STR_PAD_LEFT);

        return User::create([
            'name' => fake()->name(),
            'email' => "auction-{$unique}@example.test",
            'phone' => '+96279'.$phoneSuffix,
            'password' => Hash::make('password'),
            'role' => $role,
            'email_verified_at' => Carbon::now(),
        ]);
    }
}
