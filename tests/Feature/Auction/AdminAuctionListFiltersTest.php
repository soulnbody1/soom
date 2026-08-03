<?php

declare(strict_types=1);

namespace Tests\Feature\Auction;

use App\Domain\Auction\Enums\AuctionStatus;
use App\Models\Auction\Auction;
use App\Models\Auction\AuctionConfigurationVersion;
use App\Models\Auction\AuctionTermsVersion;
use App\Models\Category;
use App\Models\Country;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AdminAuctionListFiltersTest extends TestCase
{
    private ?Category $category = null;

    private ?Country $country = null;

    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate', ['--force' => true]);
    }

    public function test_admin_list_returns_paginated_envelope_without_filters(): void
    {
        $this->makeAuction();
        $this->makeAuction();

        $response = $this->actingAs($this->user('admin'), 'sanctum')
            ->getJson('/api/admin/auctions')
            ->assertOk();

        $response->assertJsonStructure(['success', 'message', 'data', 'current_page', 'last_page', 'per_page', 'total']);
        $this->assertSame(2, $response->json('total'));
    }

    public function test_status_filter_narrows_results_server_side(): void
    {
        $this->makeAuction(['status' => AuctionStatus::Live]);
        $this->makeAuction(['status' => AuctionStatus::PendingReview]);
        $this->makeAuction(['status' => AuctionStatus::PendingReview]);

        $response = $this->actingAs($this->user('admin'), 'sanctum')
            ->getJson('/api/admin/auctions?status=pending_review')
            ->assertOk();

        $this->assertSame(2, $response->json('total'));
        foreach ($response->json('data') as $row) {
            $this->assertSame('pending_review', $row['status']);
        }
    }

    public function test_seller_and_category_filters(): void
    {
        $seller = $this->user();
        $this->makeAuction(['seller_id' => $seller->id]);
        $this->makeAuction();

        $bySeller = $this->actingAs($this->user('admin'), 'sanctum')
            ->getJson('/api/admin/auctions?seller_id='.$seller->id)
            ->assertOk();
        $this->assertSame(1, $bySeller->json('total'));

        $otherCategory = Category::create(['name' => 'admin-filter-cat-'.Str::ulid(), 'display_order' => 0]);
        $this->makeAuction(['category_id' => $otherCategory->id]);

        $byCategory = $this->actingAs($this->user('admin'), 'sanctum')
            ->getJson('/api/admin/auctions?category_id='.$otherCategory->id)
            ->assertOk();
        $this->assertSame(1, $byCategory->json('total'));
    }

    public function test_q_matches_title_partially_and_public_id_exactly(): void
    {
        $target = $this->makeAuction(['title' => 'Rare vintage clock']);
        $this->makeAuction(['title' => 'Something else']);

        $byTitle = $this->actingAs($this->user('admin'), 'sanctum')
            ->getJson('/api/admin/auctions?q=vintage')
            ->assertOk();
        $this->assertSame(1, $byTitle->json('total'));
        $this->assertSame($target->public_id, $byTitle->json('data.0.id'));

        $byUlid = $this->actingAs($this->user('admin'), 'sanctum')
            ->getJson('/api/admin/auctions?q='.$target->public_id)
            ->assertOk();
        $this->assertSame(1, $byUlid->json('total'));
        $this->assertSame($target->public_id, $byUlid->json('data.0.id'));
    }

    public function test_date_range_filters(): void
    {
        $near = $this->makeAuction(['starts_at' => now()->addDays(201), 'ends_at' => now()->addDays(202), 'original_ends_at' => now()->addDays(202)]);
        $far = $this->makeAuction(['starts_at' => now()->addDays(210), 'ends_at' => now()->addDays(212), 'original_ends_at' => now()->addDays(212)]);

        $response = $this->actingAs($this->user('admin'), 'sanctum')
            ->getJson('/api/admin/auctions?starts_from='.now()->addDays(205)->toDateString())
            ->assertOk();

        $this->assertSame([$far->public_id], array_column($response->json('data'), 'id'));

        $ends = $this->actingAs($this->user('admin'), 'sanctum')
            ->getJson('/api/admin/auctions?ends_from='.now()->addDays(200)->toDateString().'&ends_to='.now()->addDays(205)->toDateString())
            ->assertOk();

        $this->assertSame([$near->public_id], array_column($ends->json('data'), 'id'));
    }

    public function test_sort_by_ends_at_ascending_and_descending(): void
    {
        $late = $this->makeAuction(['ends_at' => now()->addDays(309), 'original_ends_at' => now()->addDays(309)]);
        $early = $this->makeAuction(['ends_at' => now()->addDays(2), 'original_ends_at' => now()->addDays(2)]);

        $asc = $this->actingAs($this->user('admin'), 'sanctum')
            ->getJson('/api/admin/auctions?sort=ends_at&direction=asc')
            ->assertOk();
        $this->assertSame($early->public_id, $asc->json('data.0.id'));

        $desc = $this->actingAs($this->user('admin'), 'sanctum')
            ->getJson('/api/admin/auctions?sort=ends_at&direction=desc')
            ->assertOk();
        $this->assertSame($late->public_id, $desc->json('data.0.id'));
    }

    public function test_invalid_filter_values_are_rejected(): void
    {
        $admin = $this->user('admin');

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/auctions?status=not_a_status')
            ->assertUnprocessable();

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/auctions?sort=seller_id')
            ->assertUnprocessable();

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/auctions?direction=upside_down')
            ->assertUnprocessable();
    }

    public function test_non_admin_cannot_access_admin_list(): void
    {
        $this->actingAs($this->user(), 'sanctum')
            ->getJson('/api/admin/auctions')
            ->assertForbidden();
    }

    // ─── Fixtures ──────────────────────────────────────────────

    private function makeAuction(array $overrides = []): Auction
    {
        $seller = isset($overrides['seller_id']) ? null : $this->user();

        return Auction::create(array_merge([
            'seller_id' => $seller?->id,
            'category_id' => $this->category()->id,
            'country_id' => $this->country()->id,
            'terms_version_id' => $this->terms()->id,
            'configuration_version_id' => $this->configuration()->id,
            'currency_code' => 'JOD',
            'title' => 'Admin filter auction '.Str::ulid(),
            'description' => 'Admin filter auction.',
            'status' => AuctionStatus::Scheduled,
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
        ], $overrides));
    }

    private function category(): Category
    {
        return $this->category ??= Category::create(['name' => 'admin-list-cat-'.Str::ulid(), 'display_order' => 0]);
    }

    private function country(): Country
    {
        return $this->country ??= Country::create([
            'name' => 'admin-list-country-'.Str::ulid(),
            'code' => strtoupper(substr((string) Str::ulid(), 0, 6)),
        ]);
    }

    private function terms(): AuctionTermsVersion
    {
        return AuctionTermsVersion::firstOrCreate(
            ['version_number' => 1],
            ['title' => 'Terms', 'body' => 'Auction terms.', 'is_active' => true, 'published_at' => now()->subDay()]
        );
    }

    private function configuration(): AuctionConfigurationVersion
    {
        return AuctionConfigurationVersion::firstOrCreate(
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
    }

    private function user(string $role = 'user'): User
    {
        $unique = strtolower((string) Str::ulid());
        $phoneSuffix = str_pad((string) (abs(crc32($unique)) % 10_000_000), 7, '0', STR_PAD_LEFT);

        return User::create([
            'name' => fake()->name(),
            'email' => "admin-list-{$unique}@example.test",
            'phone' => '+96270'.$phoneSuffix,
            'password' => Hash::make('password'),
            'role' => $role,
            'email_verified_at' => now(),
        ]);
    }
}
