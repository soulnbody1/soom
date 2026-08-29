<?php

declare(strict_types=1);

namespace Tests\Feature\Auction;

use App\Domain\Auction\Enums\AuctionDepositStatus;
use App\Domain\Auction\Enums\AuctionParticipantStatus;
use App\Domain\Auction\Enums\AuctionStatus;
use App\Domain\Auction\Enums\PaymentPurpose;
use App\Domain\Auction\Enums\PaymentTransactionStatus;
use App\Domain\Auction\Enums\RefundTransactionStatus;
use App\Domain\Auction\Enums\SettlementStatus;
use App\Domain\Auction\Exceptions\AuctionException;
use App\Models\Auction\AuctionDeposit;
use App\Models\Auction\PaymentProviderEvent;
use App\Models\Auction\PaymentTransaction;
use App\Models\Auction\RefundTransaction;
use App\Services\Auction\Actions\CreatePaymentIntentAction;
use App\Services\Auction\Actions\RefreshOnlinePaymentAction;
use App\Services\Auction\Actions\SettleOnlinePaymentAction;
use App\Services\Auction\Payments\ProviderPaymentStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\Feature\Auction\Concerns\BuildsOnlinePaymentFixtures;
use Tests\TestCase;

final class OnlinePaymentLifecycleTest extends TestCase
{
    use BuildsOnlinePaymentFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate', ['--force' => true]);
        $this->enableFakeProvider();
    }

    public function test_bidder_deposit_intent_is_created_pending_without_touching_the_obligation(): void
    {
        [$auction] = $this->paymentAuction(AuctionStatus::Live);
        [$bidder, $participant] = $this->registeredBidder($auction);
        $method = $this->onlinePaymentMethod();

        $transaction = app(CreatePaymentIntentAction::class)
            ->execute($auction, $bidder->id, PaymentPurpose::BidderDeposit, $method->public_id);

        $this->assertSame(PaymentTransactionStatus::Pending, $transaction->status);
        $this->assertNull($transaction->payment_submission_id);
        $this->assertNull($transaction->successful_obligation_key);
        $this->assertSame(1_000, (int) $transaction->amount_minor);
        $this->assertSame('JOD', $transaction->currency_code);
        $this->assertNotNull($transaction->provider_transaction_id);
        $this->assertNotNull($transaction->checkout_instruction['redirect_url']);

        $this->assertSame(
            AuctionDepositStatus::PendingSubmission,
            AuctionDeposit::where('auction_id', $auction->id)->where('user_id', $bidder->id)->firstOrFail()->status
        );
        $this->assertSame(AuctionParticipantStatus::Registered, $participant->refresh()->status);
    }

    public function test_duplicate_pay_click_reuses_the_pending_intent(): void
    {
        [$auction] = $this->paymentAuction(AuctionStatus::Live);
        [$bidder] = $this->registeredBidder($auction);
        $method = $this->onlinePaymentMethod();
        $action = app(CreatePaymentIntentAction::class);

        $first = $action->execute($auction, $bidder->id, PaymentPurpose::BidderDeposit, $method->public_id);
        $second = $action->execute($auction, $bidder->id, PaymentPurpose::BidderDeposit, $method->public_id);

        $this->assertSame($first->id, $second->id);
        $this->assertSame($first->provider_transaction_id, $second->provider_transaction_id);
        $this->assertSame(1, PaymentTransaction::where('auction_id', $auction->id)->count());
    }

    public function test_successful_webhook_qualifies_the_bidder_once(): void
    {
        [$auction] = $this->paymentAuction(AuctionStatus::Live);
        [$bidder, $participant] = $this->registeredBidder($auction);
        $method = $this->onlinePaymentMethod();
        $provider = $this->enableFakeProvider();

        $transaction = app(CreatePaymentIntentAction::class)
            ->execute($auction, $bidder->id, PaymentPurpose::BidderDeposit, $method->public_id);

        $provider->markSucceeded((string) $transaction->provider_transaction_id, providerFeeMinor: 25, settlementReference: 'BATCH-1');

        $first = $this->postWebhook($transaction, 'payment.succeeded');
        $first->assertOk()->assertJsonPath('data.result', 'processed');

        $transaction->refresh();
        $this->assertSame(PaymentTransactionStatus::Succeeded, $transaction->status);
        $this->assertSame("deposit:{$this->depositId($auction->id, $bidder->id)}", $transaction->successful_obligation_key);
        $this->assertSame(25, (int) $transaction->provider_fee_minor);
        $this->assertSame('BATCH-1', $transaction->settlement_reference);

        $deposit = AuctionDeposit::where('auction_id', $auction->id)->where('user_id', $bidder->id)->firstOrFail();
        $this->assertSame(AuctionDepositStatus::Held, $deposit->status);
        $this->assertSame(1_000, (int) $deposit->held_amount_minor);
        $this->assertSame(AuctionParticipantStatus::Qualified, $participant->refresh()->status);

        $second = $this->postWebhook($transaction, 'payment.succeeded');
        $second->assertOk()->assertJsonPath('data.result', 'duplicate');

        $this->assertSame(1, PaymentTransaction::where('auction_id', $auction->id)->count());
        $this->assertSame(1, PaymentProviderEvent::where('provider', 'fake')->count());
    }

    public function test_declined_payment_leaves_the_obligation_unpaid_and_allows_a_new_attempt(): void
    {
        [$auction] = $this->paymentAuction(AuctionStatus::Live);
        [$bidder] = $this->registeredBidder($auction);
        $method = $this->onlinePaymentMethod();
        $provider = $this->enableFakeProvider();
        $action = app(CreatePaymentIntentAction::class);

        $transaction = $action->execute($auction, $bidder->id, PaymentPurpose::BidderDeposit, $method->public_id);
        $provider->markFailed((string) $transaction->provider_transaction_id, 'card_declined');
        $this->postWebhook($transaction, 'payment.failed')->assertOk();

        $transaction->refresh();
        $this->assertSame(PaymentTransactionStatus::Failed, $transaction->status);
        $this->assertSame('card_declined', $transaction->failure_code);
        $this->assertNull($transaction->successful_obligation_key);

        $retry = $action->execute($auction, $bidder->id, PaymentPurpose::BidderDeposit, $method->public_id);
        $this->assertNotSame($transaction->id, $retry->id);
        $this->assertSame(PaymentTransactionStatus::Pending, $retry->status);
    }

    public function test_return_before_webhook_settles_through_a_server_side_status_check(): void
    {
        [$auction] = $this->paymentAuction(AuctionStatus::Live);
        [$bidder] = $this->registeredBidder($auction);
        $method = $this->onlinePaymentMethod();
        $provider = $this->enableFakeProvider();

        $transaction = app(CreatePaymentIntentAction::class)
            ->execute($auction, $bidder->id, PaymentPurpose::BidderDeposit, $method->public_id);
        $provider->markSucceeded((string) $transaction->provider_transaction_id);

        $response = $this->actingAs($bidder)->getJson("/api/soom/payments/{$transaction->public_id}");

        $response->assertOk()->assertJsonPath('data.status', 'succeeded');
        $this->assertSame(AuctionDepositStatus::Held, AuctionDeposit::where('auction_id', $auction->id)->where('user_id', $bidder->id)->firstOrFail()->status);
    }

    public function test_webhook_before_return_is_authoritative_and_return_only_reads(): void
    {
        [$auction] = $this->paymentAuction(AuctionStatus::Live);
        [$bidder] = $this->registeredBidder($auction);
        $method = $this->onlinePaymentMethod();
        $provider = $this->enableFakeProvider();

        $transaction = app(CreatePaymentIntentAction::class)
            ->execute($auction, $bidder->id, PaymentPurpose::BidderDeposit, $method->public_id);
        $provider->markSucceeded((string) $transaction->provider_transaction_id);
        $this->postWebhook($transaction, 'payment.succeeded')->assertOk();

        $processedAt = $transaction->refresh()->processed_at;

        $this->actingAs($bidder)
            ->getJson("/api/soom/payments/{$transaction->public_id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'succeeded');

        $this->assertEquals($processedAt, $transaction->refresh()->processed_at);
    }

    public function test_invalid_signature_is_rejected_and_recorded_without_effect(): void
    {
        [$auction] = $this->paymentAuction(AuctionStatus::Live);
        [$bidder] = $this->registeredBidder($auction);
        $method = $this->onlinePaymentMethod();
        $provider = $this->enableFakeProvider();

        $transaction = app(CreatePaymentIntentAction::class)
            ->execute($auction, $bidder->id, PaymentPurpose::BidderDeposit, $method->public_id);
        $provider->markSucceeded((string) $transaction->provider_transaction_id);

        $payload = $this->webhookPayload($transaction, 'payment.succeeded');

        $this->postJson('/api/webhooks/payments/fake', $payload, ['X-Fake-Signature' => 'not-a-signature'])
            ->assertStatus(401);

        $this->assertSame(PaymentTransactionStatus::Pending, $transaction->refresh()->status);

        $event = PaymentProviderEvent::where('process_error', 'signature_invalid')->firstOrFail();
        $this->assertFalse($event->signature_verified);
        $this->assertStringStartsWith('unverified:', (string) $event->event_id);
    }

    public function test_a_forged_event_id_cannot_block_the_genuine_event(): void
    {
        [$auction] = $this->paymentAuction(AuctionStatus::Live);
        [$bidder] = $this->registeredBidder($auction);
        $method = $this->onlinePaymentMethod();
        $provider = $this->enableFakeProvider();

        $transaction = app(CreatePaymentIntentAction::class)
            ->execute($auction, $bidder->id, PaymentPurpose::BidderDeposit, $method->public_id);
        $provider->markSucceeded((string) $transaction->provider_transaction_id);

        $payload = $this->webhookPayload($transaction, 'payment.succeeded');

        $this->postJson('/api/webhooks/payments/fake', $payload, ['X-Fake-Signature' => 'forged'])
            ->assertStatus(401);

        $this->postWebhook($transaction, 'payment.succeeded')->assertOk()->assertJsonPath('data.result', 'processed');

        $this->assertSame(PaymentTransactionStatus::Succeeded, $transaction->refresh()->status);
        $this->assertSame(
            AuctionDepositStatus::Held,
            AuctionDeposit::where('auction_id', $auction->id)->where('user_id', $bidder->id)->firstOrFail()->status
        );
    }

    public function test_amount_mismatch_records_the_charge_without_applying_it(): void
    {
        [$auction] = $this->paymentAuction(AuctionStatus::Live);
        [$bidder] = $this->registeredBidder($auction);
        $method = $this->onlinePaymentMethod();
        $provider = $this->enableFakeProvider();

        $transaction = app(CreatePaymentIntentAction::class)
            ->execute($auction, $bidder->id, PaymentPurpose::BidderDeposit, $method->public_id);
        $provider->markSucceeded((string) $transaction->provider_transaction_id, amountMinor: 900);

        $this->postWebhook($transaction, 'payment.succeeded')->assertOk();

        $transaction->refresh();
        $this->assertSame(PaymentTransactionStatus::Succeeded, $transaction->status);
        $this->assertSame('amount_mismatch', $transaction->failure_code);
        $this->assertNull($transaction->successful_obligation_key);
        $this->assertSame(
            AuctionDepositStatus::PendingSubmission,
            AuctionDeposit::where('auction_id', $auction->id)->where('user_id', $bidder->id)->firstOrFail()->status
        );
    }

    public function test_currency_mismatch_records_the_charge_without_applying_it(): void
    {
        [$auction] = $this->paymentAuction(AuctionStatus::Live);
        [$bidder] = $this->registeredBidder($auction);
        $method = $this->onlinePaymentMethod();
        $provider = $this->enableFakeProvider();

        $transaction = app(CreatePaymentIntentAction::class)
            ->execute($auction, $bidder->id, PaymentPurpose::BidderDeposit, $method->public_id);
        $provider->markSucceeded((string) $transaction->provider_transaction_id, currencyCode: 'USD');

        $this->postWebhook($transaction, 'payment.succeeded')->assertOk();

        $transaction->refresh();
        $this->assertSame('currency_mismatch', $transaction->failure_code);
        $this->assertNull($transaction->successful_obligation_key);
    }

    public function test_provider_transaction_mismatch_is_refused(): void
    {
        [$auction] = $this->paymentAuction(AuctionStatus::Live);
        [$bidder] = $this->registeredBidder($auction);
        $method = $this->onlinePaymentMethod();

        $transaction = app(CreatePaymentIntentAction::class)
            ->execute($auction, $bidder->id, PaymentPurpose::BidderDeposit, $method->public_id);

        $this->expectException(AuctionException::class);

        app(SettleOnlinePaymentAction::class)->execute((int) $transaction->id, new ProviderPaymentStatus(
            status: PaymentTransactionStatus::Succeeded,
            providerTransactionId: 'fake_someone_elses_reference',
            amountMinor: 1_000,
            currencyCode: 'JOD',
        ));
    }

    public function test_out_of_order_event_cannot_move_a_settled_payment_backwards(): void
    {
        [$auction] = $this->paymentAuction(AuctionStatus::Live);
        [$bidder] = $this->registeredBidder($auction);
        $method = $this->onlinePaymentMethod();
        $provider = $this->enableFakeProvider();

        $transaction = app(CreatePaymentIntentAction::class)
            ->execute($auction, $bidder->id, PaymentPurpose::BidderDeposit, $method->public_id);
        $provider->markSucceeded((string) $transaction->provider_transaction_id);
        $this->postWebhook($transaction, 'payment.succeeded')->assertOk();

        $this->expectException(AuctionException::class);

        app(SettleOnlinePaymentAction::class)->execute((int) $transaction->id, new ProviderPaymentStatus(
            status: PaymentTransactionStatus::Pending,
            providerTransactionId: (string) $transaction->refresh()->provider_transaction_id,
        ));
    }

    public function test_winner_payment_succeeds_and_advances_the_auction(): void
    {
        [$auction] = $this->paymentAuction(AuctionStatus::PaymentPending);
        [$winner, $settlement] = $this->winnerSettlement($auction);
        $method = $this->onlinePaymentMethod();
        $provider = $this->enableFakeProvider();

        $transaction = app(CreatePaymentIntentAction::class)
            ->execute($auction, $winner->id, PaymentPurpose::WinnerSettlement, $method->public_id);

        $this->assertSame(100_000, (int) $transaction->amount_minor);

        $provider->markSucceeded((string) $transaction->provider_transaction_id);
        $this->postWebhook($transaction, 'payment.succeeded')->assertOk();

        $settlement->refresh();
        $this->assertSame(SettlementStatus::Paid, $settlement->status);
        $this->assertSame(100_000, (int) $settlement->amount_paid_minor);
        $this->assertSame(0, (int) $settlement->remaining_amount_minor);
        $this->assertNotNull($settlement->handover_due_at);
        $this->assertSame(AuctionStatus::HandoverPending, $auction->refresh()->status);
    }

    public function test_second_online_payment_for_a_paid_obligation_is_refused(): void
    {
        [$auction] = $this->paymentAuction(AuctionStatus::Live);
        [$bidder] = $this->registeredBidder($auction);
        $method = $this->onlinePaymentMethod();
        $provider = $this->enableFakeProvider();
        $action = app(CreatePaymentIntentAction::class);

        $transaction = $action->execute($auction, $bidder->id, PaymentPurpose::BidderDeposit, $method->public_id);
        $provider->markSucceeded((string) $transaction->provider_transaction_id);
        $this->postWebhook($transaction, 'payment.succeeded')->assertOk();

        $this->expectException(AuctionException::class);

        $action->execute($auction, $bidder->id, PaymentPurpose::BidderDeposit, $method->public_id);
    }

    public function test_late_success_records_the_payment_and_schedules_an_automatic_refund(): void
    {
        [$auction] = $this->paymentAuction(AuctionStatus::Live);
        [$bidder] = $this->registeredBidder($auction);
        $method = $this->onlinePaymentMethod();
        $provider = $this->enableFakeProvider();

        $transaction = app(CreatePaymentIntentAction::class)
            ->execute($auction, $bidder->id, PaymentPurpose::BidderDeposit, $method->public_id);

        $auction->forceFill([
            'ends_at' => Carbon::now()->subMinute(),
            'original_ends_at' => Carbon::now()->subMinute(),
        ])->save();

        $provider->markSucceeded((string) $transaction->provider_transaction_id);
        $this->postWebhook($transaction, 'payment.succeeded')->assertOk();

        $transaction->refresh();
        $this->assertSame(PaymentTransactionStatus::Succeeded, $transaction->status);
        $this->assertNull($transaction->successful_obligation_key);

        $this->assertSame(
            AuctionDepositStatus::PendingSubmission,
            AuctionDeposit::where('auction_id', $auction->id)->where('user_id', $bidder->id)->firstOrFail()->status
        );

        $refund = RefundTransaction::where('payment_transaction_id', $transaction->id)->firstOrFail();
        $this->assertSame(RefundTransactionStatus::Pending, $refund->status);
        $this->assertSame('obligation_no_longer_payable', $refund->reason);
        $this->assertSame(1_000, (int) $refund->amount_minor);
        $this->assertSame('fake', $refund->provider);
        $this->assertNull($refund->deposit_id);
    }

    public function test_stale_pending_payment_is_expired_only_after_a_provider_check(): void
    {
        [$auction] = $this->paymentAuction(AuctionStatus::Live);
        [$bidder] = $this->registeredBidder($auction);
        $method = $this->onlinePaymentMethod();
        $provider = $this->enableFakeProvider();

        $transaction = app(CreatePaymentIntentAction::class)
            ->execute($auction, $bidder->id, PaymentPurpose::BidderDeposit, $method->public_id);

        $refresh = app(RefreshOnlinePaymentAction::class);
        $this->assertSame(PaymentTransactionStatus::Pending, $refresh->execute($transaction->refresh(), true)->status);

        $transaction->forceFill(['expires_at' => Carbon::now()->subMinute()])->save();
        $this->assertSame(PaymentTransactionStatus::Expired, $refresh->execute($transaction->refresh(), true)->status);

        $provider->markSucceeded((string) $transaction->provider_transaction_id);
        $this->assertSame(PaymentTransactionStatus::Expired, $refresh->execute($transaction->refresh(), true)->status);
    }

    public function test_stale_pending_payment_that_actually_succeeded_is_settled_by_reconciliation(): void
    {
        [$auction] = $this->paymentAuction(AuctionStatus::Live);
        [$bidder] = $this->registeredBidder($auction);
        $method = $this->onlinePaymentMethod();
        $provider = $this->enableFakeProvider();

        $transaction = app(CreatePaymentIntentAction::class)
            ->execute($auction, $bidder->id, PaymentPurpose::BidderDeposit, $method->public_id);
        $provider->markSucceeded((string) $transaction->provider_transaction_id);
        $transaction->forceFill([
            'expires_at' => Carbon::now()->subMinute(),
            'created_at' => Carbon::now()->subHour(),
        ])->save();

        app(\App\Jobs\Auction\ReconcilePendingOnlinePaymentsJob::class)->handle(
            app(\App\Repositories\Auction\AuctionPaymentRepository::class),
            app(RefreshOnlinePaymentAction::class)
        );

        $this->assertSame(PaymentTransactionStatus::Succeeded, $transaction->refresh()->status);
        $this->assertSame(AuctionDepositStatus::Held, AuctionDeposit::where('auction_id', $auction->id)->where('user_id', $bidder->id)->firstOrFail()->status);
    }

    public function test_disabled_provider_blocks_new_checkouts_but_not_webhooks_or_reconciliation(): void
    {
        [$auction] = $this->paymentAuction(AuctionStatus::Live);
        [$bidder] = $this->registeredBidder($auction);
        $method = $this->onlinePaymentMethod();
        $provider = $this->enableFakeProvider();
        $action = app(CreatePaymentIntentAction::class);

        $transaction = $action->execute($auction, $bidder->id, PaymentPurpose::BidderDeposit, $method->public_id);
        $provider->markSucceeded((string) $transaction->provider_transaction_id);

        config(['auction.payments.disabled_providers' => ['fake']]);

        $this->postWebhook($transaction, 'payment.succeeded')->assertOk();
        $this->assertSame(PaymentTransactionStatus::Succeeded, $transaction->refresh()->status);

        [$otherBidder] = $this->registeredBidder($auction);

        $this->expectException(AuctionException::class);
        $action->execute($auction, $otherBidder->id, PaymentPurpose::BidderDeposit, $method->public_id);
    }

    public function test_checkout_failure_closes_the_intent_and_never_leaves_it_pending(): void
    {
        [$auction] = $this->paymentAuction(AuctionStatus::Live);
        [$bidder] = $this->registeredBidder($auction);
        $method = $this->onlinePaymentMethod();
        $provider = $this->enableFakeProvider();
        $provider->failCheckouts();

        try {
            app(CreatePaymentIntentAction::class)
                ->execute($auction, $bidder->id, PaymentPurpose::BidderDeposit, $method->public_id);
            $this->fail('Checkout creation should have failed.');
        } catch (AuctionException $exception) {
            $this->assertSame('payment_provider_unavailable', $exception->getErrorCode());
        }

        $transaction = PaymentTransaction::where('auction_id', $auction->id)->firstOrFail();
        $this->assertSame(PaymentTransactionStatus::Failed, $transaction->status);
        $this->assertSame('checkout_unavailable', $transaction->failure_code);
        $this->assertNull($transaction->provider_transaction_id);
    }

    public function test_manual_payment_method_cannot_start_an_online_payment(): void
    {
        [$auction] = $this->paymentAuction(AuctionStatus::Live);
        [$bidder] = $this->registeredBidder($auction);
        $method = $this->manualPaymentMethod();

        try {
            app(CreatePaymentIntentAction::class)
                ->execute($auction, $bidder->id, PaymentPurpose::BidderDeposit, $method->public_id);
            $this->fail('A manual method must not start an online payment.');
        } catch (AuctionException $exception) {
            $this->assertSame('payment_method_not_online', $exception->getErrorCode());
        }
    }

    public function test_payment_method_purpose_restriction_is_enforced(): void
    {
        [$auction] = $this->paymentAuction(AuctionStatus::Live);
        [$bidder] = $this->registeredBidder($auction);
        $method = $this->onlinePaymentMethod(['allowed_purposes' => ['winner_settlement']]);

        try {
            app(CreatePaymentIntentAction::class)
                ->execute($auction, $bidder->id, PaymentPurpose::BidderDeposit, $method->public_id);
            $this->fail('The purpose restriction must be enforced.');
        } catch (AuctionException $exception) {
            $this->assertSame('payment_method_purpose_not_allowed', $exception->getErrorCode());
        }
    }

    public function test_unmatched_webhook_is_recorded_and_ignored(): void
    {
        $payload = [
            'event_id' => 'evt-unmatched-1',
            'event_type' => 'payment.succeeded',
            'provider_transaction_id' => 'fake_unknown_reference',
        ];

        $this->postJson('/api/webhooks/payments/fake', $payload, [
            'X-Fake-Signature' => hash_hmac('sha256', json_encode($payload), 'test-webhook-secret'),
        ])->assertOk()->assertJsonPath('data.result', 'unmatched');

        $this->assertSame('transaction_not_found', PaymentProviderEvent::where('event_id', 'evt-unmatched-1')->firstOrFail()->process_error);
    }

    public function test_unknown_provider_webhook_is_rejected(): void
    {
        $this->postJson('/api/webhooks/payments/not-a-provider', ['event_id' => 'x'])->assertStatus(404);
    }

    private function depositId(int $auctionId, int $userId): int
    {
        return (int) AuctionDeposit::where('auction_id', $auctionId)->where('user_id', $userId)->firstOrFail()->id;
    }

    private function webhookPayload(PaymentTransaction $transaction, string $eventType): array
    {
        return [
            'event_id' => 'evt-'.$transaction->public_id.'-'.$eventType,
            'event_type' => $eventType,
            'provider_transaction_id' => (string) $transaction->refresh()->provider_transaction_id,
        ];
    }

    private function postWebhook(PaymentTransaction $transaction, string $eventType)
    {
        $payload = $this->webhookPayload($transaction, $eventType);

        return $this->postJson('/api/webhooks/payments/fake', $payload, [
            'X-Fake-Signature' => hash_hmac('sha256', json_encode($payload), 'test-webhook-secret'),
        ]);
    }
}
