<?php

declare(strict_types=1);

namespace Tests\Feature\Auction;

use App\Domain\Auction\Enums\AuctionStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\Feature\Auction\Concerns\BuildsAuctionDeadlineFixtures;
use Tests\TestCase;

final class BidRateLimitTest extends TestCase
{
    use BuildsAuctionDeadlineFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate', ['--force' => true]);
        RateLimiter::clear('auction-bids');
        config([
            'auction.bidding.rate_limit_per_minute' => 3,
            'auction.bidding.rate_limit_per_minute_per_ip' => 0,
        ]);
    }

    public function test_bidding_is_throttled_per_user_and_auction(): void
    {
        [$auction] = $this->liveAuction();
        [$bidder] = $this->qualifiedParticipant($auction, 10_000);

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->postBid($auction->public_id, $bidder)->assertStatus(201);
        }

        $response = $this->postBid($auction->public_id, $bidder);

        $response->assertStatus(429)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'too_many_requests');

        $this->assertIsInt($response->json('retry_after'));
        $this->assertGreaterThan(0, (int) $response->json('retry_after'));
    }

    public function test_throttle_counters_are_isolated_per_auction(): void
    {
        [$auctionA] = $this->liveAuction();
        [$auctionB] = $this->liveAuction();
        [$bidder] = $this->qualifiedParticipant($auctionA, 10_000);
        $this->qualifiedParticipantFor($auctionB, $bidder);

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->postBid($auctionA->public_id, $bidder)->assertStatus(201);
        }

        $this->postBid($auctionA->public_id, $bidder)->assertStatus(429);
        $this->postBid($auctionB->public_id, $bidder)->assertStatus(201);
    }

    public function test_throttle_counters_are_isolated_per_user(): void
    {
        [$auction] = $this->liveAuction();
        [$firstBidder] = $this->qualifiedParticipant($auction, 10_000);
        [$secondBidder] = $this->qualifiedParticipant($auction, 10_000);

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->postBid($auction->public_id, $firstBidder)->assertStatus(201);
        }

        $this->postBid($auction->public_id, $firstBidder)->assertStatus(429);
        $this->postBid($auction->public_id, $secondBidder)->assertStatus(201);
    }

    public function test_throttled_bidder_can_retry_after_the_window(): void
    {
        [$auction] = $this->liveAuction();
        [$bidder] = $this->qualifiedParticipant($auction, 10_000);

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->postBid($auction->public_id, $bidder)->assertStatus(201);
        }

        $this->postBid($auction->public_id, $bidder)->assertStatus(429);

        $this->travel(61)->seconds();

        $this->postBid($auction->public_id, $bidder)->assertStatus(201);
    }

    public function test_rate_limit_is_configurable(): void
    {
        config(['auction.bidding.rate_limit_per_minute' => 1]);

        [$auction] = $this->liveAuction();
        [$bidder] = $this->qualifiedParticipant($auction, 10_000);

        $this->postBid($auction->public_id, $bidder)->assertStatus(201);
        $this->postBid($auction->public_id, $bidder)->assertStatus(429);
    }

    private function liveAuction(): array
    {
        [$auction, $seller] = $this->auction(AuctionStatus::Live, Carbon::now()->addHours(2));

        $auction->forceFill(['started_at' => Carbon::now()->subHour()])->save();

        return [$auction->refresh(), $seller];
    }

    private function qualifiedParticipantFor(\App\Models\Auction\Auction $auction, \App\Models\User $user): void
    {
        $participant = \App\Models\Auction\AuctionParticipant::create([
            'auction_id' => $auction->id,
            'user_id' => $user->id,
            'status' => \App\Domain\Auction\Enums\AuctionParticipantStatus::Qualified,
            'registered_at' => Carbon::now()->subDays(2),
            'qualified_at' => Carbon::now()->subDay(),
        ]);
        $deposit = \App\Models\Auction\AuctionDeposit::create([
            'auction_id' => $auction->id,
            'participant_id' => $participant->id,
            'user_id' => $user->id,
            'type' => 'bidder',
            'status' => \App\Domain\Auction\Enums\AuctionDepositStatus::Held,
            'required_amount_minor' => 10_000,
            'held_amount_minor' => 10_000,
            'currency_code' => $auction->currency_code,
            'held_at' => Carbon::now()->subDay(),
        ]);
        $this->successfulDepositPayment($auction, $deposit, $user->id, 10_000);
        $this->acceptTerms($auction, $participant, $user);
    }

    private function postBid(string $auctionPublicId, \App\Models\User $bidder)
    {
        static $amount = 20_000;
        $amount += 1_000;

        return $this->actingAs($bidder, 'sanctum')->postJson(
            '/api/soom/auctions/'.$auctionPublicId.'/bids',
            [
                'amount' => number_format($amount / 1000, 3, '.', ''),
                'currency_code' => 'JOD',
                'idempotency_key' => (string) Str::ulid(),
            ]
        );
    }
}
