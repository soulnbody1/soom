<?php

declare(strict_types=1);

namespace Tests\Feature\Auction;

use App\Domain\Auction\Enums\AuctionParticipantStatus;
use App\Domain\Auction\Enums\AuctionStatus;
use App\Models\Auction\Auction;
use App\Models\Auction\AuctionConfigurationVersion;
use App\Models\Auction\AuctionParticipant;
use App\Models\Auction\AuctionTermsVersion;
use App\Models\Category;
use App\Models\Country;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AdminParticipantsListTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate', ['--force' => true]);
    }

    public function test_admin_lists_participants_with_identity_ordered_by_registration(): void
    {
        $auction = $this->makeAuction();
        $first = $this->participant($auction, AuctionParticipantStatus::Registered, now()->subDays(3));
        $second = $this->participant($auction, AuctionParticipantStatus::Qualified, now()->subDays(2));
        $blocked = $this->participant($auction, AuctionParticipantStatus::Blocked, now()->subDay(), 'fraud suspicion');

        $response = $this->actingAs($this->user('admin'), 'sanctum')
            ->getJson('/api/admin/auctions/'.$auction->public_id.'/participants')
            ->assertOk();

        $response->assertJsonStructure(['success', 'data', 'current_page', 'last_page', 'per_page', 'total']);
        $this->assertSame(3, $response->json('total'));

        $rows = $response->json('data');
        $this->assertSame($first->public_id, $rows[0]['id']);
        $this->assertSame($second->public_id, $rows[1]['id']);
        $this->assertSame($blocked->public_id, $rows[2]['id']);

        $this->assertSame($first->user_id, $rows[0]['user']['id']);
        $this->assertNotEmpty($rows[0]['user']['name']);

        $this->assertSame('blocked', $rows[2]['status']);
        $this->assertSame('fraud suspicion', $rows[2]['block_reason']);
        $this->assertNotNull($rows[2]['blocked_at']);
    }

    public function test_status_filter_narrows_participants(): void
    {
        $auction = $this->makeAuction();
        $this->participant($auction, AuctionParticipantStatus::Registered, now()->subDays(2));
        $this->participant($auction, AuctionParticipantStatus::Qualified, now()->subDay());

        $response = $this->actingAs($this->user('admin'), 'sanctum')
            ->getJson('/api/admin/auctions/'.$auction->public_id.'/participants?status=qualified')
            ->assertOk();

        $this->assertSame(1, $response->json('total'));
        $this->assertSame('qualified', $response->json('data.0.status'));

        $this->actingAs($this->user('admin'), 'sanctum')
            ->getJson('/api/admin/auctions/'.$auction->public_id.'/participants?status=vip')
            ->assertUnprocessable();
    }

    public function test_non_admin_cannot_list_participants(): void
    {
        $auction = $this->makeAuction();

        $this->actingAs($this->user(), 'sanctum')
            ->getJson('/api/admin/auctions/'.$auction->public_id.'/participants')
            ->assertForbidden();
    }

    public function test_user_registration_response_does_not_expose_admin_fields(): void
    {
        $auction = $this->makeAuction(AuctionStatus::Scheduled);
        $bidder = $this->user();

        $data = $this->actingAs($bidder, 'sanctum')
            ->postJson('/api/soom/auctions/'.$auction->public_id.'/register')
            ->assertCreated()
            ->json('data');

        $this->assertArrayNotHasKey('user', $data);
        $this->assertArrayNotHasKey('blocked_at', $data);
        $this->assertArrayNotHasKey('block_reason', $data);
    }

    // ─── Fixtures ──────────────────────────────────────────────

    private function participant(
        Auction $auction,
        AuctionParticipantStatus $status,
        \Illuminate\Support\Carbon $registeredAt,
        ?string $blockReason = null
    ): AuctionParticipant {
        return AuctionParticipant::create([
            'auction_id' => $auction->id,
            'user_id' => $this->user()->id,
            'status' => $status,
            'registered_at' => $registeredAt,
            'qualified_at' => $status === AuctionParticipantStatus::Qualified ? $registeredAt->copy()->addHour() : null,
            'blocked_at' => $status === AuctionParticipantStatus::Blocked ? $registeredAt->copy()->addHour() : null,
            'block_reason' => $blockReason,
        ]);
    }

    private function makeAuction(AuctionStatus $status = AuctionStatus::Live): Auction
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
        $category = Category::create(['name' => 'participants-cat-'.Str::ulid(), 'display_order' => 0]);
        $country = Country::create(['name' => 'participants-country-'.Str::ulid(), 'code' => strtoupper(substr((string) Str::ulid(), 0, 6))]);

        return Auction::create([
            'seller_id' => $this->user()->id,
            'category_id' => $category->id,
            'country_id' => $country->id,
            'terms_version_id' => $terms->id,
            'configuration_version_id' => $configuration->id,
            'currency_code' => 'JOD',
            'title' => 'Participants auction '.Str::ulid(),
            'description' => 'Participants auction.',
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
            'starts_at' => $status === AuctionStatus::Scheduled ? now()->addDay() : now()->subHour(),
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
            'email' => "participants-{$unique}@example.test",
            'phone' => '+96270'.$phoneSuffix,
            'password' => Hash::make('password'),
            'role' => $role,
            'email_verified_at' => now(),
        ]);
    }
}
