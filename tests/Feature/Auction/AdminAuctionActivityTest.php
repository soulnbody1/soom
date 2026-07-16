<?php

declare(strict_types=1);

namespace Tests\Feature\Auction;

use App\Domain\Auction\Enums\AuctionStatus;
use App\Models\Auction\Auction;
use App\Models\Auction\AuctionActivityLog;
use App\Models\Auction\AuctionConfigurationVersion;
use App\Models\Auction\AuctionStatusHistory;
use App\Models\Auction\AuctionTermsVersion;
use App\Models\Category;
use App\Models\Country;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AdminAuctionActivityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate', ['--force' => true]);
    }

    public function test_activity_is_paginated_with_actor_identity_and_never_exposes_ip_hash(): void
    {
        $auction = $this->makeAuction();
        $actor = $this->user('admin');

        AuctionActivityLog::create([
            'auction_id' => $auction->id,
            'user_id' => $actor->id,
            'event_type' => 'auction.created',
            'actor_type' => 'user',
            'ip_hash' => hash('sha256', '10.0.0.1'),
            'metadata' => ['note' => 'first'],
            'created_at' => now()->subHour(),
        ]);
        AuctionActivityLog::create([
            'auction_id' => $auction->id,
            'user_id' => null,
            'event_type' => 'auction.started',
            'actor_type' => 'system',
            'ip_hash' => hash('sha256', '10.0.0.2'),
            'metadata' => null,
            'created_at' => now(),
        ]);

        $response = $this->actingAs($this->user('admin'), 'sanctum')
            ->getJson('/api/admin/auctions/'.$auction->public_id.'/activity')
            ->assertOk();

        $response->assertJsonStructure(['success', 'data', 'current_page', 'last_page', 'per_page', 'total']);
        $this->assertSame(2, $response->json('total'));

        $rows = $response->json('data');
        // latest('id') → newest first.
        $this->assertSame('auction.started', $rows[0]['event_type']);
        $this->assertSame('system', $rows[0]['actor_type']);
        $this->assertNull($rows[0]['user']);
        $this->assertSame('auction.created', $rows[1]['event_type']);
        $this->assertSame($actor->id, $rows[1]['user']['id']);
        $this->assertSame(['note' => 'first'], $rows[1]['metadata']);

        foreach ($rows as $row) {
            $this->assertArrayNotHasKey('ip_hash', $row);
        }
    }

    public function test_status_history_is_ordered_oldest_first_with_actor_and_reason(): void
    {
        $auction = $this->makeAuction();
        $admin = $this->user('admin');

        AuctionStatusHistory::create([
            'auction_id' => $auction->id,
            'from_status' => null,
            'to_status' => 'draft',
            'changed_by' => null,
            'actor_type' => 'user',
            'reason' => null,
            'metadata' => null,
            'created_at' => now()->subHours(3),
        ]);
        AuctionStatusHistory::create([
            'auction_id' => $auction->id,
            'from_status' => 'pending_review',
            'to_status' => 'rejected',
            'changed_by' => $admin->id,
            'actor_type' => 'admin',
            'reason' => 'incomplete photos',
            'metadata' => ['review' => true],
            'created_at' => now()->subHour(),
        ]);

        $response = $this->actingAs($this->user('admin'), 'sanctum')
            ->getJson('/api/admin/auctions/'.$auction->public_id.'/status-history')
            ->assertOk();

        $rows = $response->json('data');
        $this->assertCount(2, $rows);
        $this->assertSame('draft', $rows[0]['to_status']);
        $this->assertNull($rows[0]['actor']);
        $this->assertSame('rejected', $rows[1]['to_status']);
        $this->assertSame('incomplete photos', $rows[1]['reason']);
        $this->assertSame($admin->id, $rows[1]['actor']['id']);
        $this->assertSame($admin->name, $rows[1]['actor']['name']);
        $this->assertSame(['review' => true], $rows[1]['metadata']);
    }

    public function test_non_admin_and_unknown_auction_are_rejected(): void
    {
        $auction = $this->makeAuction();

        $this->actingAs($this->user(), 'sanctum')
            ->getJson('/api/admin/auctions/'.$auction->public_id.'/activity')
            ->assertForbidden();

        $this->actingAs($this->user(), 'sanctum')
            ->getJson('/api/admin/auctions/'.$auction->public_id.'/status-history')
            ->assertForbidden();

        $this->actingAs($this->user('admin'), 'sanctum')
            ->getJson('/api/admin/auctions/'.strtoupper((string) Str::ulid()).'/activity')
            ->assertNotFound();
    }

    // ─── Fixtures ──────────────────────────────────────────────

    private function makeAuction(): Auction
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
        $category = Category::create(['name' => 'activity-cat-'.Str::ulid(), 'display_order' => 0]);
        $country = Country::create(['name' => 'activity-country-'.Str::ulid(), 'code' => strtoupper(substr((string) Str::ulid(), 0, 6))]);

        return Auction::create([
            'seller_id' => $this->user()->id,
            'category_id' => $category->id,
            'country_id' => $country->id,
            'terms_version_id' => $terms->id,
            'configuration_version_id' => $configuration->id,
            'currency_code' => 'JOD',
            'title' => 'Activity auction '.Str::ulid(),
            'description' => 'Activity auction.',
            'status' => AuctionStatus::PendingReview,
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
            'starts_at' => now()->addDay(),
            'original_ends_at' => now()->addDays(3),
            'ends_at' => now()->addDays(3),
        ]);
    }

    private function user(string $role = 'user'): User
    {
        $unique = strtolower((string) Str::ulid());
        $phoneSuffix = str_pad((string) (abs(crc32($unique)) % 10_000_000), 7, '0', STR_PAD_LEFT);

        return User::create([
            'name' => fake()->name(),
            'email' => "activity-{$unique}@example.test",
            'phone' => '+96270'.$phoneSuffix,
            'password' => Hash::make('password'),
            'role' => $role,
            'email_verified_at' => now(),
        ]);
    }
}
