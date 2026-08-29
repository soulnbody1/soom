<?php

declare(strict_types=1);

namespace Tests\Feature\Auction;

use App\Domain\Auction\Enums\AuctionDepositStatus;
use App\Domain\Auction\Enums\AuctionStatus;
use App\Domain\Auction\Enums\PaymentPurpose;
use App\Domain\Auction\Enums\PaymentTransactionStatus;
use App\Domain\Auction\Enums\RefundTransactionStatus;
use App\Domain\Auction\Exceptions\AuctionException;
use App\Models\Auction\AuctionDeposit;
use App\Models\Auction\PaymentSubmission;
use App\Models\Auction\PaymentTransaction;
use App\Models\Auction\RefundTransaction;
use App\Services\Auction\Actions\CreatePaymentIntentAction;
use App\Services\Auction\Actions\ProcessAuctionRefundAction;
use App\Services\Auction\Actions\RefundAuctionDepositAction;
use App\Services\Auction\Payments\PaymentProviderFactory;
use App\Services\Auction\Refunds\ManualReviewRefundProcessor;
use App\Services\Auction\Refunds\ProviderRefundProcessor;
use App\Services\Auction\Refunds\RefundProcessorFactory;
use Illuminate\Support\Facades\Artisan;
use Tests\Feature\Auction\Concerns\BuildsOnlinePaymentFixtures;
use Tests\TestCase;

