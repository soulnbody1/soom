<?php

declare(strict_types=1);

namespace Tests\Feature\Auction;

use App\Domain\Auction\Enums\AuctionStatus;
use App\Domain\Auction\Enums\PaymentPurpose;
use App\Domain\Auction\Enums\PaymentTransactionStatus;
use App\Domain\Auction\Enums\RefundTransactionStatus;
use App\Models\Auction\AuctionTermsAcceptance;
use App\Models\Auction\AuctionTermsVersion;
use App\Models\Auction\PaymentTransaction;
use App\Models\Auction\RefundTransaction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\Feature\Auction\Concerns\AcceptsAuctionTerms;
use Tests\Feature\Auction\Concerns\BuildsOnlinePaymentFixtures;
use Tests\TestCase;

final class TermsAcceptanceFlowTest extends TestCase
{
    use AcceptsAuctionTerms;
    use BuildsOnlinePaymentFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate', ['--force' => true]);
        $this->enableFakeProvider();
    }

    public function test_submitting_for_review_records_the_seller_terms_acceptance(): void
    {
        [$auction, $seller] = $this->draftAuction();

        $this->actingAs($seller, 'sanctum')
            ->postJson('/api/soom/auctions/'.$auction->public_id.'/submit-review', $this->termsBody($auction))
            ->assertOk();

        $acceptance = AuctionTermsAcceptance::where('auction_id', $auction->id)
            ->where('user_id', $seller->id)
            ->sole();

        $this->assertNull($acceptance->participant_id);
        $this->assertSame((int) $auction->terms_version_id, (int) $acceptance->terms_version_id);
        $this->assertNotNull($acceptance->accepted_at);
        $this->assertNotNull($acceptance->ip_hash);
        $this->assertSame(AuctionStatus::PendingReview, $auction->refresh()->status);
    }

    public function test_submitting_for_review_with_a_foreign_terms_version_is_rejected(): void
    {
        [$auction, $seller] = $this->draftAuction();
        $other = AuctionTermsVersion::create([
            'version_number' => ((int) AuctionTermsVersion::max('version_number')) + 1,
            'title' => 'Other terms',
            'body' => 'Other terms body.',
            'is_active' => false,
            'published_at' => Carbon::now()->subDay(),
        ]);

        $this->actingAs($seller, 'sanctum')
            ->postJson('/api/soom/auctions/'.$auction->public_id.'/submit-review', ['terms_version_id' => $other->public_id])
            ->assertStatus(409)
            ->assertJsonPath('code', 'terms_version_mismatch');

        $this->assertSame(0, AuctionTermsAcceptance::where('auction_id', $auction->id)->count());
        $this->assertSame(AuctionStatus::Draft, $auction->refresh()->status);
    }

    public function test_submitting_for_review_twice_is_idempotent(): void
    {
        [$auction, $seller] = $this->draftAuction();

        $this->actingAs($seller, 'sanctum')
            ->postJson('/api/soom/auctions/'.$auction->public_id.'/submit-review', $this->termsBody($auction))
            ->assertOk();

        $this->actingAs($seller, 'sanctum')
            ->postJson('/api/soom/auctions/'.$auction->public_id.'/submit-review', $this->termsBody($auction))
            ->assertOk()
            ->assertJsonPath('data.status', AuctionStatus::PendingReview->value);

        $this->assertSame(1, AuctionTermsAcceptance::where('auction_id', $auction->id)->count());
    }

    public function test_registering_records_terms_and_moves_the_bidder_to_the_deposit_step(): void
    {
        [$auction] = $this->paymentAuction(AuctionStatus::Scheduled);
        $bidder = $this->paymentUser();

        $this->actingAs($bidder, 'sanctum')
            ->postJson('/api/soom/auctions/'.$auction->public_id.'/register', $this->termsBody($auction))
            ->assertCreated();

        $acceptance = AuctionTermsAcceptance::where('auction_id', $auction->id)
            ->where('user_id', $bidder->id)
            ->sole();

        $this->assertNotNull($acceptance->participant_id);
        $this->assertNotNull($acceptance->accepted_at);

        $data = $this->actingAs($bidder, 'sanctum')
            ->getJson('/api/auctions/'.$auction->public_id)
            ->assertOk()
            ->json('data');

        $this->assertTrue($data['my_participation']['is_registered']);
        $this->assertTrue($data['my_participation']['terms_accepted']);
        $this->assertSame('submit_bidder_deposit', $data['next_action']['code']);
    }

    public function test_registering_with_a_foreign_terms_version_creates_nothing(): void
    {
        [$auction] = $this->paymentAuction(AuctionStatus::Scheduled);
        $bidder = $this->paymentUser();
        $other = AuctionTermsVersion::create([
            'version_number' => ((int) AuctionTermsVersion::max('version_number')) + 1,
            'title' => 'Other terms',
            'body' => 'Other terms body.',
            'is_active' => false,
            'published_at' => Carbon::now()->subDay(),
        ]);

        $this->actingAs($bidder, 'sanctum')
            ->postJson('/api/soom/auctions/'.$auction->public_id.'/register', ['terms_version_id' => $other->public_id])
            ->assertStatus(409)
            ->assertJsonPath('code', 'terms_version_mismatch');

        $this->assertSame(0, AuctionTermsAcceptance::where('auction_id', $auction->id)->count());
    }

    public function test_a_seller_draft_reports_submit_for_review_as_the_next_action(): void
    {
        [$auction, $seller] = $this->draftAuction();

        $data = $this->actingAs($seller, 'sanctum')
            ->getJson('/api/auctions/'.$auction->public_id)
            ->assertOk()
            ->json('data');

        $this->assertSame('submit_for_review', $data['next_action']['code']);
        $this->assertTrue($data['next_action']['allowed']);
    }

    public function test_my_refunds_include_online_refunds_without_a_payment_submission(): void
    {
        [$auction] = $this->paymentAuction(AuctionStatus::Live);
        $bidder = $this->paymentUser();

        $payment = PaymentTransaction::create([
            'auction_id' => $auction->id,
            'user_id' => $bidder->id,
            'purpose' => PaymentPurpose::BidderDeposit,
            'status' => PaymentTransactionStatus::Succeeded,
            'amount_minor' => 1_000,
            'currency_code' => 'JOD',
            'provider' => 'fake',
            'provider_transaction_id' => 'txn-'.Str::ulid(),
            'idempotency_key' => 'online-'.Str::ulid(),
            'processed_at' => Carbon::now(),
        ]);

        $refund = RefundTransaction::create([
            'auction_id' => $auction->id,
            'deposit_id' => null,
            'payment_transaction_id' => $payment->id,
            'obligation_type' => 'deposit',
            'obligation_id' => 1,
            'user_id' => $bidder->id,
            'status' => RefundTransactionStatus::Pending,
            'amount_minor' => 1_000,
            'currency_code' => 'JOD',
            'reason' => 'online refund without submission',
            'provider' => 'fake',
            'idempotency_key' => 'refund-'.Str::ulid(),
        ]);

        $data = $this->actingAs($bidder, 'sanctum')
            ->getJson('/api/auctions/'.$auction->public_id)
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $data['my_refunds']);
        $this->assertSame($refund->public_id, $data['my_refunds'][0]['id']);
    }

    public function test_my_refunds_never_expose_another_bidder_refund(): void
    {
        [$auction] = $this->paymentAuction(AuctionStatus::Live);
        $bidder = $this->paymentUser();
        $other = $this->paymentUser();

        RefundTransaction::create([
            'auction_id' => $auction->id,
            'deposit_id' => null,
            'payment_transaction_id' => null,
            'obligation_type' => 'deposit',
            'obligation_id' => 1,
            'user_id' => $other->id,
            'status' => RefundTransactionStatus::Pending,
            'amount_minor' => 1_000,
            'currency_code' => 'JOD',
            'reason' => 'someone else',
            'provider' => 'fake',
            'idempotency_key' => 'refund-'.Str::ulid(),
        ]);

        $data = $this->actingAs($bidder, 'sanctum')
            ->getJson('/api/auctions/'.$auction->public_id)
            ->assertOk()
            ->json('data');

        $this->assertSame([], $data['my_refunds']);
    }

    private function draftAuction(): array
    {
        [$auction] = $this->paymentAuction(AuctionStatus::Draft);
        $seller = $auction->seller;

        return [$auction->refresh(), $seller];
    }
}
