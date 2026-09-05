<?php

declare(strict_types=1);

namespace Tests\Feature\Auction;

use App\Domain\Auction\Enums\AuctionDepositStatus;
use App\Domain\Auction\Enums\AuctionParticipantStatus;
use App\Domain\Auction\Enums\AuctionStatus;
use App\Domain\Auction\Enums\PaymentPurpose;
use App\Domain\Auction\Enums\PaymentTransactionStatus;
use App\Domain\Auction\Enums\RefundTransactionStatus;
use App\Models\Auction\AuctionDeposit;
use App\Models\Auction\PaymentBillingReference;
use App\Models\Auction\PaymentProviderEvent;
use App\Models\Auction\PaymentTransaction;
use App\Models\Auction\RefundTransaction;
use App\Services\Auction\Actions\CreatePaymentIntentAction;
use App\Services\Auction\Actions\ProcessAuctionRefundAction;
use App\Services\Auction\Actions\RefreshOnlinePaymentAction;
use App\Services\Auction\Payments\Providers\FakeBillPaymentProvider;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\Feature\Auction\Concerns\BuildsOnlinePaymentFixtures;
use Tests\TestCase;

final class BillRailPaymentLifecycleTest extends TestCase
{
    use BuildsOnlinePaymentFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate', ['--force' => true]);
        $this->enableFakeBillProvider();
    }

    public function test_a_bill_intent_instructs_the_payer_instead_of_redirecting_them(): void
    {
        [$auction] = $this->paymentAuction(AuctionStatus::Live);
        [$payer] = $this->registeredBidder($auction);

        $transaction = app(CreatePaymentIntentAction::class)
            ->execute($auction, $payer->id, PaymentPurpose::BidderDeposit, $this->billPaymentMethod()->public_id)
            ->refresh();

        $instruction = $transaction->checkout_instruction;

        $this->assertSame('bill', $instruction['type']);
        $this->assertNull($instruction['redirect_url']);
        $this->assertSame($transaction->provider_transaction_id, $instruction['reference']);
        $this->assertSame($transaction->provider_transaction_id, $instruction['details']['bill_reference']);
        $this->assertSame('1.000', $instruction['details']['amount']);
        $this->assertSame(
            PaymentBillingReference::where('user_id', $payer->id)->firstOrFail()->reference,
            $instruction['details']['billing_reference']
        );
        $this->assertSame(PaymentTransactionStatus::Pending, $transaction->status);
    }

    public function test_a_payer_keeps_one_billing_reference_across_every_claim(): void
    {
        [$first] = $this->paymentAuction(AuctionStatus::Live);
        [$second] = $this->paymentAuction(AuctionStatus::Live);
        [$payer] = $this->registeredBidder($first);
        $this->registerBidder($second, $payer);
        $method = $this->billPaymentMethod();
        $action = app(CreatePaymentIntentAction::class);

        $one = $action->execute($first, $payer->id, PaymentPurpose::BidderDeposit, $method->public_id)->refresh();
        $two = $action->execute($second, $payer->id, PaymentPurpose::BidderDeposit, $method->public_id)->refresh();

        $this->assertSame(
            $one->checkout_instruction['details']['billing_reference'],
            $two->checkout_instruction['details']['billing_reference']
        );
        $this->assertNotSame($one->provider_transaction_id, $two->provider_transaction_id);
        $this->assertSame(1, PaymentBillingReference::where('user_id', $payer->id)->count());
    }

    public function test_an_intent_never_outlives_the_obligation_it_pays_for(): void
    {
        [$auction] = $this->paymentAuction(AuctionStatus::Live, ['ends_at' => Carbon::now()->addMinutes(5)]);
        [$payer] = $this->registeredBidder($auction);
        $this->enableFakeProvider();

        // The card provider asks for a 30 minute checkout, but the auction closes in 5.
        $transaction = app(CreatePaymentIntentAction::class)
            ->execute($auction, $payer->id, PaymentPurpose::BidderDeposit, $this->onlinePaymentMethod()->public_id)
            ->refresh();

        $this->assertTrue($transaction->expires_at->lessThanOrEqualTo($auction->refresh()->ends_at));
        $this->assertTrue($transaction->expires_at->greaterThan(Carbon::now()->addMinutes(4)));
    }

    public function test_a_signed_event_settles_the_payment_with_no_status_inquiry(): void
    {
        [$auction] = $this->paymentAuction(AuctionStatus::Live);
        [$payer, $participant] = $this->registeredBidder($auction);
        $transaction = $this->billIntent($auction, $payer->id);

        $response = $this->postBillEvent([
            'event_id' => 'evt-'.Str::ulid(),
            'event_type' => 'bill.paid',
            'bill_reference' => (string) $transaction->provider_transaction_id,
            'paid_amount' => '1.000',
            'currency' => 'JOD',
            'fee_minor' => 25,
            'settlement_reference' => 'stl-000123',
            'settled_at' => Carbon::now()->toIso8601String(),
        ]);

        $response->assertOk();
        $this->assertTrue($response->json('acknowledged'));
        $this->assertSame('processed', $response->json('outcome'));

        $transaction->refresh();
        $this->assertSame(PaymentTransactionStatus::Succeeded, $transaction->status);
        $this->assertSame(25, (int) $transaction->provider_fee_minor);
        $this->assertSame('stl-000123', $transaction->settlement_reference);
        $this->assertNotNull($transaction->settled_at);

        $this->assertSame(
            AuctionDepositStatus::Held,
            AuctionDeposit::where('auction_id', $auction->id)->where('user_id', $payer->id)->firstOrFail()->status
        );
        $this->assertSame(AuctionParticipantStatus::Qualified, $participant->refresh()->status);
    }

    public function test_a_repeated_event_is_acknowledged_without_a_second_effect(): void
    {
        [$auction] = $this->paymentAuction(AuctionStatus::Live);
        [$payer] = $this->registeredBidder($auction);
        $transaction = $this->billIntent($auction, $payer->id);

        $payload = [
            'event_id' => 'evt-repeat',
            'event_type' => 'bill.paid',
            'bill_reference' => (string) $transaction->provider_transaction_id,
            'paid_amount' => '1.000',
            'currency' => 'JOD',
        ];

        $this->postBillEvent($payload)->assertOk();
        $second = $this->postBillEvent($payload);

        $second->assertOk();
        $this->assertSame('duplicate', $second->json('outcome'));
        $this->assertSame(1, PaymentProviderEvent::where('provider', FakeBillPaymentProvider::CODE)->count());
        $this->assertSame(PaymentTransactionStatus::Succeeded, $transaction->refresh()->status);
    }

    public function test_an_unsigned_event_is_refused_in_the_providers_own_envelope(): void
    {
        [$auction] = $this->paymentAuction(AuctionStatus::Live);
        [$payer] = $this->registeredBidder($auction);
        $transaction = $this->billIntent($auction, $payer->id);

        $response = $this->postJson('/api/webhooks/payments/'.FakeBillPaymentProvider::CODE, [
            'event_id' => 'evt-forged',
            'bill_reference' => (string) $transaction->provider_transaction_id,
        ]);

        $response->assertStatus(401);
        $this->assertFalse($response->json('acknowledged'));
        $this->assertSame(PaymentTransactionStatus::Pending, $transaction->refresh()->status);
    }

    public function test_a_pending_bill_expires_even_though_the_provider_cannot_be_polled(): void
    {
        [$auction] = $this->paymentAuction(AuctionStatus::Live);
        [$payer] = $this->registeredBidder($auction);
        $transaction = $this->billIntent($auction, $payer->id);
        $transaction->forceFill(['expires_at' => Carbon::now()->subMinute()])->save();

        $refreshed = app(RefreshOnlinePaymentAction::class)->execute($transaction, true);

        $this->assertSame(PaymentTransactionStatus::Expired, $refreshed->status);
        $this->assertSame('intent_expired', $refreshed->failure_code);
    }

    public function test_a_payment_that_lands_after_local_expiry_is_still_recorded(): void
    {
        [$auction] = $this->paymentAuction(AuctionStatus::Live);
        [$payer, $participant] = $this->registeredBidder($auction);
        $transaction = $this->billIntent($auction, $payer->id);
        $transaction->forceFill([
            'status' => PaymentTransactionStatus::Expired,
            'failure_code' => 'intent_expired',
            'processed_at' => Carbon::now(),
        ])->save();

        $this->postBillEvent([
            'event_id' => 'evt-late',
            'bill_reference' => (string) $transaction->provider_transaction_id,
            'paid_amount' => '1.000',
            'currency' => 'JOD',
        ])->assertOk();

        // The obligation still stood, so the money is applied rather than stranded.
        $this->assertSame(PaymentTransactionStatus::Succeeded, $transaction->refresh()->status);
        $this->assertSame(AuctionParticipantStatus::Qualified, $participant->refresh()->status);
    }

    public function test_a_payment_for_a_dead_obligation_is_recorded_and_sent_to_manual_refund(): void
    {
        [$auction] = $this->paymentAuction(AuctionStatus::Live);
        [$payer, $participant] = $this->registeredBidder($auction);
        $transaction = $this->billIntent($auction, $payer->id);

        $transaction->forceFill(['status' => PaymentTransactionStatus::Expired])->save();
        $auction->forceFill(['status' => AuctionStatus::PaymentPending])->save();

        $this->postBillEvent([
            'event_id' => 'evt-orphan',
            'bill_reference' => (string) $transaction->provider_transaction_id,
            'paid_amount' => '1.000',
            'currency' => 'JOD',
        ])->assertOk();

        $transaction->refresh();
        $this->assertSame(PaymentTransactionStatus::Succeeded, $transaction->status);
        $this->assertNull($transaction->successful_obligation_key);
        $this->assertSame(AuctionParticipantStatus::Registered, $participant->refresh()->status);

        $refund = RefundTransaction::where('payment_transaction_id', $transaction->id)->firstOrFail();
        $this->assertSame('obligation_no_longer_payable', $refund->reason);
        $this->assertSame(FakeBillPaymentProvider::CODE, $refund->provider);

        // The rail has no reversal API, so the refund must stop for a human.
        $processed = app(ProcessAuctionRefundAction::class)->execute($refund);
        $this->assertSame(RefundTransactionStatus::ManualReview, $processed->status);
        $this->assertNull($processed->provider_refund_id);
    }

    private function billIntent($auction, int $payerId): PaymentTransaction
    {
        return app(CreatePaymentIntentAction::class)
            ->execute($auction, $payerId, PaymentPurpose::BidderDeposit, $this->billPaymentMethod()->public_id)
            ->refresh();
    }

    private function postBillEvent(array $payload)
    {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $this->call(
            'POST',
            '/api/webhooks/payments/'.FakeBillPaymentProvider::CODE,
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_FAKE_BILL_SIGNATURE' => hash_hmac('sha256', $body, 'test-bill-secret'),
            ],
            $body
        );
    }
}
