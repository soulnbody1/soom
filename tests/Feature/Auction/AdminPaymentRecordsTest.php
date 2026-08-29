<?php

declare(strict_types=1);

namespace Tests\Feature\Auction;

use App\Domain\Auction\Enums\AuctionStatus;
use App\Domain\Auction\Enums\PaymentPurpose;
use App\Domain\Auction\Enums\PaymentSubmissionStatus;
use App\Models\Auction\PaymentSubmission;
use App\Models\Auction\PaymentTransaction;
use App\Services\Auction\Actions\CreatePaymentIntentAction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\Feature\Auction\Concerns\BuildsOnlinePaymentFixtures;
use Tests\TestCase;

final class AdminPaymentRecordsTest extends TestCase
{
    use BuildsOnlinePaymentFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate', ['--force' => true]);
        $this->enableFakeProvider();
    }

    public function test_the_list_returns_online_and_manual_payments_together(): void
    {
        [$auction] = $this->paymentAuction(AuctionStatus::Live);
        $online = $this->succeededOnlineDeposit($auction);
        $manual = $this->pendingManualSubmission($auction);

        $response = $this->actingAs($this->paymentUser('admin'), 'sanctum')
            ->getJson('/api/admin/auctions/payments')
            ->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();

        $this->assertContains($online->public_id, $ids);
        $this->assertContains($manual->public_id, $ids);

        $onlineRow = collect($response->json('data'))->firstWhere('id', $online->public_id);
        $this->assertSame('online', $onlineRow['channel']);
        $this->assertSame('succeeded', $onlineRow['status']);
        $this->assertSame('fake', $onlineRow['provider']);
        $this->assertNotNull($onlineRow['succeeded_at']);
        $this->assertNotNull($onlineRow['provider_transaction_id']);

        $manualRow = collect($response->json('data'))->firstWhere('id', $manual->public_id);
        $this->assertSame('manual', $manualRow['channel']);
        $this->assertSame('pending_review', $manualRow['status']);
    }

    public function test_an_approved_manual_submission_appears_once_as_its_transaction(): void
    {
        [$auction] = $this->paymentAuction(AuctionStatus::Live);
        $submission = $this->pendingManualSubmission($auction);
        $transaction = PaymentTransaction::create([
            'payment_submission_id' => $submission->id,
            'auction_id' => $auction->id,
            'user_id' => $submission->user_id,
            'purpose' => PaymentPurpose::BidderDeposit,
            'status' => 'succeeded',
            'amount_minor' => $submission->amount_minor,
            'currency_code' => $submission->currency_code,
            'provider' => 'manual',
            'idempotency_key' => 'submission:'.$submission->id.':approved',
            'processed_at' => Carbon::now(),
        ]);
        $submission->forceFill(['status' => PaymentSubmissionStatus::Approved])->save();

        $ids = collect(
            $this->actingAs($this->paymentUser('admin'), 'sanctum')
                ->getJson('/api/admin/auctions/payments')
                ->assertOk()
                ->json('data')
        )->pluck('id');

        $this->assertSame(1, $ids->count());
        $this->assertSame($transaction->public_id, $ids->first());
    }

    public function test_filters_and_status_counts_cover_both_channels(): void
    {
        [$auction] = $this->paymentAuction(AuctionStatus::Live);
        $online = $this->succeededOnlineDeposit($auction);
        $this->pendingManualSubmission($auction);

        $admin = $this->paymentUser('admin');

        $counts = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/auctions/payments')
            ->assertOk()
            ->json('status_counts');

        $this->assertSame(1, $counts['succeeded']);
        $this->assertSame(1, $counts['pending_review']);

        $onlineOnly = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/auctions/payments?channel=online')
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $onlineOnly);
        $this->assertSame($online->public_id, $onlineOnly[0]['id']);

        $succeededOnly = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/auctions/payments?status=succeeded')
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $succeededOnly);
        $this->assertSame($online->public_id, $succeededOnly[0]['id']);
    }

    public function test_details_expose_the_financial_trail_without_provider_payload(): void
    {
        [$auction] = $this->paymentAuction(AuctionStatus::Live);
        $online = $this->succeededOnlineDeposit($auction);

        $detail = $this->actingAs($this->paymentUser('admin'), 'sanctum')
            ->getJson('/api/admin/auctions/payments/'.$online->public_id)
            ->assertOk()
            ->json('data');

        $this->assertSame('online', $detail['channel']);
        $this->assertSame('bidder', $detail['deposit']['type']);
        $this->assertNotNull($detail['deposit']['held_at']);
        $this->assertSame([], $detail['refunds']);
        $this->assertArrayNotHasKey('provider_payload', $detail);
        $this->assertArrayNotHasKey('checkout_instruction', $detail);
    }

    public function test_a_succeeded_online_deposit_can_start_a_refund_once(): void
    {
        [$auction] = $this->paymentAuction(AuctionStatus::Live);
        $online = $this->succeededOnlineDeposit($auction);
        $admin = $this->paymentUser('admin');

        $first = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/auctions/payments/'.$online->public_id.'/refund', ['reason' => 'admin refund'])
            ->assertCreated()
            ->json('data');

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/auctions/payments/'.$online->public_id.'/refund', ['reason' => 'admin refund'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'zero_refund_not_allowed');

        $detail = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/auctions/payments/'.$online->public_id)
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $detail['refunds']);
        $this->assertSame($first['id'], $detail['refunds'][0]['id']);
        $this->assertSame(1, $detail['refund']['count']);
        $this->assertSame('pending', $detail['refund']['latest_status']);
    }

    public function test_a_pending_manual_submission_is_not_refundable(): void
    {
        [$auction] = $this->paymentAuction(AuctionStatus::Live);
        $submission = $this->pendingManualSubmission($auction);

        $this->actingAs($this->paymentUser('admin'), 'sanctum')
            ->postJson('/api/admin/auctions/payments/'.$submission->public_id.'/refund', ['reason' => 'too early'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'refund_not_available');
    }

    public function test_auction_details_show_how_an_online_deposit_was_paid(): void
    {
        [$auction] = $this->paymentAuction(AuctionStatus::Live);
        $online = $this->succeededOnlineDeposit($auction);
        $admin = $this->paymentUser('admin');

        $deposits = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/auctions/'.$auction->public_id)
            ->assertOk()
            ->json('data.deposits');

        $bidderDeposit = collect($deposits)->firstWhere('type', 'bidder');

        $this->assertSame('online', $bidderDeposit['payment']['channel']);
        $this->assertSame('fake', $bidderDeposit['payment']['provider']);
        $this->assertSame($online->public_id, $bidderDeposit['payment']['id']);
        $this->assertNotNull($bidderDeposit['payment']['paid_at']);
        $this->assertNotNull($bidderDeposit['payment']['payment_method']['name']);
        $this->assertNotNull($bidderDeposit['held_at']);
        $this->assertSame([], $bidderDeposit['refunds']);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/auctions/payments/'.$online->public_id.'/refund', ['reason' => 'admin refund'])
            ->assertCreated();

        $refreshed = collect(
            $this->actingAs($admin, 'sanctum')
                ->getJson('/api/admin/auctions/'.$auction->public_id)
                ->assertOk()
                ->json('data.deposits')
        )->firstWhere('type', 'bidder');

        $this->assertCount(1, $refreshed['refunds']);
        $this->assertSame('pending', $refreshed['refunds'][0]['status']);
    }

    public function test_a_non_admin_cannot_read_payments(): void
    {
        $this->actingAs($this->paymentUser(), 'sanctum')
            ->getJson('/api/admin/auctions/payments')
            ->assertForbidden();
    }

    private function succeededOnlineDeposit($auction): PaymentTransaction
    {
        [$bidder] = $this->registeredBidder($auction);
        $method = $this->onlinePaymentMethod();
        $provider = $this->enableFakeProvider();

        $transaction = app(CreatePaymentIntentAction::class)
            ->execute($auction, $bidder->id, PaymentPurpose::BidderDeposit, $method->public_id);

        $provider->markSucceeded((string) $transaction->provider_transaction_id);
        $this->postWebhook($transaction, 'payment.succeeded')->assertOk();

        return $transaction->refresh();
    }

    private function postWebhook(PaymentTransaction $transaction, string $eventType)
    {
        $payload = [
            'event_id' => 'evt-'.$transaction->public_id.'-'.$eventType,
            'event_type' => $eventType,
            'provider_transaction_id' => (string) $transaction->refresh()->provider_transaction_id,
        ];

        return $this->postJson('/api/webhooks/payments/fake', $payload, [
            'X-Fake-Signature' => hash_hmac('sha256', json_encode($payload), 'test-webhook-secret'),
        ]);
    }

    private function pendingManualSubmission($auction): PaymentSubmission
    {
        [$bidder] = $this->registeredBidder($auction);

        return PaymentSubmission::create([
            'auction_id' => $auction->id,
            'user_id' => $bidder->id,
            'payment_method_id' => $this->manualPaymentMethod()->id,
            'purpose' => PaymentPurpose::BidderDeposit,
            'status' => PaymentSubmissionStatus::PendingReview,
            'amount_minor' => 1_000,
            'currency_code' => 'JOD',
            'receipt_disk' => 'spaces_private',
            'receipt_path' => 'receipts/'.Str::ulid().'.png',
            'receipt_mime_type' => 'image/png',
            'receipt_size_bytes' => 1_024,
            'idempotency_key' => (string) Str::ulid(),
            'submitted_at' => Carbon::now(),
        ]);
    }
}
