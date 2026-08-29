<?php

declare(strict_types=1);

namespace Tests\Feature\Auction;

use App\Domain\Auction\Enums\AuctionDepositStatus;
use App\Domain\Auction\Enums\AuctionParticipantStatus;
use App\Domain\Auction\Enums\AuctionStatus;
use App\Domain\Auction\Enums\PaymentPurpose;
use App\Domain\Auction\Enums\PaymentTransactionStatus;
use App\Domain\Auction\Enums\RefundTransactionStatus;
use App\Domain\Auction\Exceptions\AuctionException;
use App\Models\Auction\AuctionDeposit;
use App\Models\Auction\AuctionParticipant;
use App\Models\Auction\PaymentProviderEvent;
use App\Models\Auction\PaymentTransaction;
use App\Models\Auction\RefundTransaction;
use App\Services\Auction\Actions\CreatePaymentIntentAction;
use App\Services\Auction\Actions\ProcessAuctionRefundAction;
use App\Services\Auction\Actions\RefundAuctionDepositAction;
use App\Services\Auction\Payments\PaymentProviderFactory;
use App\Services\Auction\Payments\Providers\NGeniusPaymentProvider;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Auction\Concerns\BuildsOnlinePaymentFixtures;
use Tests\Feature\Auction\Concerns\FakesNGenius;
use Tests\TestCase;

