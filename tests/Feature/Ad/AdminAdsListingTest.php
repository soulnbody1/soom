<?php

declare(strict_types=1);

namespace Tests\Feature\Ad;

use App\Models\Ad;
use App\Models\Category;
use App\Models\City;
use App\Models\Country;
use App\Models\State;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AdminAdsListingTest extends TestCase
{
    // On MySQL the schema persists between tests, so a bare migrate would let one
    // test's ads inflate the next one's pagination totals.
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('spaces');
    }

    public function test_listing_exposes_pagination_metadata_and_the_active_count(): void
    {
        $owner = $this->user();
        $this->makeAds($owner, 25);

        $response = $this->actingAs($this->user('admin'), 'sanctum')
            ->getJson('/api/admin/ads');

        $response->assertOk()
            ->assertJsonStructure([
                'data',
                'meta' => ['current_page', 'last_page', 'per_page', 'total'],
                'active_ads',
            ]);

        $this->assertSame(25, $response->json('meta.total'));
        $this->assertSame(2, $response->json('meta.last_page'));
        $this->assertSame(25, $response->json('active_ads'));
    }

    public function test_every_page_is_reachable_and_returns_its_own_rows(): void
    {
        $owner = $this->user();
        $this->makeAds($owner, 25);

        $first = $this->actingAs($this->user('admin'), 'sanctum')
            ->getJson('/api/admin/ads?page=1');
        $second = $this->actingAs($this->user('admin'), 'sanctum')
            ->getJson('/api/admin/ads?page=2');

        $this->assertCount(20, $first->json('data'));
        $this->assertCount(5, $second->json('data'));
        $this->assertSame(2, $second->json('meta.current_page'));

        $firstIds = collect($first->json('data'))->pluck('id');
        $secondIds = collect($second->json('data'))->pluck('id');
        $this->assertEmpty($firstIds->intersect($secondIds));
    }

    public function test_status_filter_narrows_the_paginated_totals(): void
    {
        $owner = $this->user();
        $ads = $this->makeAds($owner, 6);
        $ads->take(2)->each(fn (Ad $ad) => $ad->delete());

        $admin = $this->user('admin');

        $active = $this->actingAs($admin, 'sanctum')->getJson('/api/admin/ads?status=active');
        $inactive = $this->actingAs($admin, 'sanctum')->getJson('/api/admin/ads?status=inactive');
        $all = $this->actingAs($admin, 'sanctum')->getJson('/api/admin/ads');

        $this->assertSame(4, $active->json('meta.total'));
        $this->assertSame(2, $inactive->json('meta.total'));
        $this->assertSame(6, $all->json('meta.total'));

        $this->assertSame(4, $all->json('active_ads'));
        $this->assertSame(4, $inactive->json('active_ads'));
    }

    private function makeAds(User $owner, int $count)
    {
        $category = Category::create(['name' => 'Category '.Str::ulid()]);
        $country = Country::create([
            'name' => 'Country '.Str::ulid(),
            'code' => strtoupper(substr((string) Str::ulid(), 0, 6)),
        ]);
        $state = State::create(['country_id' => $country->id, 'name' => 'State '.Str::ulid()]);
        $city = City::create(['state_id' => $state->id, 'name' => 'City '.Str::ulid()]);

        return collect(range(1, $count))->map(fn (int $index) => Ad::create([
            'user_id' => $owner->id,
            'category_id' => $category->id,
            'title' => "Ad {$index}",
            'description' => "Description {$index}",
            'price' => 100 + $index,
            'country_id' => $country->id,
            'state_id' => $state->id,
            'city_id' => $city->id,
        ]));
    }

    private function user(string $role = 'user'): User
    {
        $unique = strtolower((string) Str::ulid());
        $phoneSuffix = str_pad((string) (abs(crc32($unique)) % 10_000_000), 7, '0', STR_PAD_LEFT);

        return User::create([
            'name' => fake()->name(),
            'email' => "ads-{$unique}@example.test",
            'phone' => '+96270'.$phoneSuffix,
            'password' => Hash::make('password'),
            'role' => $role,
            'email_verified_at' => now(),
        ]);
    }
}
