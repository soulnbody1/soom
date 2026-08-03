<?php

declare(strict_types=1);

namespace Tests\Feature\Auction;

use App\Domain\Auction\Enums\AuctionDepositStatus;
use App\Domain\Auction\Enums\AuctionParticipantStatus;
use App\Domain\Auction\Enums\AuctionStatus;
use App\Models\Auction\Auction;
use App\Models\Auction\AuctionBid;
use App\Models\Auction\AuctionConfigurationVersion;
use App\Models\Auction\AuctionDeposit;
use App\Models\Auction\AuctionParticipant;
use App\Models\Auction\AuctionTermsVersion;
use App\Models\Category;
use App\Models\Country;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Regression coverage for optional Sanctum authentication on the public
 * auction routes (GET auctions/{auction} and GET auctions/{auction}/bids).
 *
 * These tests intentionally send REAL bearer tokens via withToken() instead
 * of actingAs(): actingAs() forces the sanctum guard in-process and masked
 * the original bug where real HTTP bearer tokens were never resolved.
 */
final class PublicAuctionOptionalAuthTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate', ['--force' => true]);
    }

    public function test_guest_reads_public_auction_without_admin_blocks(): void
    {
        [$auction] = $this->liveAuctionWithBid();

        $data = $this->getJson('/api/auctions/'.$auction->public_id)
            ->assertOk()
            ->json('data');

        $this->assertSame($auction->public_id, $data['id']);
        $this->assertArrayNotHasKey('internal_id', $data);
        $this->assertArrayNotHasKey('deposits', $data);
        $this->assertArrayNotHasKey('payment_submissions', $data);
        $this->assertArrayNotHasKey('financial_details', $data);
        $this->assertArrayNotHasKey('id', $data['seller']);
        $this->assertArrayNotHasKey('phone', $data['seller']);
    }

    public function test_guest_bid_identities_remain_anonymous(): void
    {
        [$auction] = $this->liveAuctionWithBid();

        $rows = $this->getJson('/api/auctions/'.$auction->public_id.'/bids')
            ->assertOk()
            ->json('data');

        $this->assertNotEmpty($rows);
        $this->assertSame(['anonymous' => true], $rows[0]['bidder']);
    }

    public function test_guest_cannot_read_non_public_auction(): void
    {
        $auction = $this->auction(AuctionStatus::PendingReview);

        $this->getJson('/api/auctions/'.$auction->public_id)->assertNotFound();
    }

    public function test_bearer_token_normal_user_behavior_is_unchanged(): void
    {
        [$auction, , $bidder] = $this->liveAuctionWithBid();
        $stranger = $this->user();
        $token = $stranger->createToken('test')->plainTextToken;

        $data = $this->withToken($token)
            ->getJson('/api/auctions/'.$auction->public_id)
            ->assertOk()
            ->json('data');

        $this->assertArrayNotHasKey('internal_id', $data);
        $this->assertArrayNotHasKey('deposits', $data);
        $this->assertArrayNotHasKey('id', $data['seller']);
        $this->assertFalse($data['seller']['is_me']);

        // Strangers still see anonymous bidders.
        $rows = $this->withToken($token)
            ->getJson('/api/auctions/'.$auction->public_id.'/bids')
            ->assertOk()
            ->json('data');
        $this->assertSame(['anonymous' => true], $rows[0]['bidder']);

        // Non-public auctions stay hidden from unrelated authenticated users.
        $hidden = $this->auction(AuctionStatus::PendingReview);
        $this->withToken($token)
            ->getJson('/api/auctions/'.$hidden->public_id)
            ->assertNotFound();
    }

    // Separate test per token: Sanctum's RequestGuard caches the resolved
    // user across requests inside one test case, unlike real HTTP requests.
    public function test_bidder_bearer_token_sees_own_identity_on_bids(): void
    {
        [$auction, , $bidder] = $this->liveAuctionWithBid();

        $rows = $this->withToken($bidder->createToken('test')->plainTextToken)
            ->getJson('/api/auctions/'.$auction->public_id.'/bids')
            ->assertOk()
            ->json('data');

        $this->assertSame($bidder->id, $rows[0]['bidder']['id']);
    }

    public function test_admin_bearer_token_reads_non_public_auction_with_admin_blocks(): void
    {
        $auction = $this->auction(AuctionStatus::PendingReview);
        AuctionDeposit::create([
            'auction_id' => $auction->id,
            'user_id' => $auction->seller_id,
            'type' => 'seller',
            'status' => AuctionDepositStatus::PendingReview,
            'required_amount_minor' => 2_000,
            'currency_code' => 'JOD',
        ]);
        $token = $this->user('admin')->createToken('test')->plainTextToken;

        $data = $this->withToken($token)
            ->getJson('/api/admin/auctions/'.$auction->public_id)
            ->assertOk()
            ->json('data');

        $this->assertSame($auction->public_id, $data['id']);
        $this->assertArrayHasKey('internal_id', $data);
        $this->assertSame($auction->seller_id, $data['seller']['id']);
        $this->assertCount(1, $data['deposits']);
        $this->assertSame($auction->seller_id, $data['deposits'][0]['user']['id']);
        $this->assertArrayHasKey('payment_submissions', $data);
    }

    public function test_admin_bearer_token_reads_non_public_auction_on_the_user_route(): void
    {
        $auction = $this->auction(AuctionStatus::PendingReview);
        $token = $this->user('admin')->createToken('test')->plainTextToken;

        $data = $this->withToken($token)
            ->getJson('/api/auctions/'.$auction->public_id)
            ->assertOk()
            ->json('data');

        $this->assertSame($auction->public_id, $data['id']);
        $this->assertArrayNotHasKey('internal_id', $data);
        $this->assertArrayNotHasKey('deposits', $data);
        $this->assertArrayNotHasKey('id', $data['seller']);
    }

    public function test_admin_bearer_token_sees_bidder_identities(): void
    {
        [$auction, , $bidder] = $this->liveAuctionWithBid();
        $token = $this->user('admin')->createToken('test')->plainTextToken;

        $rows = $this->withToken($token)
            ->getJson('/api/auctions/'.$auction->public_id.'/bids')
            ->assertOk()
            ->json('data');

        $this->assertSame($bidder->id, $rows[0]['bidder']['id']);
        $this->assertSame($bidder->name, $rows[0]['bidder']['name']);
    }

    public function test_invalid_bearer_token_is_treated_as_guest(): void
    {
        [$live] = $this->liveAuctionWithBid();
        $hidden = $this->auction(AuctionStatus::PendingReview);

        $this->withToken('1|totally-invalid-token')
            ->getJson('/api/auctions/'.$hidden->public_id)
            ->assertNotFound();

        $rows = $this->withToken('1|totally-invalid-token')
            ->getJson('/api/auctions/'.$live->public_id.'/bids')
            ->assertOk()
            ->json('data');
        $this->assertSame(['anonymous' => true], $rows[0]['bidder']);
    }

    public function test_expired_admin_bearer_token_is_treated_as_guest(): void
    {
        $hidden = $this->auction(AuctionStatus::PendingReview);
        $expired = $this->user('admin')
            ->createToken('test', ['*'], now()->subMinute())
            ->plainTextToken;

        $this->withToken($expired)
            ->getJson('/api/auctions/'.$hidden->public_id)
            ->assertNotFound();
    }

    // ─── Fixtures ──────────────────────────────────────────────

    /** @return array{0: Auction, 1: User, 2: User} live auction + seller + bidder with one accepted bid */
    private function liveAuctionWithBid(): array
    {
        $auction = $this->auction(AuctionStatus::Live);
        $seller = User::findOrFail($auction->seller_id);
        $bidder = $this->user();

        $participant = AuctionParticipant::create([
            'auction_id' => $auction->id,
            'user_id' => $bidder->id,
            'status' => AuctionParticipantStatus::Qualified,
            'registered_at' => now()->subDays(2),
            'qualified_at' => now()->subDays(2),
        ]);

        AuctionBid::create([
            'auction_id' => $auction->id,
            'participant_id' => $participant->id,
            'bidder_id' => $bidder->id,
            'amount_minor' => 100_000,
            'currency_code' => 'JOD',
            'sequence_number' => 1,
            'idempotency_key' => 'optional-auth-bid-'.Str::ulid(),
            'accepted_at' => now()->subHour(),
            'server_received_at' => now()->subHour(),
        ]);

        return [$auction, $seller, $bidder];
    }

    private function auction(AuctionStatus $status): Auction
    {
        $terms = AuctionTermsVersion::firstOrCreate(
            ['version_number' => 1],
            ['title' => 'Terms', 'body' => 'Auction terms.', 'is_active' => true, 'published_at' => now()->subDay()]
        );
        $configuration = AuctionConfigurationVersion::firstOrCreate(
            ['version_number' => 1],
            [
                'configuration' => [
                    'seller_deposit_minor' => 2_000,
                    'bidder_deposit_minor' => 1_000,
                    'minimum_bid_increment_minor' => 500,
                    'seller_deposit_policy' => config('auction.seller_deposit_policy'),
                    'winner_default_deposit_policy' => config('auction.winner_default_deposit_policy'),
                    'non_winner_deposit_policy' => config('auction.non_winner_deposit_policy'),
                    'non_winner_deposit_hold_count' => (int) config('auction.non_winner_deposit_hold_count', 1),
                ],
                'is_active' => true,
                'published_at' => now()->subDay(),
            ]
        );
        $category = Category::create(['name' => 'optional-auth-cat-'.Str::ulid(), 'display_order' => 0]);
        $country = Country::create(['name' => 'optional-auth-country-'.Str::ulid(), 'code' => strtoupper(substr((string) Str::ulid(), 0, 6))]);

        return Auction::create([
            'seller_id' => $this->user()->id,
            'category_id' => $category->id,
            'country_id' => $country->id,
            'terms_version_id' => $terms->id,
            'configuration_version_id' => $configuration->id,
            'currency_code' => 'JOD',
            'title' => 'Optional auth auction '.Str::ulid(),
            'description' => 'Optional auth auction.',
            'status' => $status,
            'starting_amount_minor' => 10_000,
            'reserve_amount_minor' => null,
            'minimum_bid_increment_minor' => 500,
            'seller_deposit_amount_minor' => 2_000,
            'bidder_deposit_amount_minor' => 1_000,
            'platform_fee_type' => 'percentage',
            'platform_fee_basis_points' => 250,
            'platform_fee_fixed_minor' => 0,
            'winner_payment_deadline_hours' => 48,
            'handover_deadline_hours' => 72,
            'starts_at' => now()->subDay(),
            'original_ends_at' => now()->addDay(),
            'ends_at' => now()->addDay(),
        ]);
    }

    private function user(string $role = 'user'): User
    {
        $unique = strtolower((string) Str::ulid());
        $phoneSuffix = str_pad((string) (abs(crc32($unique)) % 10_000_000), 7, '0', STR_PAD_LEFT);

        return User::create([
            'name' => fake()->name(),
            'email' => "optional-auth-{$unique}@example.test",
            'phone' => '+96271'.$phoneSuffix,
            'password' => Hash::make('password'),
            'role' => $role,
            'email_verified_at' => now(),
        ]);
    }
}
