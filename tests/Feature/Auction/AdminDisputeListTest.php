<?php

declare(strict_types=1);

namespace Tests\Feature\Auction;

use App\Domain\Auction\Enums\AuctionStatus;
use App\Models\Auction\Auction;
use App\Models\Auction\AuctionConfigurationVersion;
use App\Models\Auction\AuctionDispute;
use App\Models\Auction\AuctionTermsVersion;
use App\Models\Category;
use App\Models\Country;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AdminDisputeListTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate', ['--force' => true]);
    }

    public function test_queue_lists_disputes_with_full_shape_open_first(): void
    {
        $auction = $this->makeAuction();
        $opener = $this->user();
        $resolver = $this->user('admin');

        $resolved = AuctionDispute::create([
            'auction_id' => $auction->id,
            'opened_by' => $opener->id,
            'resolved_by' => $resolver->id,
            'status' => 'resolved',
            'reason' => 'item not as described',
            'resolution_note' => 'resolved amicably',
            'opened_at' => now()->subDays(2),
            'resolved_at' => now()->subDay(),
        ]);
        $open = AuctionDispute::create([
            'auction_id' => $auction->id,
            'opened_by' => $opener->id,
            'status' => 'open',
            'reason' => 'no handover',
            'opened_at' => now()->subDays(5),
        ]);

        $response = $this->actingAs($this->user('admin'), 'sanctum')
            ->getJson('/api/admin/auctions/disputes')
            ->assertOk();

        $response->assertJsonStructure(['success', 'data', 'current_page', 'last_page', 'per_page', 'total']);
        $rows = $response->json('data');
        $this->assertCount(2, $rows);

        // Open first despite being older.
        $this->assertSame($open->public_id, $rows[0]['id']);
        $this->assertSame('open', $rows[0]['status']);
        $this->assertSame('no handover', $rows[0]['reason']);
        $this->assertSame($opener->id, $rows[0]['opened_by']['id']);
        $this->assertNotNull($rows[0]['opened_at']);
        $this->assertNull($rows[0]['resolved_by']);
        $this->assertSame($auction->public_id, $rows[0]['auction']['id']);
        $this->assertSame($auction->title, $rows[0]['auction']['title']);
        $this->assertSame($auction->status->value, $rows[0]['auction']['status']);

        $this->assertSame($resolved->public_id, $rows[1]['id']);
        $this->assertSame('resolved amicably', $rows[1]['resolution_note']);
        $this->assertSame($resolver->id, $rows[1]['resolved_by']['id']);
        $this->assertNotNull($rows[1]['resolved_at']);
    }

    public function test_status_and_auction_filters(): void
    {
        $target = $this->makeAuction();
        $other = $this->makeAuction();
        $opener = $this->user();

        AuctionDispute::create([
            'auction_id' => $target->id, 'opened_by' => $opener->id,
            'status' => 'open', 'reason' => 'a', 'opened_at' => now()->subDay(),
        ]);
        AuctionDispute::create([
            'auction_id' => $other->id, 'opened_by' => $opener->id,
            'status' => 'resolved', 'reason' => 'b', 'resolution_note' => 'done',
            'opened_at' => now()->subDays(2), 'resolved_at' => now()->subDay(),
        ]);

        $admin = $this->user('admin');

        $open = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/auctions/disputes?status=open')
            ->assertOk();
        $this->assertSame(1, $open->json('total'));
        $this->assertSame('open', $open->json('data.0.status'));

        $byNumeric = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/auctions/disputes?auction_id='.$target->id)
            ->assertOk();
        $this->assertSame(1, $byNumeric->json('total'));

        $byUlid = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/auctions/disputes?auction_id='.$target->public_id)
            ->assertOk();
        $this->assertSame(1, $byUlid->json('total'));
        $this->assertSame($target->public_id, $byUlid->json('data.0.auction.id'));

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/auctions/disputes?status=escalated')
            ->assertUnprocessable();
    }

    public function test_per_auction_admin_resource_includes_reason_and_opened_at(): void
    {
        $auction = $this->makeAuction();
        $opener = $this->user();

        AuctionDispute::create([
            'auction_id' => $auction->id,
            'opened_by' => $opener->id,
            'status' => 'open',
            'reason' => 'seller unreachable',
            'opened_at' => now()->subHours(4),
        ]);

        $data = $this->actingAs($this->user('admin'), 'sanctum')
            ->getJson('/api/admin/auctions/'.$auction->public_id)
            ->assertOk()
            ->json('data');

        $this->assertArrayHasKey('disputes', $data);
        $this->assertSame('seller unreachable', $data['disputes'][0]['reason']);
        $this->assertNotNull($data['disputes'][0]['opened_at']);
        $this->assertSame('open', $data['disputes'][0]['status']);
    }

    public function test_non_admin_cannot_access_disputes_queue(): void
    {
        $this->actingAs($this->user(), 'sanctum')
            ->getJson('/api/admin/auctions/disputes')
            ->assertForbidden();
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
        $category = Category::create(['name' => 'dispute-cat-'.Str::ulid(), 'display_order' => 0]);
        $country = Country::create(['name' => 'dispute-country-'.Str::ulid(), 'code' => strtoupper(substr((string) Str::ulid(), 0, 6))]);

        return Auction::create([
            'seller_id' => $this->user()->id,
            'category_id' => $category->id,
            'country_id' => $country->id,
            'terms_version_id' => $terms->id,
            'configuration_version_id' => $configuration->id,
            'currency_code' => 'JOD',
            'title' => 'Dispute auction '.Str::ulid(),
            'description' => 'Dispute auction.',
            'status' => AuctionStatus::Disputed,
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
            'starts_at' => now()->subDays(5),
            'original_ends_at' => now()->subDays(2),
            'ends_at' => now()->subDays(2),
        ]);
    }

    private function user(string $role = 'user'): User
    {
        $unique = strtolower((string) Str::ulid());
        $phoneSuffix = str_pad((string) (abs(crc32($unique)) % 10_000_000), 7, '0', STR_PAD_LEFT);

        return User::create([
            'name' => fake()->name(),
            'email' => "dispute-list-{$unique}@example.test",
            'phone' => '+96270'.$phoneSuffix,
            'password' => Hash::make('password'),
            'role' => $role,
            'email_verified_at' => now(),
        ]);
    }
}