final class OnlinePaymentGuardsTest extends TestCase
{
    use BuildsOnlinePaymentFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate', ['--force' => true]);
        $this->enableFakeProvider();
    }

    public function test_fake_provider_is_unavailable_when_not_explicitly_allowed(): void
    {
        config(['auction.payments.allow_fake_provider' => false]);

        $factory = app(PaymentProviderFactory::class);

        $this->assertFalse($factory->isEnabled('fake'));

        try {
            $factory->make('fake');
            $this->fail('The fake provider must not resolve without an explicit allowance.');
        } catch (AuctionException $exception) {
            $this->assertSame('payment_provider_not_available', $exception->getErrorCode());
        }
    }

    public function test_fake_provider_is_unavailable_outside_local_and_testing_environments(): void
    {
        config(['auction.payments.allow_fake_provider' => true]);
        app()->detectEnvironment(fn (): string => 'production');

        try {
            $factory = new PaymentProviderFactory(app());

            $this->assertFalse($factory->isEnabled('fake'));

            try {
                $factory->make('fake');
                $this->fail('The fake provider must never resolve in production.');
            } catch (AuctionException $exception) {
                $this->assertSame('payment_provider_not_available', $exception->getErrorCode());
            }
        } finally {
            app()->detectEnvironment(fn (): string => 'testing');
        }
    }

    public function test_provider_status_never_exposes_credentials(): void
    {
        $admin = $this->paymentUser('admin');

        $response = $this->actingAs($admin)->getJson('/api/admin/auctions/payment-providers');

        $response->assertOk()->assertJsonPath('data.0.code', 'fake');

        $body = $response->getContent();
        $this->assertStringNotContainsString('test-webhook-secret', $body);
        $this->assertStringNotContainsString('webhook_secret', $body);
        $this->assertStringNotContainsString('api_key', $body);
    }

    public function test_admin_payment_method_listing_never_exposes_provider_secrets(): void
    {
        $admin = $this->paymentUser('admin');
        $this->onlinePaymentMethod();

        $response = $this->actingAs($admin)->getJson('/api/admin/auctions/payment-methods');

        $response->assertOk()->assertJsonPath('data.0.channel', 'online');
        $this->assertStringNotContainsString('test-webhook-secret', $response->getContent());
    }

    public function test_refund_of_an_online_payment_is_routed_to_the_provider_processor(): void
    {
        $refund = $this->succeededOnlineDepositRefund();

        $this->assertSame('fake', $refund->provider);
        $this->assertInstanceOf(ProviderRefundProcessor::class, app(RefundProcessorFactory::class)->forRefund($refund));
    }

    public function test_manual_refund_still_uses_the_manual_processor(): void
    {
        $refund = new RefundTransaction(['provider' => 'manual']);

        $this->assertInstanceOf(ManualReviewRefundProcessor::class, app(RefundProcessorFactory::class)->forRefund($refund));
    }

    public function test_provider_refund_succeeds_without_requiring_a_payout_destination(): void
    {
        $refund = $this->succeededOnlineDepositRefund();

        $processed = app(ProcessAuctionRefundAction::class)->execute($refund);

        $this->assertSame(RefundTransactionStatus::Succeeded, $processed->status);
        $this->assertNotNull($processed->provider_refund_id);
        $this->assertNull($processed->destination_id);
        $this->assertSame(AuctionDepositStatus::Refunded, AuctionDeposit::findOrFail($refund->deposit_id)->status);
    }

    public function test_provider_refund_failure_moves_the_refund_to_manual_review(): void
    {
        $provider = $this->enableFakeProvider();
        $refund = $this->succeededOnlineDepositRefund($provider);
        $payment = PaymentTransaction::findOrFail($refund->payment_transaction_id);
        $provider->scriptRefund((string) $payment->provider_transaction_id, 'failed');

        $processed = app(ProcessAuctionRefundAction::class)->execute($refund);

        $this->assertSame(RefundTransactionStatus::ManualReview, $processed->status);
        $this->assertNull($processed->provider_refund_id);
    }

    public function test_no_admin_path_can_mark_an_online_transaction_succeeded(): void
    {
        [$auction] = $this->paymentAuction(AuctionStatus::Live);
        [$bidder] = $this->registeredBidder($auction);
        $method = $this->onlinePaymentMethod();
        $admin = $this->paymentUser('admin');

        $transaction = app(CreatePaymentIntentAction::class)
            ->execute($auction, $bidder->id, PaymentPurpose::BidderDeposit, $method->public_id);

        $this->assertNull($transaction->payment_submission_id);
        $this->assertSame(0, PaymentSubmission::where('auction_id', $auction->id)->count());

        $this->actingAs($admin)
            ->postJson("/api/admin/auctions/payment-submissions/{$transaction->public_id}/review", [
                'decision' => 'approve',
            ])
            ->assertNotFound();

        $this->assertSame(PaymentTransactionStatus::Pending, $transaction->refresh()->status);
        $this->assertNull($transaction->successful_obligation_key);
        $this->assertSame(
            AuctionDepositStatus::PendingSubmission,
            AuctionDeposit::where('auction_id', $auction->id)->where('user_id', $bidder->id)->firstOrFail()->status
        );
    }

    private function succeededOnlineDepositRefund($provider = null): RefundTransaction
    {
        $provider ??= app(\App\Services\Auction\Payments\Providers\FakePaymentProvider::class);

        [$auction] = $this->paymentAuction(AuctionStatus::Live);
        [$bidder] = $this->registeredBidder($auction);
        $method = $this->onlinePaymentMethod();

        $transaction = app(CreatePaymentIntentAction::class)
            ->execute($auction, $bidder->id, PaymentPurpose::BidderDeposit, $method->public_id);

        $provider->markSucceeded((string) $transaction->provider_transaction_id);

        $payload = [
            'event_id' => 'evt-refund-'.$transaction->public_id,
            'event_type' => 'payment.succeeded',
            'provider_transaction_id' => (string) $transaction->refresh()->provider_transaction_id,
        ];

        $this->postJson('/api/webhooks/payments/fake', $payload, [
            'X-Fake-Signature' => hash_hmac('sha256', json_encode($payload), 'test-webhook-secret'),
        ])->assertOk();

        $this->assertSame(PaymentTransactionStatus::Succeeded, $transaction->refresh()->status);

        $deposit = AuctionDeposit::where('auction_id', $auction->id)->where('user_id', $bidder->id)->firstOrFail();

        return app(RefundAuctionDepositAction::class)->execute($deposit, 'test_refund');
    }
}
