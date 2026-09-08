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
use App\Models\Auction\PaymentMethod;
use App\Models\Auction\PaymentProviderEvent;
use App\Models\Auction\PaymentTransaction;
use App\Models\Auction\RefundTransaction;
use App\Services\Auction\Actions\CreatePaymentIntentAction;
use App\Services\Auction\Actions\ProcessAuctionRefundAction;
use App\Services\Auction\Payments\Providers\EFawateercomPaymentProvider;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\Feature\Auction\Concerns\BuildsOnlinePaymentFixtures;
use Tests\Feature\Auction\Concerns\SpeaksMfep;
use Tests\TestCase;

final class EFawateercomPaymentNotificationTest extends TestCase
{
    use BuildsOnlinePaymentFixtures;
    use SpeaksMfep;

    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate', ['--force' => true]);
    }

    public function test_a_notification_settles_the_claim_and_answers_in_the_official_envelope(): void
    {
        $method = $this->enableEfawateercom();
        [$auction] = $this->paymentAuction(AuctionStatus::Live);
        [$payer, $participant] = $this->registeredBidder($auction);
        $claim = $this->claimFor($auction, $payer->id, $method);
        $reference = 'JOEBPPS-'.Str::ulid();
        $guid = (string) Str::uuid();

        $response = $this->paymentNotification([
            'claim' => $claim,
            'joebppstrx' => $reference,
            'bank_trx_id' => 'BNK-778899',
        ], $guid);

        $response->assertOk();
        $body = $response->json();

        $this->assertSame('BLRPMTNTFRS', $body['MFEP']['MsgHeader']['TrsInf']['ResTyp']);
        $this->assertSame('1000', $body['MFEP']['MsgHeader']['TrsInf']['SdrCode']);
        $this->assertSame($guid, $body['MFEP']['MsgHeader']['GUID']);
        $this->assertSame(0, $body['MFEP']['MsgHeader']['Result']['ErrorCode']);

        $transfer = $body['MFEP']['MsgBody']['Transactions']['TrxInf'];
        $this->assertSame($reference, $transfer['JOEBPPSTrx']);
        $this->assertSame('2026-09-08T10:43:09', $transfer['ProcessDate']);
        $this->assertSame('2026-09-09', $transfer['STMTDate']);
        $this->assertSame(0, $transfer['Result']['ErrorCode']);
        $this->assertSame('Info', $transfer['Result']['Severity']);

        $transaction = PaymentTransaction::where('provider_transaction_id', $claim)->firstOrFail();
        $this->assertSame(PaymentTransactionStatus::Succeeded, $transaction->status);
        $this->assertSame($reference, $transaction->provider_event_id);
        $this->assertSame('BNK-778899', $transaction->settlement_reference);
        $this->assertSame(150, (int) $transaction->provider_fee_minor);
        $this->assertNotNull($transaction->settled_at);

        $this->assertSame(AuctionParticipantStatus::Qualified, $participant->refresh()->status);
        $this->assertSame(
            AuctionDepositStatus::Held,
            AuctionDeposit::where('auction_id', $auction->id)->where('user_id', $payer->id)->firstOrFail()->status
        );
    }

    public function test_a_repeated_notification_is_acknowledged_without_a_second_effect(): void
    {
        $method = $this->enableEfawateercom();
        [$auction] = $this->paymentAuction(AuctionStatus::Live);
        [$payer] = $this->registeredBidder($auction);
        $claim = $this->claimFor($auction, $payer->id, $method);
        $body = $this->notificationBody(['claim' => $claim, 'joebppstrx' => 'JO-REPEAT']);

        $this->postMfep('/api/webhooks/payments/efawateercom', $body)->assertOk();

        foreach (range(1, 3) as $ignored) {
            $repeat = $this->postMfep('/api/webhooks/payments/efawateercom', $body);
            $repeat->assertOk();
            $this->assertSame(0, $repeat->json('MFEP.MsgHeader.Result.ErrorCode'));
        }

        $this->assertSame(1, PaymentProviderEvent::where('event_id', 'JO-REPEAT')->count());
        $this->assertSame(
            1,
            AuctionDeposit::where('auction_id', $auction->id)->where('user_id', $payer->id)->count()
        );
        $this->assertSame(
            1_000,
            (int) AuctionDeposit::where('auction_id', $auction->id)->where('user_id', $payer->id)->value('held_amount_minor')
        );
    }

    public function test_the_same_reference_can_never_settle_two_claims(): void
    {
        $method = $this->enableEfawateercom();
        [$first] = $this->paymentAuction(AuctionStatus::Live);
        [$second] = $this->paymentAuction(AuctionStatus::Live);
        [$payer] = $this->registeredBidder($first);
        $this->registerBidder($second, $payer);

        $one = $this->claimFor($first, $payer->id, $method);
        $two = $this->claimFor($second, $payer->id, $method);

        $this->paymentNotification(['claim' => $one, 'joebppstrx' => 'JO-SHARED'])->assertOk();
        $replayed = $this->paymentNotification(['claim' => $two, 'joebppstrx' => 'JO-SHARED']);

        $replayed->assertOk();
        $this->assertSame(PaymentTransactionStatus::Pending, PaymentTransaction::where('provider_transaction_id', $two)->firstOrFail()->status);
        $this->assertSame(1, PaymentProviderEvent::where('event_id', 'JO-SHARED')->count());
    }

    public function test_an_unmatched_notification_is_refused_so_the_scheme_retries(): void
    {
        $method = $this->enableEfawateercom();
        [$auction] = $this->paymentAuction(AuctionStatus::Live);
        [$payer, $participant] = $this->registeredBidder($auction);
        $claim = $this->claimFor($auction, $payer->id, $method);

        $unmatched = $this->paymentNotification(['claim' => '999999999999', 'joebppstrx' => 'JO-RETRY']);

        $unmatched->assertStatus(422);
        $this->assertSame(404, $unmatched->json('MFEP.MsgHeader.Result.ErrorCode'));
        $this->assertSame(404, $unmatched->json('MFEP.MsgBody.Transactions.TrxInf.Result.ErrorCode'));
        $this->assertSame('Error', $unmatched->json('MFEP.MsgBody.Transactions.TrxInf.Result.Severity'));

        $record = PaymentProviderEvent::where('event_id', 'JO-RETRY')->firstOrFail();
        $this->assertNull($record->processed_at);
        $this->assertSame('transaction_not_found', $record->process_error);

        $retried = $this->paymentNotification(['claim' => $claim, 'joebppstrx' => 'JO-RETRY']);

        $retried->assertOk();
        $this->assertSame(0, $retried->json('MFEP.MsgHeader.Result.ErrorCode'));
        $this->assertSame(PaymentTransactionStatus::Succeeded, PaymentTransaction::where('provider_transaction_id', $claim)->firstOrFail()->status);
        $this->assertSame(AuctionParticipantStatus::Qualified, $participant->refresh()->status);
        $this->assertSame(1, PaymentProviderEvent::where('event_id', 'JO-RETRY')->count());
    }

    public function test_a_paid_amount_that_is_not_what_we_asked_for_is_recorded_and_refunded(): void
    {
        $method = $this->enableEfawateercom();
        [$auction] = $this->paymentAuction(AuctionStatus::Live);
        [$payer, $participant] = $this->registeredBidder($auction);
        $claim = $this->claimFor($auction, $payer->id, $method);

        $this->paymentNotification(['claim' => $claim, 'paid' => '0.500'])->assertOk();

        $transaction = PaymentTransaction::where('provider_transaction_id', $claim)->firstOrFail();
        $this->assertSame(PaymentTransactionStatus::Succeeded, $transaction->status);
        $this->assertSame('provider_amount_mismatch', $transaction->failure_code);
        $this->assertSame(500, (int) $transaction->captured_amount_minor);
        $this->assertNull($transaction->successful_obligation_key);
        $this->assertSame(AuctionParticipantStatus::Registered, $participant->refresh()->status);
        $this->assertNotNull(RefundTransaction::where('payment_transaction_id', $transaction->id)->first());
    }

    public function test_a_notification_for_an_unmapped_service_is_refused_without_settling(): void
    {
        $method = $this->enableEfawateercom();
        [$auction] = $this->paymentAuction(AuctionStatus::Live);
        [$payer] = $this->registeredBidder($auction);
        $claim = $this->claimFor($auction, $payer->id, $method);

        $response = $this->paymentNotification([
            'claim' => $claim,
            'service_type' => 'SOMEONE-ELSE',
            'joebppstrx' => 'JO-UNMAPPED',
        ]);

        $response->assertStatus(400);
        $this->assertSame(400, $response->json('MFEP.MsgHeader.Result.ErrorCode'));
        $this->assertSame(PaymentTransactionStatus::Pending, PaymentTransaction::where('provider_transaction_id', $claim)->firstOrFail()->status);
    }

    public function test_a_payment_that_lands_after_our_own_expiry_is_still_financially_successful(): void
    {
        $method = $this->enableEfawateercom();
        [$auction] = $this->paymentAuction(AuctionStatus::Live);
        [$payer, $participant] = $this->registeredBidder($auction);
        $claim = $this->claimFor($auction, $payer->id, $method);

        PaymentTransaction::where('provider_transaction_id', $claim)
            ->firstOrFail()
            ->forceFill([
                'status' => PaymentTransactionStatus::Expired,
                'failure_code' => 'intent_expired',
                'expires_at' => Carbon::now()->subHour(),
                'processed_at' => Carbon::now(),
            ])->save();

        $this->paymentNotification(['claim' => $claim])->assertOk();

        $transaction = PaymentTransaction::where('provider_transaction_id', $claim)->firstOrFail();
        $this->assertSame(PaymentTransactionStatus::Succeeded, $transaction->status);
        $this->assertSame(AuctionParticipantStatus::Qualified, $participant->refresh()->status);
    }

    public function test_a_business_state_change_after_inquiry_never_undoes_the_payment(): void
    {
        $method = $this->enableEfawateercom();
        [$auction] = $this->paymentAuction(AuctionStatus::Live);
        [$payer, $participant] = $this->registeredBidder($auction);
        $claim = $this->claimFor($auction, $payer->id, $method);

        $auction->forceFill(['status' => AuctionStatus::PaymentPending])->save();

        $response = $this->paymentNotification(['claim' => $claim]);

        $response->assertOk();
        $this->assertSame(0, $response->json('MFEP.MsgHeader.Result.ErrorCode'));

        $transaction = PaymentTransaction::where('provider_transaction_id', $claim)->firstOrFail();
        $this->assertSame(PaymentTransactionStatus::Succeeded, $transaction->status);
        $this->assertNull($transaction->successful_obligation_key);
        $this->assertSame(AuctionParticipantStatus::Registered, $participant->refresh()->status);

        $refund = RefundTransaction::where('payment_transaction_id', $transaction->id)->firstOrFail();
        $this->assertSame('obligation_no_longer_payable', $refund->reason);
        $this->assertSame(1_000, (int) $refund->amount_minor);
    }

    public function test_the_rail_never_reverses_a_payment_by_itself(): void
    {
        $method = $this->enableEfawateercom();
        [$auction] = $this->paymentAuction(AuctionStatus::Live);
        [$payer] = $this->registeredBidder($auction);
        $claim = $this->claimFor($auction, $payer->id, $method);

        $auction->forceFill(['status' => AuctionStatus::PaymentPending])->save();
        $this->paymentNotification(['claim' => $claim])->assertOk();

        $transaction = PaymentTransaction::where('provider_transaction_id', $claim)->firstOrFail();
        $refund = RefundTransaction::where('payment_transaction_id', $transaction->id)->firstOrFail();
        $processed = app(ProcessAuctionRefundAction::class)->execute($refund);

        $this->assertFalse(app(EFawateercomPaymentProvider::class)->capabilities()->supportsRefund);
        $this->assertSame(RefundTransactionStatus::ManualReview, $processed->status);
        $this->assertNull($processed->provider_refund_id);
        $this->assertSame(PaymentTransactionStatus::Succeeded, $transaction->refresh()->status);
    }

    public function test_a_fee_bearing_payment_credits_only_the_principal_and_never_refunds_the_fee(): void
    {
        $method = $this->enableEfawateercom();
        $method->forceFill([
            'fee_basis' => 'principal',
            'fee_tiers' => [['from_minor' => 0, 'to_minor' => null, 'fee_minor' => 250]],
        ])->save();

        [$auction] = $this->paymentAuction(AuctionStatus::Live);
        [$payer] = $this->registeredBidder($auction);
        $claim = $this->claimFor($auction, $payer->id, $method);

        $auction->forceFill(['status' => AuctionStatus::PaymentPending])->save();
        $this->paymentNotification(['claim' => $claim, 'paid' => '1.250', 'due' => '1.250'])->assertOk();

        $transaction = PaymentTransaction::where('provider_transaction_id', $claim)->firstOrFail();
        $this->assertSame(PaymentTransactionStatus::Succeeded, $transaction->status);
        $this->assertNull($transaction->failure_code);
        $this->assertSame(1_000, (int) $transaction->amount_minor);
        $this->assertSame(250, (int) $transaction->customer_fee_minor);
        $this->assertSame(1_250, $transaction->payableAmountMinor());
        $this->assertSame(150, (int) $transaction->provider_fee_minor);

        $refund = RefundTransaction::where('payment_transaction_id', $transaction->id)->firstOrFail();
        $this->assertSame(1_000, (int) $refund->amount_minor);
    }

    public function test_a_fee_bearing_claim_paid_at_the_principal_alone_is_a_mismatch(): void
    {
        $method = $this->enableEfawateercom();
        $method->forceFill([
            'fee_basis' => 'principal',
            'fee_tiers' => [['from_minor' => 0, 'to_minor' => null, 'fee_minor' => 250]],
        ])->save();

        [$auction] = $this->paymentAuction(AuctionStatus::Live);
        [$payer] = $this->registeredBidder($auction);
        $claim = $this->claimFor($auction, $payer->id, $method);

        $this->paymentNotification(['claim' => $claim, 'paid' => '1.000'])->assertOk();

        $transaction = PaymentTransaction::where('provider_transaction_id', $claim)->firstOrFail();
        $this->assertSame('provider_amount_mismatch', $transaction->failure_code);
        $this->assertSame(1_000, (int) $transaction->captured_amount_minor);
        $this->assertSame(750, (int) $transaction->amount_minor);
    }

    public function test_a_notification_without_credentials_is_refused(): void
    {
        $this->enableEfawateercom();

        $response = $this->postMfep(
            '/api/webhooks/payments/efawateercom',
            $this->notificationBody(['claim' => '123456789012']),
            false
        );

        $response->assertStatus(401);
        $this->assertSame(401, $response->json('MFEP.MsgHeader.Result.ErrorCode'));
    }

    public function test_a_notification_with_the_wrong_username_is_refused(): void
    {
        $this->enableEfawateercom();

        $response = $this->postMfep(
            '/api/webhooks/payments/efawateercom',
            $this->notificationBody(['claim' => '123456789012']),
            ['someone-else', 'ctm-secret']
        );

        $response->assertStatus(401);
    }

    public function test_payer_identity_never_reaches_storage(): void
    {
        $method = $this->enableEfawateercom();
        [$auction] = $this->paymentAuction(AuctionStatus::Live);
        [$payer] = $this->registeredBidder($auction);
        $claim = $this->claimFor($auction, $payer->id, $method);

        $this->paymentNotification(['claim' => $claim, 'joebppstrx' => 'JO-PRIVACY'])->assertOk();

        $stored = (string) json_encode([
            PaymentProviderEvent::where('event_id', 'JO-PRIVACY')->firstOrFail()->payload_redacted,
            PaymentTransaction::where('provider_transaction_id', $claim)->firstOrFail()->provider_payload,
        ]);

        $this->assertStringNotContainsString('Payer Name', $stored);
        $this->assertStringNotContainsString('payer@example.test', $stored);
        $this->assertStringNotContainsString('9999999999', $stored);
        $this->assertStringNotContainsString('Amman', $stored);
    }

    public function test_the_settlement_record_survives_redaction(): void
    {
        $method = $this->enableEfawateercom();
        [$auction] = $this->paymentAuction(AuctionStatus::Live);
        [$payer] = $this->registeredBidder($auction);
        $claim = $this->claimFor($auction, $payer->id, $method);

        $this->paymentNotification([
            'claim' => $claim,
            'joebppstrx' => 'JO-AUDIT',
            'bank_trx_id' => 'BNK-AUDIT',
        ])->assertOk();

        $payload = PaymentTransaction::where('provider_transaction_id', $claim)->firstOrFail()->provider_payload;

        $this->assertSame('JO-AUDIT', $payload['event_id']);
        $this->assertSame($claim, $payload['provider_transaction_id']);
        $this->assertSame('1.000', $payload['amount']);
        $this->assertSame('1.000', $payload['due_amount']);
        $this->assertSame('0.150', $payload['provider_fee']);
        $this->assertSame('BNK-AUDIT', $payload['settlement_reference']);
        $this->assertSame('PmtNew', $payload['status']);
        $this->assertSame('2026-09-09', $payload['statement_date']);
        $this->assertSame('Mobile', $payload['payment_channel']);
        $this->assertSame('SOOMBID', $payload['service_code']);
        $this->assertTrue($payload['fee_on_biller']);
    }

    private function claimFor($auction, int $payerId, PaymentMethod $method, ?PaymentPurpose $purpose = null): string
    {
        return (string) app(CreatePaymentIntentAction::class)
            ->execute($auction, $payerId, $purpose ?? PaymentPurpose::BidderDeposit, $method->public_id)
            ->refresh()
            ->provider_transaction_id;
    }
}
