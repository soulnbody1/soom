<?php

declare(strict_types=1);

namespace Tests\Feature\Auction;

use App\Domain\Auction\Enums\AuctionDepositStatus;
use App\Domain\Auction\Enums\AuctionStatus;
use App\Domain\Auction\Enums\PaymentPurpose;
use App\Domain\Auction\Enums\PaymentTransactionStatus;
use App\Domain\Auction\Enums\RefundTransactionStatus;
use App\Domain\Auction\Exceptions\AuctionException;
use App\Models\Auction\AuctionActivityLog;
use App\Models\Auction\AuctionDeposit;
use App\Models\Auction\PaymentTransaction;
use App\Models\Auction\RefundTransaction;
use App\Services\Auction\Actions\CreatePaymentIntentAction;
use App\Services\Auction\Actions\ProcessAuctionRefundAction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\Feature\Auction\Concerns\BuildsOnlinePaymentFixtures;
use Tests\TestCase;

final class OnlinePaymentRecoveryTest extends TestCase
{
    use BuildsOnlinePaymentFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate', ['--force' => true]);
        $this->enableFakeProvider();
    }

    public function test_a_charge_orphaned_by_a_crash_is_recovered_through_the_merchant_reference(): void
    {
        [$auction] = $this->paymentAuction(AuctionStatus::Live);
        [$bidder] = $this->registeredBidder($auction);
        $provider = $this->enableFakeProvider();

        $transaction = app(CreatePaymentIntentAction::class)
            ->execute($auction, $bidder->id, PaymentPurpose::BidderDeposit, $this->onlinePaymentMethod()->public_id);

        $orphanReference = (string) $transaction->provider_transaction_id;

        $transaction->forceFill([
            'provider_transaction_id' => null,
            'checkout_instruction' => null,
        ])->save();

        $provider->markSucceeded($orphanReference);

        $this->postWebhook($orphanReference, 'evt-orphan-1', (string) $transaction->public_id)
            ->assertOk()
            ->assertJsonPath('data.result', 'processed');

        $transaction->refresh();
        $this->assertSame(PaymentTransactionStatus::Succeeded, $transaction->status);
        $this->assertSame($orphanReference, $transaction->provider_transaction_id);

        $deposit = AuctionDeposit::where('auction_id', $auction->id)->where('user_id', $bidder->id)->firstOrFail();
        $this->assertSame(AuctionDepositStatus::Held, $deposit->status);
        $this->assertSame("deposit:{$deposit->id}", $transaction->successful_obligation_key);

        $this->assertSame(
            1,
            AuctionActivityLog::where('auction_id', $auction->id)
                ->where('event_type', 'auction.online_payment_reference_recovered')
                ->count()
        );
    }

    public function test_a_crash_retry_opens_a_second_attempt_so_both_charges_stay_recoverable(): void
    {
        [$auction] = $this->paymentAuction(AuctionStatus::Live);
        [$bidder] = $this->registeredBidder($auction);
        $method = $this->onlinePaymentMethod();
        $provider = $this->enableFakeProvider();
        $action = app(CreatePaymentIntentAction::class);

        $first = $action->execute($auction, $bidder->id, PaymentPurpose::BidderDeposit, $method->public_id);
        $orphanReference = (string) $first->provider_transaction_id;

        $first->forceFill([
            'provider_transaction_id' => null,
            'checkout_instruction' => null,
            'checkout_claimed_at' => Carbon::now()->subHour(),
        ])->save();

        $retry = $action->execute($auction, $bidder->id, PaymentPurpose::BidderDeposit, $method->public_id);

        $this->assertNotSame($first->id, $retry->id);
        $this->assertSame(2, PaymentTransaction::where('auction_id', $auction->id)->count());

        $provider->markSucceeded($orphanReference);
        $provider->markSucceeded((string) $retry->provider_transaction_id);

        $this->postWebhook((string) $retry->provider_transaction_id, 'evt-retry', (string) $retry->public_id)->assertOk();
        $this->postWebhook($orphanReference, 'evt-orphan-2', (string) $first->public_id)->assertOk();

        $deposit = AuctionDeposit::where('auction_id', $auction->id)->where('user_id', $bidder->id)->firstOrFail();
        $this->assertSame(AuctionDepositStatus::Held, $deposit->status);
        $this->assertSame(1_000, (int) $deposit->held_amount_minor);
        $this->assertSame(1, PaymentTransaction::where('successful_obligation_key', "deposit:{$deposit->id}")->count());

        $this->assertSame(PaymentTransactionStatus::Succeeded, $first->refresh()->status);
        $this->assertNull($first->successful_obligation_key);

        $refund = RefundTransaction::where('payment_transaction_id', $first->id)->firstOrFail();
        $this->assertSame('obligation_no_longer_payable', $refund->reason);
        $this->assertSame(1_000, (int) $refund->amount_minor);
    }

    public function test_a_checkout_still_in_flight_is_not_duplicated(): void
    {
        [$auction] = $this->paymentAuction(AuctionStatus::Live);
        [$bidder] = $this->registeredBidder($auction);
        $method = $this->onlinePaymentMethod();
        $action = app(CreatePaymentIntentAction::class);

        $first = $action->execute($auction, $bidder->id, PaymentPurpose::BidderDeposit, $method->public_id);

        $first->forceFill([
            'provider_transaction_id' => null,
            'checkout_instruction' => null,
            'checkout_claimed_at' => Carbon::now(),
        ])->save();

        try {
            $action->execute($auction, $bidder->id, PaymentPurpose::BidderDeposit, $method->public_id);
            $this->fail('A checkout still in flight must not be duplicated.');
        } catch (AuctionException $exception) {
            $this->assertSame('payment_checkout_in_progress', $exception->getErrorCode());
        }

        $this->assertSame(1, PaymentTransaction::where('auction_id', $auction->id)->count());
    }

    public function test_a_foreign_provider_reference_never_binds_to_an_already_bound_payment(): void
    {
        [$auction] = $this->paymentAuction(AuctionStatus::Live);
        [$bidder] = $this->registeredBidder($auction);
        $provider = $this->enableFakeProvider();

        $transaction = app(CreatePaymentIntentAction::class)
            ->execute($auction, $bidder->id, PaymentPurpose::BidderDeposit, $this->onlinePaymentMethod()->public_id);

        $provider->markSucceeded('fake_a_different_charge', 1_000, 'JOD');

        $this->postWebhook('fake_a_different_charge', 'evt-foreign', (string) $transaction->public_id)
            ->assertStatus(422);

        $transaction->refresh();
        $this->assertSame(PaymentTransactionStatus::Pending, $transaction->status);
        $this->assertNotSame('fake_a_different_charge', $transaction->provider_transaction_id);
    }

    public function test_a_full_refund_leaves_the_payment_succeeded_and_blocks_a_second_refund(): void
    {
        [$auction] = $this->paymentAuction(AuctionStatus::Live);
        [$bidder] = $this->registeredBidder($auction);
        $provider = $this->enableFakeProvider();

        $transaction = app(CreatePaymentIntentAction::class)
            ->execute($auction, $bidder->id, PaymentPurpose::BidderDeposit, $this->onlinePaymentMethod()->public_id);
        $provider->markSucceeded((string) $transaction->provider_transaction_id, amountMinor: 1_400);
        $this->postWebhook((string) $transaction->provider_transaction_id, 'evt-mismatch', (string) $transaction->public_id)->assertOk();

        $refund = RefundTransaction::where('payment_transaction_id', $transaction->id)->firstOrFail();
        $this->assertSame(RefundTransactionStatus::Succeeded, app(ProcessAuctionRefundAction::class)->execute($refund)->status);

        $this->assertSame(PaymentTransactionStatus::Succeeded, $transaction->refresh()->status);

        $second = RefundTransaction::create([
            'auction_id' => $auction->id,
            'deposit_id' => null,
            'payment_transaction_id' => $transaction->id,
            'obligation_type' => 'deposit',
            'obligation_id' => null,
            'user_id' => $bidder->id,
            'status' => RefundTransactionStatus::Pending,
            'amount_minor' => 1_400,
            'held_refund_amount_minor' => 0,
            'applied_refund_amount_minor' => 0,
            'currency_code' => 'JOD',
            'reason' => 'double_refund_attempt',
            'provider' => 'fake',
            'idempotency_key' => 'double-refund-attempt',
        ]);

        $this->expectException(AuctionException::class);

        app(\App\Services\Auction\Support\AuctionRefundCompletion::class)
            ->completeSucceeded($second, 'fake_refund_double');
    }

    public function test_an_unsupported_captured_currency_is_stored_structurally_and_surfaced_to_the_operator(): void
    {
        [$auction] = $this->paymentAuction(AuctionStatus::Live);
        [$bidder] = $this->registeredBidder($auction);
        $provider = $this->enableFakeProvider();
        $admin = $this->paymentUser('admin');

        $transaction = app(CreatePaymentIntentAction::class)
            ->execute($auction, $bidder->id, PaymentPurpose::BidderDeposit, $this->onlinePaymentMethod()->public_id);
        $provider->markSucceeded((string) $transaction->provider_transaction_id, amountMinor: 1_234, currencyCode: 'GBP');

        $this->postWebhook((string) $transaction->provider_transaction_id, 'evt-gbp', (string) $transaction->public_id)->assertOk();

        $transaction->refresh();
        $this->assertSame('provider_currency_unsupported', $transaction->failure_code);
        $this->assertSame(1_234, (int) $transaction->captured_amount_minor);
        $this->assertSame('GBP', $transaction->captured_currency_code);
        $this->assertSame('JOD', $transaction->currency_code);

        $refund = RefundTransaction::where('payment_transaction_id', $transaction->id)->firstOrFail();

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/admin/auctions/refunds');

        $response->assertOk();
        $row = collect($response->json('data'))->firstWhere('public_id', $refund->public_id);

        $this->assertNotNull($row);
        $this->assertSame(1_234, $row['captured']['amount_minor']);
        $this->assertSame('GBP', $row['captured']['currency_code']);
        $this->assertFalse($row['captured']['matches_refund']);
        $this->assertSame('provider_currency_unsupported', $row['captured']['failure_code']);
    }

    public function test_a_matching_capture_records_no_captured_override(): void
    {
        [$auction] = $this->paymentAuction(AuctionStatus::Live);
        [$bidder] = $this->registeredBidder($auction);
        $provider = $this->enableFakeProvider();

        $transaction = app(CreatePaymentIntentAction::class)
            ->execute($auction, $bidder->id, PaymentPurpose::BidderDeposit, $this->onlinePaymentMethod()->public_id);
        $provider->markSucceeded((string) $transaction->provider_transaction_id);
        $this->postWebhook((string) $transaction->provider_transaction_id, 'evt-clean', (string) $transaction->public_id)->assertOk();

        $transaction->refresh();
        $this->assertNull($transaction->captured_amount_minor);
        $this->assertNull($transaction->captured_currency_code);
        $this->assertNull($transaction->failure_code);
    }

    private function postWebhook(string $providerTransactionId, string $eventId, string $merchantReference)
    {
        $payload = [
            'event_id' => $eventId,
            'event_type' => 'payment.succeeded',
            'provider_transaction_id' => $providerTransactionId,
            'merchant_reference' => $merchantReference,
        ];

        return $this->postJson('/api/webhooks/payments/fake', $payload, [
            'X-Fake-Signature' => hash_hmac('sha256', json_encode($payload), 'test-webhook-secret'),
        ]);
    }
}
