<?php

declare(strict_types=1);

namespace Tests\Feature\Auction;

use App\Domain\Auction\Enums\AuctionDepositStatus;
use App\Domain\Auction\Enums\AuctionStatus;
use App\Domain\Auction\Enums\PaymentPurpose;
use App\Domain\Auction\Enums\PaymentSubmissionStatus;
use App\Domain\Auction\Enums\PaymentTransactionStatus;
use App\Domain\Auction\Exceptions\AuctionException;
use App\Models\Auction\AuctionDeposit;
use App\Models\Auction\PaymentSubmission;
use App\Models\Auction\PaymentTransaction;
use App\Services\Auction\Actions\CreatePaymentIntentAction;
use App\Services\Auction\Actions\ReviewPaymentSubmissionAction;
use App\Services\Auction\Actions\SubmitPaymentSubmissionAction;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Auction\Concerns\BuildsOnlinePaymentFixtures;
use Tests\TestCase;

final class OnlineAndManualPaymentCoexistenceTest extends TestCase
{
    use BuildsOnlinePaymentFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate', ['--force' => true]);
        $this->enableFakeProvider();
        Storage::fake('spaces_private');
    }

    public function test_manual_approval_and_online_success_produce_the_same_applied_state(): void
    {
        [$manualAuction] = $this->paymentAuction(AuctionStatus::Live);
        [$manualBidder] = $this->registeredBidder($manualAuction);
        $admin = $this->paymentUser('admin');

        $submission = app(SubmitPaymentSubmissionAction::class)->execute(
            $manualAuction,
            $manualBidder->id,
            PaymentPurpose::BidderDeposit,
            $this->manualPaymentMethod()->public_id,
            UploadedFile::fake()->create('receipt.pdf', 10, 'application/pdf'),
            'manual-'.$manualBidder->id
        );

        app(ReviewPaymentSubmissionAction::class)->approve($submission, $admin->id, 'approved');

        [$onlineAuction] = $this->paymentAuction(AuctionStatus::Live);
        [$onlineBidder] = $this->registeredBidder($onlineAuction);
        $provider = $this->enableFakeProvider();

        $transaction = app(CreatePaymentIntentAction::class)
            ->execute($onlineAuction, $onlineBidder->id, PaymentPurpose::BidderDeposit, $this->onlinePaymentMethod()->public_id);
        $provider->markSucceeded((string) $transaction->provider_transaction_id);
        $this->postWebhook($transaction)->assertOk();

        $manualDeposit = AuctionDeposit::where('auction_id', $manualAuction->id)->where('user_id', $manualBidder->id)->firstOrFail();
        $onlineDeposit = AuctionDeposit::where('auction_id', $onlineAuction->id)->where('user_id', $onlineBidder->id)->firstOrFail();

        $this->assertSame(AuctionDepositStatus::Held, $manualDeposit->status);
        $this->assertSame(AuctionDepositStatus::Held, $onlineDeposit->status);
        $this->assertSame((int) $manualDeposit->held_amount_minor, (int) $onlineDeposit->held_amount_minor);

        $manualTransaction = PaymentTransaction::where('auction_id', $manualAuction->id)->firstOrFail();
        $this->assertSame('manual', $manualTransaction->provider);
        $this->assertNotNull($manualTransaction->payment_submission_id);
        $this->assertSame("deposit:{$manualDeposit->id}", $manualTransaction->successful_obligation_key);

        $this->assertSame('fake', $transaction->refresh()->provider);
        $this->assertNull($transaction->payment_submission_id);
        $this->assertSame("deposit:{$onlineDeposit->id}", $transaction->successful_obligation_key);
    }

    public function test_a_paid_online_deposit_blocks_a_later_manual_submission(): void
    {
        [$auction] = $this->paymentAuction(AuctionStatus::Live);
        [$bidder] = $this->registeredBidder($auction);
        $provider = $this->enableFakeProvider();

        $transaction = app(CreatePaymentIntentAction::class)
            ->execute($auction, $bidder->id, PaymentPurpose::BidderDeposit, $this->onlinePaymentMethod()->public_id);
        $provider->markSucceeded((string) $transaction->provider_transaction_id);
        $this->postWebhook($transaction)->assertOk();

        try {
            app(SubmitPaymentSubmissionAction::class)->execute(
                $auction,
                $bidder->id,
                PaymentPurpose::BidderDeposit,
                $this->manualPaymentMethod()->public_id,
                UploadedFile::fake()->create('receipt.pdf', 10, 'application/pdf'),
                'manual-after-online'
            );
            $this->fail('A manual submission must not be accepted for a paid obligation.');
        } catch (AuctionException $exception) {
            $this->assertSame('payment_obligation_already_paid', $exception->getErrorCode());
        }

        $this->assertSame(0, PaymentSubmission::where('auction_id', $auction->id)->count());
    }

    public function test_a_pending_online_intent_does_not_block_the_manual_lane(): void
    {
        [$auction] = $this->paymentAuction(AuctionStatus::Live);
        [$bidder] = $this->registeredBidder($auction);
        $admin = $this->paymentUser('admin');

        app(CreatePaymentIntentAction::class)
            ->execute($auction, $bidder->id, PaymentPurpose::BidderDeposit, $this->onlinePaymentMethod()->public_id);

        $submission = app(SubmitPaymentSubmissionAction::class)->execute(
            $auction,
            $bidder->id,
            PaymentPurpose::BidderDeposit,
            $this->manualPaymentMethod()->public_id,
            UploadedFile::fake()->create('receipt.pdf', 10, 'application/pdf'),
            'manual-while-pending'
        );

        $this->assertSame(PaymentSubmissionStatus::PendingReview, $submission->status);

        app(ReviewPaymentSubmissionAction::class)->approve($submission->refresh(), $admin->id, 'approved');

        $this->assertSame(
            AuctionDepositStatus::Held,
            AuctionDeposit::where('auction_id', $auction->id)->where('user_id', $bidder->id)->firstOrFail()->status
        );
        $this->assertSame(
            1,
            PaymentTransaction::where('auction_id', $auction->id)->where('status', PaymentTransactionStatus::Succeeded->value)->count()
        );
    }

    public function test_an_online_payment_arriving_after_a_manual_approval_is_refunded_not_applied_twice(): void
    {
        [$auction] = $this->paymentAuction(AuctionStatus::Live);
        [$bidder] = $this->registeredBidder($auction);
        $admin = $this->paymentUser('admin');
        $provider = $this->enableFakeProvider();

        $transaction = app(CreatePaymentIntentAction::class)
            ->execute($auction, $bidder->id, PaymentPurpose::BidderDeposit, $this->onlinePaymentMethod()->public_id);

        $submission = app(SubmitPaymentSubmissionAction::class)->execute(
            $auction,
            $bidder->id,
            PaymentPurpose::BidderDeposit,
            $this->manualPaymentMethod()->public_id,
            UploadedFile::fake()->create('receipt.pdf', 10, 'application/pdf'),
            'manual-race'
        );
        app(ReviewPaymentSubmissionAction::class)->approve($submission->refresh(), $admin->id, 'approved');

        $provider->markSucceeded((string) $transaction->provider_transaction_id);
        $this->postWebhook($transaction)->assertOk();

        $transaction->refresh();
        $this->assertSame(PaymentTransactionStatus::Succeeded, $transaction->status);
        $this->assertNull($transaction->successful_obligation_key);

        $deposit = AuctionDeposit::where('auction_id', $auction->id)->where('user_id', $bidder->id)->firstOrFail();
        $this->assertSame(AuctionDepositStatus::Held, $deposit->status);
        $this->assertSame(1_000, (int) $deposit->held_amount_minor);

        $this->assertSame(
            1,
            \App\Models\Auction\RefundTransaction::where('payment_transaction_id', $transaction->id)
                ->where('reason', 'obligation_no_longer_payable')
                ->count()
        );
    }

    private function postWebhook(PaymentTransaction $transaction)
    {
        $payload = [
            'event_id' => 'evt-'.$transaction->public_id,
            'event_type' => 'payment.succeeded',
            'provider_transaction_id' => (string) $transaction->refresh()->provider_transaction_id,
        ];

        return $this->postJson('/api/webhooks/payments/fake', $payload, [
            'X-Fake-Signature' => hash_hmac('sha256', json_encode($payload), 'test-webhook-secret'),
        ]);
    }
}
