<?php

declare(strict_types=1);

namespace Tests\Feature\Auction;

use App\Domain\Auction\Enums\AuctionDepositStatus;
use App\Domain\Auction\Enums\AuctionStatus;
use App\Domain\Auction\Enums\PaymentPurpose;
use App\Domain\Auction\Enums\PaymentTransactionStatus;
use App\Domain\Auction\Enums\RefundTransactionStatus;
use App\Models\Auction\AuctionDeposit;
use App\Models\Auction\PaymentTransaction;
use App\Models\Auction\RefundTransaction;
use App\Services\Auction\Actions\CreatePaymentIntentAction;
use App\Services\Auction\Actions\ProcessAuctionRefundAction;
use App\Services\Auction\Actions\RefundAuctionDepositAction;
use App\Services\Auction\Support\AuctionRefundCompletion;
use Illuminate\Support\Facades\Artisan;
use Tests\Feature\Auction\Concerns\BuildsOnlinePaymentFixtures;
use Tests\TestCase;

final class WinnerPaymentRefundTest extends TestCase
{
    use BuildsOnlinePaymentFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate', ['--force' => true]);
        $this->enableFakeProvider();
    }

    public function test_a_winner_payment_refund_completes_without_a_deposit_allocation(): void
    {
        [$auction] = $this->paymentAuction(AuctionStatus::PaymentPending);
        [$winner, $settlement] = $this->winnerSettlement($auction);
        $provider = $this->enableFakeProvider();

        $transaction = app(CreatePaymentIntentAction::class)
            ->execute($auction, $winner->id, PaymentPurpose::WinnerSettlement, $this->onlinePaymentMethod()->public_id);
        $provider->markSucceeded((string) $transaction->provider_transaction_id);
        $this->postWebhook($transaction)->assertOk();

        $refund = RefundTransaction::create([
            'auction_id' => $auction->id,
            'deposit_id' => null,
            'payment_transaction_id' => $transaction->id,
            'obligation_type' => 'settlement',
            'obligation_id' => $settlement->id,
            'user_id' => $winner->id,
            'status' => RefundTransactionStatus::Pending,
            'amount_minor' => 100_000,
            'held_refund_amount_minor' => 0,
            'applied_refund_amount_minor' => 0,
            'currency_code' => 'JOD',
            'reason' => 'auction_cancelled',
            'provider' => 'fake',
            'idempotency_key' => 'winner-payment-refund-1',
        ]);

        $completed = app(AuctionRefundCompletion::class)->completeSucceeded($refund, 'fake_refund_manual_1');

        $this->assertSame(RefundTransactionStatus::Succeeded, $completed->status);
        $this->assertSame(100_000, (int) $completed->amount_minor);
        $this->assertSame(PaymentTransactionStatus::Succeeded, $transaction->refresh()->status);
    }

    public function test_a_winner_payment_refund_is_processed_end_to_end_by_the_provider(): void
    {
        [$auction] = $this->paymentAuction(AuctionStatus::PaymentPending);
        [$winner, $settlement] = $this->winnerSettlement($auction);
        $provider = $this->enableFakeProvider();

        $transaction = app(CreatePaymentIntentAction::class)
            ->execute($auction, $winner->id, PaymentPurpose::WinnerSettlement, $this->onlinePaymentMethod()->public_id);
        $provider->markSucceeded((string) $transaction->provider_transaction_id);
        $this->postWebhook($transaction)->assertOk();

        $refund = RefundTransaction::create([
            'auction_id' => $auction->id,
            'deposit_id' => null,
            'payment_transaction_id' => $transaction->id,
            'obligation_type' => 'settlement',
            'obligation_id' => $settlement->id,
            'user_id' => $winner->id,
            'status' => RefundTransactionStatus::Pending,
            'amount_minor' => 100_000,
            'held_refund_amount_minor' => 0,
            'applied_refund_amount_minor' => 0,
            'currency_code' => 'JOD',
            'reason' => 'auction_cancelled',
            'provider' => 'fake',
            'idempotency_key' => 'winner-payment-refund-2',
        ]);

        $processed = app(ProcessAuctionRefundAction::class)->execute($refund);

        $this->assertSame(RefundTransactionStatus::Succeeded, $processed->status);
        $this->assertNotNull($processed->provider_refund_id);
    }

    public function test_a_refund_larger_than_the_captured_payment_is_still_refused(): void
    {
        [$auction] = $this->paymentAuction(AuctionStatus::PaymentPending);
        [$winner, $settlement] = $this->winnerSettlement($auction);
        $provider = $this->enableFakeProvider();

        $transaction = app(CreatePaymentIntentAction::class)
            ->execute($auction, $winner->id, PaymentPurpose::WinnerSettlement, $this->onlinePaymentMethod()->public_id);
        $provider->markSucceeded((string) $transaction->provider_transaction_id);
        $this->postWebhook($transaction)->assertOk();

        $refund = RefundTransaction::create([
            'auction_id' => $auction->id,
            'deposit_id' => null,
            'payment_transaction_id' => $transaction->id,
            'obligation_type' => 'settlement',
            'obligation_id' => $settlement->id,
            'user_id' => $winner->id,
            'status' => RefundTransactionStatus::Pending,
            'amount_minor' => 150_000,
            'held_refund_amount_minor' => 0,
            'applied_refund_amount_minor' => 0,
            'currency_code' => 'JOD',
            'reason' => 'auction_cancelled',
            'provider' => 'fake',
            'idempotency_key' => 'winner-payment-refund-3',
        ]);

        $this->expectException(\App\Domain\Auction\Exceptions\AuctionException::class);

        app(AuctionRefundCompletion::class)->completeSucceeded($refund, 'fake_refund_manual_3');
    }

    public function test_deposit_refunds_still_decrement_the_deposit_balances(): void
    {
        [$auction] = $this->paymentAuction(AuctionStatus::Live);
        [$bidder] = $this->registeredBidder($auction);
        $provider = $this->enableFakeProvider();

        $transaction = app(CreatePaymentIntentAction::class)
            ->execute($auction, $bidder->id, PaymentPurpose::BidderDeposit, $this->onlinePaymentMethod()->public_id);
        $provider->markSucceeded((string) $transaction->provider_transaction_id);
        $this->postWebhook($transaction)->assertOk();

        $deposit = AuctionDeposit::where('auction_id', $auction->id)->where('user_id', $bidder->id)->firstOrFail();
        $this->assertSame(1_000, (int) $deposit->held_amount_minor);

        $refund = app(RefundAuctionDepositAction::class)->execute($deposit, 'regression_check');
        $completed = app(ProcessAuctionRefundAction::class)->execute($refund);

        $this->assertSame(RefundTransactionStatus::Succeeded, $completed->status);

        $deposit->refresh();
        $this->assertSame(AuctionDepositStatus::Refunded, $deposit->status);
        $this->assertSame(0, (int) $deposit->held_amount_minor);
        $this->assertSame(1_000, (int) $deposit->refunded_amount_minor);
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
