<?php

declare(strict_types=1);

namespace Tests\Feature\Auction;

use App\Domain\Auction\Enums\AuctionStatus;
use App\Domain\Auction\Enums\BillRejectionReason;
use App\Domain\Auction\Enums\PaymentPurpose;
use App\Domain\Auction\Enums\PaymentTransactionStatus;
use App\Domain\Auction\ValueObjects\BillingReference;
use App\Domain\Auction\ValueObjects\BillReference;
use App\Models\Auction\PaymentBillingReference;
use App\Models\Auction\PaymentTransaction;
use App\Models\User;
use App\Services\Auction\Actions\CreatePaymentIntentAction;
use App\Services\Auction\Actions\ResolveBillPresentmentAction;
use App\Services\Auction\Payments\Bills\BillQuery;
use App\Services\Auction\Payments\Providers\FakeBillPaymentProvider;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\Feature\Auction\Concerns\BuildsOnlinePaymentFixtures;
use Tests\TestCase;

final class BillPresentmentTest extends TestCase
{
    use BuildsOnlinePaymentFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate', ['--force' => true]);
        $this->enableFakeBillProvider();
    }

    public function test_a_billing_reference_alone_presents_every_open_claim(): void
    {
        [$payer, $bills] = $this->payerWithTwoOpenBills();

        $resolution = $this->resolve($this->billingReferenceOf($payer));

        $this->assertNull($resolution->rejection);
        $this->assertSame(2, $resolution->count());
        $this->assertEqualsCanonicalizing(
            array_map(fn (PaymentTransaction $t): string => (string) $t->provider_transaction_id, $bills),
            array_map(fn ($bill): string => $bill->billReference->value, $resolution->bills)
        );
    }

    public function test_adding_a_bill_reference_narrows_the_same_lookup_to_one_claim(): void
    {
        [$payer, $bills] = $this->payerWithTwoOpenBills();
        $target = $bills[1];

        $resolution = $this->resolve(
            $this->billingReferenceOf($payer),
            new BillReference((string) $target->provider_transaction_id)
        );

        $this->assertNull($resolution->rejection);
        $this->assertSame(1, $resolution->count());
        $this->assertSame(
            (string) $target->provider_transaction_id,
            $resolution->sole()?->billReference->value
        );
    }

    public function test_presented_claims_carry_the_frozen_amount_and_forbid_partial_payment(): void
    {
        [$payer] = $this->payerWithTwoOpenBills();

        $bill = $this->resolve($this->billingReferenceOf($payer))->bills[0];

        $this->assertSame(1_000, $bill->principalMinor);
        $this->assertSame(0, $bill->customerFeeMinor);
        $this->assertSame(1_000, $bill->payableMinor());
        $this->assertSame('JOD', $bill->currencyCode);
        $this->assertFalse($bill->allowsPartialPayment());
        $this->assertSame($bill->payableMinor(), $bill->minimumPayableMinor());
        $this->assertSame($bill->payableMinor(), $bill->maximumPayableMinor());
        $this->assertSame($payer->name, $bill->payerDisplayName);
    }

    public function test_a_purpose_narrows_the_lookup_without_changing_the_path(): void
    {
        [$auction] = $this->paymentAuction(AuctionStatus::Live);
        [$sellerAuction, $payer] = $this->paymentAuction(AuctionStatus::AwaitingSellerDeposit);
        $this->registerBidder($auction, $payer);
        $method = $this->billPaymentMethod();
        $action = app(CreatePaymentIntentAction::class);

        $action->execute($auction, $payer->id, PaymentPurpose::BidderDeposit, $method->public_id);
        $action->execute($sellerAuction, $payer->id, PaymentPurpose::SellerDeposit, $method->public_id);

        $reference = $this->billingReferenceOf($payer);

        $this->assertSame(2, $this->resolve($reference)->count());

        $sellerOnly = $this->resolve($reference, null, PaymentPurpose::SellerDeposit);
        $this->assertSame(1, $sellerOnly->count());
        $this->assertSame(PaymentPurpose::SellerDeposit, $sellerOnly->sole()?->purpose);
    }

    public function test_an_unknown_billing_reference_is_rejected_without_leaking_anything(): void
    {
        $this->payerWithTwoOpenBills();

        $resolution = $this->resolve(new BillingReference('0000000000'));

        $this->assertSame(BillRejectionReason::UnknownBillingReference, $resolution->rejection);
        $this->assertTrue($resolution->isEmpty());
    }

    public function test_a_payer_who_owes_nothing_is_told_so(): void
    {
        [$payer, $bills] = $this->payerWithTwoOpenBills();

        foreach ($bills as $bill) {
            $bill->forceFill(['expires_at' => Carbon::now()->subMinute()])->save();
        }

        $this->assertSame(
            BillRejectionReason::NoPayableBills,
            $this->resolve($this->billingReferenceOf($payer))->rejection
        );
    }

    public function test_a_bill_reference_that_does_not_exist_is_distinguished_from_one_that_is_closed(): void
    {
        [$payer, $bills] = $this->payerWithTwoOpenBills();

        $this->assertSame(
            BillRejectionReason::BillNotFound,
            $this->resolve($this->billingReferenceOf($payer), new BillReference('999999999999'))->rejection
        );

        $closed = $bills[0];
        $closed->forceFill(['amount_minor' => 4_242])->save();

        $this->assertSame(
            BillRejectionReason::BillNotPayable,
            $this->resolve(
                $this->billingReferenceOf($payer),
                new BillReference((string) $closed->provider_transaction_id)
            )->rejection
        );
    }

    public function test_an_expired_claim_is_no_longer_presented(): void
    {
        [$payer, $bills] = $this->payerWithTwoOpenBills();
        $bills[0]->forceFill(['expires_at' => Carbon::now()->subSecond()])->save();

        $resolution = $this->resolve($this->billingReferenceOf($payer));

        $this->assertSame(1, $resolution->count());
        $this->assertSame(
            (string) $bills[1]->provider_transaction_id,
            $resolution->sole()?->billReference->value
        );
    }

    public function test_one_payer_never_sees_another_payers_claim(): void
    {
        [$auction] = $this->paymentAuction(AuctionStatus::Live);
        [$first] = $this->registeredBidder($auction);
        [$second] = $this->registeredBidder($auction);
        $method = $this->billPaymentMethod();
        $action = app(CreatePaymentIntentAction::class);

        $action->execute($auction, $first->id, PaymentPurpose::BidderDeposit, $method->public_id);
        $theirs = $action->execute($auction, $second->id, PaymentPurpose::BidderDeposit, $method->public_id);

        $resolution = $this->resolve(
            $this->billingReferenceOf($first),
            new BillReference((string) $theirs->refresh()->provider_transaction_id)
        );

        $this->assertSame(BillRejectionReason::BillNotFound, $resolution->rejection);
    }

    public function test_presentment_never_creates_a_deposit_or_touches_the_obligation(): void
    {
        [$auction] = $this->paymentAuction(AuctionStatus::Live);
        [$payer] = $this->registeredBidder($auction);

        // No intent has been raised, so no billing reference exists for this payer.
        $this->assertNull(PaymentBillingReference::where('user_id', $payer->id)->first());

        $depositsBefore = \App\Models\Auction\AuctionDeposit::count();
        $this->resolve(new BillingReference('1234567890'));

        $this->assertSame($depositsBefore, \App\Models\Auction\AuctionDeposit::count());
    }

    public function test_the_endpoint_answers_both_query_shapes_in_the_providers_own_envelope(): void
    {
        [$payer, $bills] = $this->payerWithTwoOpenBills();
        $reference = $this->billingReferenceOf($payer)->value;

        $all = $this->postBillQuery(['billing_reference' => $reference]);
        $all->assertOk();
        $this->assertSame(2, $all->json('count'));
        $this->assertNull($all->json('rejection'));

        $one = $this->postBillQuery([
            'billing_reference' => $reference,
            'bill_reference' => (string) $bills[0]->provider_transaction_id,
        ]);
        $one->assertOk();
        $this->assertSame(1, $one->json('count'));
        $this->assertSame('1.000', $one->json('bills.0.amount'));
        $this->assertFalse($one->json('bills.0.allows_partial'));
        $this->assertSame($one->json('bills.0.minimum'), $one->json('bills.0.maximum'));
    }

    public function test_an_unsigned_bill_query_is_refused_by_the_provider(): void
    {
        [$payer] = $this->payerWithTwoOpenBills();

        $response = $this->postJson(
            '/api/webhooks/payments/'.FakeBillPaymentProvider::CODE.'/bills',
            ['billing_reference' => $this->billingReferenceOf($payer)->value]
        );

        $response->assertStatus(400);
        $this->assertSame('query_rejected', $response->json('rejection'));
    }

    public function test_a_provider_that_does_not_present_bills_has_no_lookup_endpoint(): void
    {
        $this->enableFakeProvider();

        $this->postJson('/api/webhooks/payments/fake/bills', ['billing_reference' => '1234567890'])
            ->assertStatus(404);
    }

    public function test_a_claim_number_alone_resolves_the_payer_and_the_bill(): void
    {
        [$payer, $bills] = $this->payerWithTwoOpenBills();
        $target = $bills[1];

        // No billing reference is quoted at all: the claim number is the only
        // identifier a one-off scheme has.
        $resolution = $this->resolve(null, new BillReference((string) $target->provider_transaction_id));

        $this->assertNull($resolution->rejection);
        $this->assertSame(1, $resolution->count());

        $bill = $resolution->sole();
        $this->assertSame((string) $target->provider_transaction_id, $bill?->billReference->value);
        $this->assertNull($bill?->billingReference);
        $this->assertSame($payer->name, $bill?->payerDisplayName);
    }

    public function test_a_claim_only_lookup_never_reads_the_billing_reference_table(): void
    {
        [, $bills] = $this->payerWithTwoOpenBills();
        PaymentBillingReference::query()->delete();

        $resolution = $this->resolve(null, new BillReference((string) $bills[0]->provider_transaction_id));

        $this->assertNull($resolution->rejection);
        $this->assertSame(1, $resolution->count());
    }

    public function test_an_unknown_claim_number_alone_is_not_found_rather_than_unknown_payer(): void
    {
        $this->payerWithTwoOpenBills();

        $this->assertSame(
            BillRejectionReason::BillNotFound,
            $this->resolve(null, new BillReference('999999999999'))->rejection
        );
    }

    public function test_a_settled_claim_is_reported_as_paid_not_as_missing(): void
    {
        [$payer, $bills] = $this->payerWithTwoOpenBills();
        $paid = $bills[0];
        $paid->forceFill(['status' => PaymentTransactionStatus::Succeeded])->save();

        $reference = new BillReference((string) $paid->provider_transaction_id);

        $this->assertSame(
            BillRejectionReason::BillAlreadyPaid,
            $this->resolve($this->billingReferenceOf($payer), $reference)->rejection
        );
        $this->assertSame(
            BillRejectionReason::BillAlreadyPaid,
            $this->resolve(null, $reference)->rejection
        );
    }

    public function test_a_query_carrying_no_reference_at_all_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new BillQuery(providerCode: FakeBillPaymentProvider::CODE);
    }

    public function test_the_endpoint_answers_a_claim_only_query(): void
    {
        [, $bills] = $this->payerWithTwoOpenBills();

        $response = $this->postBillQuery([
            'bill_reference' => (string) $bills[0]->provider_transaction_id,
        ]);

        $response->assertOk();
        $this->assertSame(1, $response->json('count'));
        $this->assertNull($response->json('billing_reference'));
        $this->assertSame('1.000', $response->json('bills.0.principal'));
        $this->assertSame('0.000', $response->json('bills.0.fee'));
        $this->assertSame('1.000', $response->json('bills.0.amount'));
    }

    /**
     * @return array{0: User, 1: array<int, PaymentTransaction>}
     */
    private function payerWithTwoOpenBills(): array
    {
        [$first] = $this->paymentAuction(AuctionStatus::Live);
        [$second] = $this->paymentAuction(AuctionStatus::Live);
        [$payer] = $this->registeredBidder($first);
        $this->registerBidder($second, $payer);
        $method = $this->billPaymentMethod();
        $action = app(CreatePaymentIntentAction::class);

        return [$payer, [
            $action->execute($first, $payer->id, PaymentPurpose::BidderDeposit, $method->public_id)->refresh(),
            $action->execute($second, $payer->id, PaymentPurpose::BidderDeposit, $method->public_id)->refresh(),
        ]];
    }

    private function billingReferenceOf(User $payer): BillingReference
    {
        return PaymentBillingReference::where('user_id', $payer->id)
            ->where('provider', FakeBillPaymentProvider::CODE)
            ->firstOrFail()
            ->billingReference();
    }

    private function resolve(
        ?BillingReference $billingReference,
        ?BillReference $billReference = null,
        ?PaymentPurpose $purpose = null
    ) {
        return app(ResolveBillPresentmentAction::class)->execute(new BillQuery(
            providerCode: FakeBillPaymentProvider::CODE,
            billingReference: $billingReference,
            billReference: $billReference,
            purpose: $purpose,
            receivedAt: CarbonImmutable::now(),
        ));
    }

    private function postBillQuery(array $payload)
    {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $this->call(
            'POST',
            '/api/webhooks/payments/'.FakeBillPaymentProvider::CODE.'/bills',
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
