<?php

declare(strict_types=1);

namespace Tests\Feature\Auction;

use App\Domain\Auction\Enums\AuctionStatus;
use App\Domain\Auction\Enums\PaymentPurpose;
use App\Domain\Auction\Enums\PaymentTransactionStatus;
use App\Models\Auction\AuctionDeposit;
use App\Models\Auction\PaymentBillingReference;
use App\Models\Auction\PaymentMethod;
use App\Models\Auction\PaymentTransaction;
use App\Services\Auction\Actions\CreatePaymentIntentAction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\Feature\Auction\Concerns\BuildsOnlinePaymentFixtures;
use Tests\Feature\Auction\Concerns\SpeaksMfep;
use Tests\TestCase;

final class EFawateercomBillPullTest extends TestCase
{
    use BuildsOnlinePaymentFixtures;
    use SpeaksMfep;

    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate', ['--force' => true]);
    }

    public function test_a_payable_claim_is_presented_as_a_single_one_off_record(): void
    {
        $method = $this->enableEfawateercom();
        [$auction] = $this->paymentAuction(AuctionStatus::Live);
        [$payer] = $this->registeredBidder($auction);
        $claim = $this->claimFor($auction, $payer->id, $method);

        $body = $this->billPull($claim, 'SOOMBID')->json();
        $header = $body['MFEP']['MsgHeader'];
        $record = $body['MFEP']['MsgBody']['BillRec'][0];

        $this->assertSame('BILPULRS', $header['TrsInf']['ResTyp']);
        $this->assertSame('1000', $header['TrsInf']['SdrCode']);
        $this->assertSame(0, $header['Result']['ErrorCode']);
        $this->assertSame(1, $body['MFEP']['MsgBody']['RecCount']);

        $this->assertSame(0, $record['Result']['ErrorCode']);
        $this->assertSame('Info', $record['Result']['Severity']);
        $this->assertSame($claim, $record['AcctInfo']['BillingNo']);
        $this->assertSame($claim, $record['AcctInfo']['BillNo']);
        $this->assertSame('1.000', $record['DueAmount']);
        $this->assertSame('OneOff', $record['BillType']);
        $this->assertSame('BillNew', $record['BillStatus']);
        $this->assertSame('SOOMBID', $record['ServiceType']);
        $this->assertNotNull($record['ExpiryDate']);
        $this->assertFalse($record['PmtConst']['AllowPart']);
        $this->assertSame('1.000', $record['PmtConst']['Lower']);
        $this->assertSame('1.000', $record['PmtConst']['Upper']);
    }

    public function test_the_response_echoes_the_request_correlation_id(): void
    {
        $method = $this->enableEfawateercom();
        [$auction] = $this->paymentAuction(AuctionStatus::Live);
        [$payer] = $this->registeredBidder($auction);
        $claim = $this->claimFor($auction, $payer->id, $method);
        $guid = (string) Str::uuid();

        $response = $this->billPull($claim, 'SOOMBID', $guid);

        $this->assertSame($guid, $response->json('MFEP.MsgHeader.GUID'));
    }

    public function test_an_unknown_claim_is_refused_inside_a_protocol_successful_envelope(): void
    {
        $this->enableEfawateercom();

        $body = $this->billPull('999999999999', 'SOOMBID')->json();

        $this->assertSame(0, $body['MFEP']['MsgHeader']['Result']['ErrorCode']);
        $this->assertSame(1, $body['MFEP']['MsgBody']['RecCount']);

        $record = $body['MFEP']['MsgBody']['BillRec'][0];
        $this->assertSame(404, $record['Result']['ErrorCode']);
        $this->assertSame('Error', $record['Result']['Severity']);
        $this->assertSame('999999999999', $record['AcctInfo']['BillNo']);
        $this->assertSame('0.000', $record['DueAmount']);
    }

    public function test_a_previously_paid_claim_returns_the_documented_paid_code(): void
    {
        $method = $this->enableEfawateercom();
        [$auction] = $this->paymentAuction(AuctionStatus::Live);
        [$payer] = $this->registeredBidder($auction);
        $claim = $this->claimFor($auction, $payer->id, $method);

        PaymentTransaction::where('provider_transaction_id', $claim)
            ->firstOrFail()
            ->forceFill(['status' => PaymentTransactionStatus::Succeeded])
            ->save();

        $record = $this->billPull($claim, 'SOOMBID')->json('MFEP.MsgBody.BillRec.0');

        $this->assertSame(324, $record['Result']['ErrorCode']);
        $this->assertSame('Bill has been paid previously', $record['Result']['ErrorDesc']);
        $this->assertSame($claim, $record['AcctInfo']['BillNo']);
    }

    public function test_an_expired_claim_is_refused(): void
    {
        $method = $this->enableEfawateercom();
        [$auction] = $this->paymentAuction(AuctionStatus::Live);
        [$payer] = $this->registeredBidder($auction);
        $claim = $this->claimFor($auction, $payer->id, $method);

        PaymentTransaction::where('provider_transaction_id', $claim)
            ->firstOrFail()
            ->forceFill(['expires_at' => Carbon::now()->subMinute()])
            ->save();

        $record = $this->billPull($claim, 'SOOMBID')->json('MFEP.MsgBody.BillRec.0');

        $this->assertSame(404, $record['Result']['ErrorCode']);
        $this->assertSame('0.000', $record['DueAmount']);
    }

    public function test_a_claim_whose_obligation_moved_on_is_not_payable(): void
    {
        $method = $this->enableEfawateercom();
        [$auction] = $this->paymentAuction(AuctionStatus::Live);
        [$payer] = $this->registeredBidder($auction);
        $claim = $this->claimFor($auction, $payer->id, $method);

        $auction->forceFill(['status' => AuctionStatus::PaymentPending])->save();

        $record = $this->billPull($claim, 'SOOMBID')->json('MFEP.MsgBody.BillRec.0');

        $this->assertSame(404, $record['Result']['ErrorCode']);
    }

    public function test_a_service_type_that_belongs_to_another_purpose_finds_no_claim(): void
    {
        $method = $this->enableEfawateercom();
        [$auction] = $this->paymentAuction(AuctionStatus::Live);
        [$payer] = $this->registeredBidder($auction);
        $claim = $this->claimFor($auction, $payer->id, $method);

        $record = $this->billPull($claim, 'SOOMSELL')->json('MFEP.MsgBody.BillRec.0');

        $this->assertSame(404, $record['Result']['ErrorCode']);
    }

    public function test_an_unmapped_service_type_is_rejected_before_any_lookup(): void
    {
        $method = $this->enableEfawateercom();
        [$auction] = $this->paymentAuction(AuctionStatus::Live);
        [$payer] = $this->registeredBidder($auction);
        $claim = $this->claimFor($auction, $payer->id, $method);

        // A service we do not run is a bad request, not an empty result.
        $response = $this->billPull($claim, 'NOT-OURS');

        $response->assertStatus(400);
        $this->assertSame(400, $response->json('MFEP.MsgHeader.Result.ErrorCode'));
        $this->assertSame([], $response->json('MFEP.MsgBody.BillRec'));
    }

    public function test_an_inquiry_changes_nothing(): void
    {
        $method = $this->enableEfawateercom();
        [$auction] = $this->paymentAuction(AuctionStatus::Live);
        [$payer] = $this->registeredBidder($auction);
        $claim = $this->claimFor($auction, $payer->id, $method);

        $transaction = PaymentTransaction::where('provider_transaction_id', $claim)->firstOrFail();
        $before = $this->snapshot($transaction);
        $deposits = AuctionDeposit::count();

        $this->billPull($claim, 'SOOMBID')->assertOk();
        $this->billPull($claim, 'SOOMBID')->assertOk();

        $this->assertSame($before, $this->snapshot($transaction->refresh()));
        $this->assertSame($deposits, AuctionDeposit::count());
    }

    public function test_a_fee_free_claim_asks_for_the_principal_alone(): void
    {
        $method = $this->enableEfawateercom();
        [$auction] = $this->paymentAuction(AuctionStatus::Live);
        [$payer] = $this->registeredBidder($auction);
        $claim = $this->claimFor($auction, $payer->id, $method);

        $record = $this->billPull($claim, 'SOOMBID')->json('MFEP.MsgBody.BillRec.0');

        $this->assertSame('1.000', $record['DueAmount']);
        $this->assertSame(0, (int) PaymentTransaction::where('provider_transaction_id', $claim)->value('customer_fee_minor'));
    }

    public function test_a_fee_bearing_claim_asks_for_principal_plus_fee(): void
    {
        $method = $this->enableEfawateercom();
        $method->forceFill([
            'fee_basis' => 'principal',
            'fee_tiers' => [
                ['from_minor' => 0, 'to_minor' => 10_000, 'fee_minor' => 250],
                ['from_minor' => 10_001, 'to_minor' => null, 'fee_minor' => 500],
            ],
        ])->save();

        [$auction] = $this->paymentAuction(AuctionStatus::Live);
        [$payer] = $this->registeredBidder($auction);
        $claim = $this->claimFor($auction, $payer->id, $method);

        $record = $this->billPull($claim, 'SOOMBID')->json('MFEP.MsgBody.BillRec.0');
        $transaction = PaymentTransaction::where('provider_transaction_id', $claim)->firstOrFail();

        $this->assertSame(1_000, (int) $transaction->amount_minor);
        $this->assertSame(250, (int) $transaction->customer_fee_minor);
        $this->assertSame('1.250', $record['DueAmount']);
        $this->assertSame('1.250', $record['PmtConst']['Lower']);
        $this->assertSame('1.250', $record['PmtConst']['Upper']);
    }

    public function test_a_fee_schedule_with_no_matching_tier_refuses_to_raise_a_claim(): void
    {
        $method = $this->enableEfawateercom();
        $method->forceFill([
            'fee_basis' => 'principal',
            'fee_tiers' => [['from_minor' => 100_000, 'to_minor' => 200_000, 'fee_minor' => 250]],
        ])->save();

        [$auction] = $this->paymentAuction(AuctionStatus::Live);
        [$payer] = $this->registeredBidder($auction);

        $this->expectExceptionMessage(__('auction.errors.payment_fee_tier_missing'));

        app(CreatePaymentIntentAction::class)
            ->execute($auction, $payer->id, PaymentPurpose::BidderDeposit, $method->public_id);
    }

    public function test_a_claim_reference_is_numeric_without_a_leading_zero(): void
    {
        $method = $this->enableEfawateercom();
        [$auction] = $this->paymentAuction(AuctionStatus::Live);
        [$payer] = $this->registeredBidder($auction);

        $claim = $this->claimFor($auction, $payer->id, $method);

        $this->assertMatchesRegularExpression('/^[1-9][0-9]{11}$/', $claim);
        $this->assertLessThanOrEqual(50, strlen($claim));
    }

    public function test_an_inquiry_without_credentials_is_refused(): void
    {
        $this->enableEfawateercom();

        $response = $this->postMfep('/api/webhooks/payments/efawateercom/bills', $this->billPullBody('123456789012', 'SOOMBID'), false);

        $response->assertStatus(401);
        $this->assertSame(401, $response->json('MFEP.MsgHeader.Result.ErrorCode'));
        $this->assertSame([], $response->json('MFEP.MsgBody.BillRec'));
    }

    public function test_an_inquiry_with_the_wrong_password_is_refused(): void
    {
        $this->enableEfawateercom();

        $response = $this->postMfep(
            '/api/webhooks/payments/efawateercom/bills',
            $this->billPullBody('123456789012', 'SOOMBID'),
            ['ctm-user', 'wrong-secret']
        );

        $response->assertStatus(401);
    }

    public function test_a_request_of_the_wrong_type_is_refused(): void
    {
        $this->enableEfawateercom();

        $body = $this->billPullBody('123456789012', 'SOOMBID');
        $body['MFEP']['MsgHeader']['TrsInf']['ReqTyp'] = 'BLRPMTNTFRQ';

        $response = $this->postMfep('/api/webhooks/payments/efawateercom/bills', $body);

        $response->assertStatus(400);
        $this->assertSame(400, $response->json('MFEP.MsgHeader.Result.ErrorCode'));
    }

    public function test_the_one_off_path_never_allocates_a_lasting_payer_reference(): void
    {
        $method = $this->enableEfawateercom();
        [$auction] = $this->paymentAuction(AuctionStatus::Live);
        [$payer] = $this->registeredBidder($auction);

        $claim = $this->claimFor($auction, $payer->id, $method);
        $this->billPull($claim, 'SOOMBID')->assertOk();

        $this->assertSame(0, PaymentBillingReference::count());
    }

    public function test_every_claim_for_one_payer_is_a_different_number(): void
    {
        $method = $this->enableEfawateercom();
        [$first] = $this->paymentAuction(AuctionStatus::Live);
        [$second] = $this->paymentAuction(AuctionStatus::Live);
        [$payer] = $this->registeredBidder($first);
        $this->registerBidder($second, $payer);

        $one = $this->claimFor($first, $payer->id, $method);
        $two = $this->claimFor($second, $payer->id, $method);

        $this->assertNotSame($one, $two);
        $this->assertSame(0, PaymentBillingReference::count());
    }

    private function snapshot(PaymentTransaction $transaction): array
    {
        return [
            'status' => $transaction->status->value,
            'amount_minor' => (int) $transaction->amount_minor,
            'customer_fee_minor' => (int) $transaction->customer_fee_minor,
            'expires_at' => $transaction->expires_at?->toIso8601String(),
            'processed_at' => $transaction->processed_at?->toIso8601String(),
        ];
    }

    private function claimFor($auction, int $payerId, PaymentMethod $method, ?PaymentPurpose $purpose = null): string
    {
        return (string) app(CreatePaymentIntentAction::class)
            ->execute($auction, $payerId, $purpose ?? PaymentPurpose::BidderDeposit, $method->public_id)
            ->refresh()
            ->provider_transaction_id;
    }
}