final class NGeniusPaymentProviderTest extends TestCase
{
    use BuildsOnlinePaymentFixtures;
    use FakesNGenius;

    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate', ['--force' => true]);
        $this->configureNGenius();
    }

    public function test_authentication_failure_closes_the_intent_and_never_leaves_it_pending(): void
    {
        Http::fake([$this->ngeniusTokenUrl() => Http::response(['error' => 'unauthorized'], 401)]);

        [$auction, $bidder] = $this->liveAuctionWithBidder();

        try {
            app(CreatePaymentIntentAction::class)
                ->execute($auction, $bidder->id, PaymentPurpose::BidderDeposit, $this->ngeniusMethod()->public_id);
            $this->fail('Authentication failure must abort the checkout.');
        } catch (AuctionException $exception) {
            $this->assertSame('payment_provider_unavailable', $exception->getErrorCode());
        }

        $transaction = PaymentTransaction::where('auction_id', $auction->id)->firstOrFail();
        $this->assertSame(PaymentTransactionStatus::Failed, $transaction->status);
        $this->assertSame('checkout_unavailable', $transaction->failure_code);
    }

    public function test_create_checkout_sends_the_merchant_reference_and_minor_units(): void
    {
        [$auction, $bidder] = $this->liveAuctionWithBidder();
        $this->fakeNGenius('order-1');

        $transaction = app(CreatePaymentIntentAction::class)
            ->execute($auction, $bidder->id, PaymentPurpose::BidderDeposit, $this->ngeniusMethod()->public_id);

        $this->assertSame('order-1', $transaction->provider_transaction_id);
        $this->assertStringContainsString('paypage', (string) $transaction->checkout_instruction['redirect_url']);

        Http::assertSent(function ($request) use ($transaction): bool {
            if (! str_contains($request->url(), '/orders')) {
                return true;
            }

            $body = $request->data();

            return $body['action'] === 'PURCHASE'
                && $body['amount']['currencyCode'] === 'JOD'
                && $body['amount']['value'] === 1_000
                && $body['merchantOrderReference'] === $transaction->public_id
                && $body['merchantDefinedData']['soomPaymentReference'] === $transaction->public_id;
        });
    }

    public function test_jod_amounts_are_sent_as_three_decimal_minor_units_without_rounding(): void
    {
        [$auction, $bidder] = $this->liveAuctionWithBidder(bidderDeposit: 10_501);
        $this->fakeNGenius('order-jod', amountMinor: 10_501);

        app(CreatePaymentIntentAction::class)
            ->execute($auction, $bidder->id, PaymentPurpose::BidderDeposit, $this->ngeniusMethod()->public_id);

        Http::assertSent(function ($request): bool {
            if (! str_contains($request->url(), '/orders') || $request->method() !== 'POST') {
                return true;
            }

            return $request->data()['amount']['value'] === 10_501;
        });
    }

    public function test_a_purchased_order_qualifies_the_bidder_through_a_webhook(): void
    {
        [$auction, $bidder] = $this->liveAuctionWithBidder();
        $this->fakeNGenius('order-2');

        $transaction = app(CreatePaymentIntentAction::class)
            ->execute($auction, $bidder->id, PaymentPurpose::BidderDeposit, $this->ngeniusMethod()->public_id);

        $this->postNGeniusWebhook($this->ngeniusWebhook('order-2', (string) $transaction->public_id, 'evt-1'))
            ->assertOk()
            ->assertJsonPath('data.result', 'processed');

        $transaction->refresh();
        $this->assertSame(PaymentTransactionStatus::Succeeded, $transaction->status);
        $this->assertSame("deposit:{$this->depositFor($auction->id, $bidder->id)->id}", $transaction->successful_obligation_key);

        $this->assertSame(AuctionDepositStatus::Held, $this->depositFor($auction->id, $bidder->id)->status);
        $this->assertSame(
            AuctionParticipantStatus::Qualified,
            AuctionParticipant::where('auction_id', $auction->id)->where('user_id', $bidder->id)->firstOrFail()->status
        );
    }

    public function test_an_encrypted_webhook_body_is_decrypted_and_processed(): void
    {
        [$auction, $bidder] = $this->liveAuctionWithBidder();
        $this->fakeNGenius('order-enc');

        $transaction = app(CreatePaymentIntentAction::class)
            ->execute($auction, $bidder->id, PaymentPurpose::BidderDeposit, $this->ngeniusMethod()->public_id);

        $this->postNGeniusWebhook(
            $this->ngeniusWebhook('order-enc', (string) $transaction->public_id, 'evt-enc'),
            encrypted: true
        )->assertOk()->assertJsonPath('data.result', 'processed');

        $this->assertSame(PaymentTransactionStatus::Succeeded, $transaction->refresh()->status);
    }

    public function test_a_wrong_webhook_secret_is_rejected_without_effect(): void
    {
        [$auction, $bidder] = $this->liveAuctionWithBidder();
        $this->fakeNGenius('order-3');

        $transaction = app(CreatePaymentIntentAction::class)
            ->execute($auction, $bidder->id, PaymentPurpose::BidderDeposit, $this->ngeniusMethod()->public_id);

        $this->postNGeniusWebhook(
            $this->ngeniusWebhook('order-3', (string) $transaction->public_id, 'evt-bad'),
            secret: 'the-wrong-secret-value-000000000'
        )->assertStatus(401);

        $this->assertSame(PaymentTransactionStatus::Pending, $transaction->refresh()->status);
        $this->assertSame('signature_invalid', PaymentProviderEvent::where('provider', 'ngenius')->firstOrFail()->process_error);
    }

    public function test_duplicate_webhooks_apply_the_obligation_once(): void
    {
        [$auction, $bidder] = $this->liveAuctionWithBidder();
        $this->fakeNGenius('order-4');

        $transaction = app(CreatePaymentIntentAction::class)
            ->execute($auction, $bidder->id, PaymentPurpose::BidderDeposit, $this->ngeniusMethod()->public_id);
        $payload = $this->ngeniusWebhook('order-4', (string) $transaction->public_id, 'evt-dup');

        $this->postNGeniusWebhook($payload)->assertOk()->assertJsonPath('data.result', 'processed');
        $this->postNGeniusWebhook($payload)->assertOk()->assertJsonPath('data.result', 'duplicate');

        $this->assertSame(1_000, (int) $this->depositFor($auction->id, $bidder->id)->held_amount_minor);
        $this->assertSame(1, PaymentProviderEvent::where('provider', 'ngenius')->count());
    }

    public function test_a_declined_order_fails_the_payment_and_leaves_the_deposit_unpaid(): void
    {
        [$auction, $bidder] = $this->liveAuctionWithBidder();
        $this->fakeNGenius('order-5', state: 'FAILED', resultCode: '05');

        $transaction = app(CreatePaymentIntentAction::class)
            ->execute($auction, $bidder->id, PaymentPurpose::BidderDeposit, $this->ngeniusMethod()->public_id);

        $this->postNGeniusWebhook($this->ngeniusWebhook('order-5', (string) $transaction->public_id, 'evt-5', 'DECLINED'))->assertOk();

        $transaction->refresh();
        $this->assertSame(PaymentTransactionStatus::Failed, $transaction->status);
        $this->assertSame('failed:05', $transaction->failure_code);
        $this->assertSame(AuctionDepositStatus::PendingSubmission, $this->depositFor($auction->id, $bidder->id)->status);
    }

    public function test_an_awaiting_three_ds_order_stays_pending(): void
    {
        [$auction, $bidder] = $this->liveAuctionWithBidder();
        $this->fakeNGenius('order-6', state: 'AWAIT_3DS', resultCode: null);

        $transaction = app(CreatePaymentIntentAction::class)
            ->execute($auction, $bidder->id, PaymentPurpose::BidderDeposit, $this->ngeniusMethod()->public_id);

        $this->postNGeniusWebhook($this->ngeniusWebhook('order-6', (string) $transaction->public_id, 'evt-6', 'AWAIT_3DS'))->assertOk();

        $this->assertSame(PaymentTransactionStatus::Pending, $transaction->refresh()->status);
    }

    public function test_an_authorised_purchase_order_is_not_treated_as_captured(): void
    {
        [$auction, $bidder] = $this->liveAuctionWithBidder();
        $this->fakeNGenius('order-auth', state: 'AUTHORISED');

        $transaction = app(CreatePaymentIntentAction::class)
            ->execute($auction, $bidder->id, PaymentPurpose::BidderDeposit, $this->ngeniusMethod()->public_id);

        $this->postNGeniusWebhook($this->ngeniusWebhook('order-auth', (string) $transaction->public_id, 'evt-auth', 'AUTHORISED'))->assertOk();

        $this->assertSame(PaymentTransactionStatus::Pending, $transaction->refresh()->status);
        $this->assertSame(AuctionDepositStatus::PendingSubmission, $this->depositFor($auction->id, $bidder->id)->status);
    }

    public function test_a_cancelled_order_closes_the_payment_as_cancelled(): void
    {
        [$auction, $bidder] = $this->liveAuctionWithBidder();
        $this->fakeNGenius('order-7', state: 'CANCELLED', resultCode: null);

        $transaction = app(CreatePaymentIntentAction::class)
            ->execute($auction, $bidder->id, PaymentPurpose::BidderDeposit, $this->ngeniusMethod()->public_id);

        $this->postNGeniusWebhook($this->ngeniusWebhook('order-7', (string) $transaction->public_id, 'evt-7', 'CANCELLED'))->assertOk();

        $this->assertSame(PaymentTransactionStatus::Cancelled, $transaction->refresh()->status);
    }

    public function test_an_orphan_checkout_is_recovered_through_the_merchant_defined_reference(): void
    {
        [$auction, $bidder] = $this->liveAuctionWithBidder();
        $this->fakeNGenius('order-8');

        $transaction = app(CreatePaymentIntentAction::class)
            ->execute($auction, $bidder->id, PaymentPurpose::BidderDeposit, $this->ngeniusMethod()->public_id);

        $transaction->forceFill(['provider_transaction_id' => null, 'checkout_instruction' => null])->save();

        $this->postNGeniusWebhook($this->ngeniusWebhook('order-8', (string) $transaction->public_id, 'evt-8'))
            ->assertOk()
            ->assertJsonPath('data.result', 'processed');

        $transaction->refresh();
        $this->assertSame('order-8', $transaction->provider_transaction_id);
        $this->assertSame(PaymentTransactionStatus::Succeeded, $transaction->status);
        $this->assertSame(AuctionDepositStatus::Held, $this->depositFor($auction->id, $bidder->id)->status);
    }

    public function test_a_foreign_order_reference_is_refused_for_a_bound_payment(): void
    {
        [$auction, $bidder] = $this->liveAuctionWithBidder();
        $this->fakeNGenius('order-9');

        $transaction = app(CreatePaymentIntentAction::class)
            ->execute($auction, $bidder->id, PaymentPurpose::BidderDeposit, $this->ngeniusMethod()->public_id);

        $this->postNGeniusWebhook($this->ngeniusWebhook('order-foreign', (string) $transaction->public_id, 'evt-9'))
            ->assertStatus(422);

        $this->assertSame(PaymentTransactionStatus::Pending, $transaction->refresh()->status);
    }

    public function test_an_amount_mismatch_records_the_captured_amount_and_plans_a_refund(): void
    {
        [$auction, $bidder] = $this->liveAuctionWithBidder();
        $this->fakeNGenius('order-10', amountMinor: 1_400);

        $transaction = app(CreatePaymentIntentAction::class)
            ->execute($auction, $bidder->id, PaymentPurpose::BidderDeposit, $this->ngeniusMethod()->public_id);

        $this->postNGeniusWebhook($this->ngeniusWebhook('order-10', (string) $transaction->public_id, 'evt-10'))->assertOk();

        $transaction->refresh();
        $this->assertSame('provider_amount_mismatch', $transaction->failure_code);
        $this->assertSame(1_400, (int) $transaction->captured_amount_minor);
        $this->assertNull($transaction->successful_obligation_key);

        $refund = RefundTransaction::where('payment_transaction_id', $transaction->id)->firstOrFail();
        $this->assertSame(1_400, (int) $refund->amount_minor);
        $this->assertSame('ngenius', $refund->provider);
    }

    public function test_a_currency_mismatch_records_the_captured_currency(): void
    {
        [$auction, $bidder] = $this->liveAuctionWithBidder();
        $this->fakeNGenius('order-11', currency: 'USD');

        $transaction = app(CreatePaymentIntentAction::class)
            ->execute($auction, $bidder->id, PaymentPurpose::BidderDeposit, $this->ngeniusMethod()->public_id);

        $this->postNGeniusWebhook($this->ngeniusWebhook('order-11', (string) $transaction->public_id, 'evt-11'))->assertOk();

        $transaction->refresh();
        $this->assertSame('provider_currency_mismatch', $transaction->failure_code);
        $this->assertSame('USD', $transaction->captured_currency_code);
        $this->assertNull($transaction->successful_obligation_key);
    }

    public function test_a_late_success_is_recorded_and_automatically_refunded(): void
    {
        [$auction, $bidder] = $this->liveAuctionWithBidder();
        $this->fakeNGenius('order-12');

        $transaction = app(CreatePaymentIntentAction::class)
            ->execute($auction, $bidder->id, PaymentPurpose::BidderDeposit, $this->ngeniusMethod()->public_id);

        $auction->forceFill(['ends_at' => now()->subMinute(), 'original_ends_at' => now()->subMinute()])->save();

        $this->postNGeniusWebhook($this->ngeniusWebhook('order-12', (string) $transaction->public_id, 'evt-12'))->assertOk();

        $transaction->refresh();
        $this->assertSame(PaymentTransactionStatus::Succeeded, $transaction->status);
        $this->assertNull($transaction->successful_obligation_key);

        $refund = RefundTransaction::where('payment_transaction_id', $transaction->id)->firstOrFail();
        $this->assertSame('obligation_no_longer_payable', $refund->reason);
        $this->assertSame('ngenius', $refund->provider);
    }

    public function test_a_full_refund_is_executed_against_the_original_capture(): void
    {
        [$auction, $bidder, $transaction] = $this->paidDeposit('order-13');

        $refund = app(RefundAuctionDepositAction::class)
            ->execute($this->depositFor($auction->id, $bidder->id), 'test_refund');

        $processed = app(ProcessAuctionRefundAction::class)->execute($refund);

        $this->assertSame(RefundTransactionStatus::Succeeded, $processed->status);
        $this->assertSame('refund-1', $processed->provider_refund_id);
        $this->assertSame(AuctionDepositStatus::Refunded, $this->depositFor($auction->id, $bidder->id)->status);

        Http::assertSent(function ($request): bool {
            if (! str_contains($request->url(), '/refund')) {
                return true;
            }

            return $request->data()['amount']['value'] === 1_000
                && $request->data()['amount']['currencyCode'] === 'JOD';
        });
    }

    public function test_a_partial_refund_sends_only_the_requested_amount(): void
    {
        [$auction, $bidder, $transaction] = $this->paidDeposit('order-14');

        $refund = RefundTransaction::create([
            'auction_id' => $auction->id,
            'deposit_id' => null,
            'payment_transaction_id' => $transaction->id,
            'obligation_type' => 'deposit',
            'obligation_id' => null,
            'user_id' => $bidder->id,
            'status' => RefundTransactionStatus::Pending,
            'amount_minor' => 400,
            'held_refund_amount_minor' => 0,
            'applied_refund_amount_minor' => 0,
            'currency_code' => 'JOD',
            'reason' => 'partial_test',
            'provider' => 'ngenius',
            'idempotency_key' => 'ngenius-partial-1',
        ]);

        $processed = app(ProcessAuctionRefundAction::class)->execute($refund);

        $this->assertSame(RefundTransactionStatus::Succeeded, $processed->status);

        Http::assertSent(function ($request): bool {
            if (! str_contains($request->url(), '/refund')) {
                return true;
            }

            return $request->data()['amount']['value'] === 400;
        });
    }

    public function test_a_disabled_provider_blocks_checkout_but_not_webhooks(): void
    {
        [$auction, $bidder] = $this->liveAuctionWithBidder();
        $this->fakeNGenius('order-15');
        $method = $this->ngeniusMethod();

        $transaction = app(CreatePaymentIntentAction::class)
            ->execute($auction, $bidder->id, PaymentPurpose::BidderDeposit, $method->public_id);

        config(['auction.payments.disabled_providers' => ['ngenius']]);

        $this->postNGeniusWebhook($this->ngeniusWebhook('order-15', (string) $transaction->public_id, 'evt-15'))->assertOk();
        $this->assertSame(PaymentTransactionStatus::Succeeded, $transaction->refresh()->status);

        [, $other] = $this->liveAuctionWithBidder();

        $this->expectException(AuctionException::class);
        app(CreatePaymentIntentAction::class)
            ->execute($auction, $other->id, PaymentPurpose::BidderDeposit, $method->public_id);
    }

    public function test_a_live_method_cannot_run_on_a_sandbox_provider(): void
    {
        [$auction, $bidder] = $this->liveAuctionWithBidder();
        $this->fakeNGenius('order-16');

        try {
            app(CreatePaymentIntentAction::class)->execute(
                $auction,
                $bidder->id,
                PaymentPurpose::BidderDeposit,
                $this->ngeniusMethod(['is_sandbox' => false])->public_id
            );
            $this->fail('A live method must not run against sandbox credentials.');
        } catch (AuctionException $exception) {
            $this->assertSame('payment_provider_environment_mismatch', $exception->getErrorCode());
        }
    }

    public function test_test_connection_reports_authentication_failure_without_creating_a_charge(): void
    {
        Http::fake([$this->ngeniusTokenUrl() => Http::response(['error' => 'unauthorized'], 401)]);
        $admin = $this->paymentUser('admin');

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/auctions/payment-providers/ngenius/test')
            ->assertOk()
            ->assertJsonPath('data.ok', false)
            ->assertJsonPath('data.error_code', 'provider_unavailable');

        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/orders'));
    }

    public function test_test_connection_succeeds_with_valid_credentials(): void
    {
        Http::fake([$this->ngeniusTokenUrl() => Http::response($this->ngeniusTokenResponse())]);
        $admin = $this->paymentUser('admin');

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/auctions/payment-providers/ngenius/test')
            ->assertOk()
            ->assertJsonPath('data.ok', true);
    }

    public function test_the_provider_never_exposes_credentials(): void
    {
        Http::fake([$this->ngeniusTokenUrl() => Http::response($this->ngeniusTokenResponse())]);
        $admin = $this->paymentUser('admin');

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/admin/auctions/payment-providers');

        $response->assertOk();
        $this->assertStringNotContainsString('test-api-key', $response->getContent());
        $this->assertStringNotContainsString($this->ngeniusSecret, $response->getContent());
    }

    public function test_the_provider_declares_jod_minor_units(): void
    {
        $capabilities = app(PaymentProviderFactory::class)->make(NGeniusPaymentProvider::CODE)->capabilities();

        $this->assertSame('minor_units', $capabilities->amountFormat->value);
        $this->assertTrue($capabilities->supportsCurrency('JOD'));
        $this->assertTrue($capabilities->supportsRefund);
        $this->assertTrue($capabilities->supportsPartialRefund);
    }

    private function fakeNGenius(
        string $orderReference,
        string $state = 'PURCHASED',
        int $amountMinor = 1_000,
        string $currency = 'JOD',
        ?string $resultCode = '00'
    ): void {
        $created = [];

        Http::fake(function ($request) use (&$created, $orderReference, $state, $amountMinor, $currency, $resultCode) {
            $url = $request->url();

            if (str_contains($url, '/identity/auth/access-token')) {
                return Http::response($this->ngeniusTokenResponse());
            }

            if (str_contains($url, '/refund')) {
                return Http::response($this->ngeniusRefundResponse('refund-1'));
            }

            if ($request->method() === 'POST' && str_ends_with($url, '/orders')) {
                $created[$orderReference] = (string) ($request->data()['merchantDefinedData']['soomPaymentReference'] ?? '');

                return Http::response($this->ngeniusOrderResponse(
                    $orderReference,
                    $created[$orderReference],
                    'STARTED',
                    $amountMinor,
                    $currency,
                    null,
                    false
                ));
            }

            $segments = explode('/', rtrim(parse_url($url, PHP_URL_PATH) ?: '', '/'));
            $requested = (string) end($segments);

            return Http::response($this->ngeniusOrderResponse(
                $requested,
                $created[$requested] ?? ($created[$orderReference] ?? ''),
                $state,
                $amountMinor,
                $currency,
                $resultCode
            ));
        });
    }

    private function liveAuctionWithBidder(int $bidderDeposit = 1_000): array
    {
        [$auction] = $this->paymentAuction(
            AuctionStatus::Live,
            ['bidder_deposit_amount_minor' => $bidderDeposit],
            ['bidder_deposit_minor' => $bidderDeposit]
        );
        [$bidder] = $this->registeredBidder($auction);

        return [$auction, $bidder];
    }

    private function paidDeposit(string $orderReference): array
    {
        [$auction, $bidder] = $this->liveAuctionWithBidder();
        $this->fakeNGenius($orderReference);

        $transaction = app(CreatePaymentIntentAction::class)
            ->execute($auction, $bidder->id, PaymentPurpose::BidderDeposit, $this->ngeniusMethod()->public_id);

        $this->postNGeniusWebhook($this->ngeniusWebhook($orderReference, (string) $transaction->public_id, 'evt-'.$orderReference))->assertOk();

        return [$auction, $bidder, $transaction->refresh()];
    }

    private function depositFor(int $auctionId, int $userId): AuctionDeposit
    {
        return AuctionDeposit::where('auction_id', $auctionId)->where('user_id', $userId)->firstOrFail();
    }
}
