<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Models\Ad;
use App\Models\City;
use App\Models\Country;
use App\Models\State;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class GeographyDeletionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('spaces');
    }

    public function test_a_country_holding_ads_cannot_be_deleted(): void
    {
        $ad = $this->adWithLocation();

        $this->admin()
            ->deleteJson('/api/admin/countries/'.$ad->country_id)
            ->assertStatus(422)
            ->assertJsonPath('errors.country.0', 'لا يمكن حذف دولة مرتبطة بمستخدمين أو إعلانات أو مزادات.');

        $this->assertDatabaseHas('countries', ['id' => $ad->country_id]);
        $this->assertDatabaseHas('ads', ['id' => $ad->id]);
    }

    public function test_a_state_holding_ads_cannot_be_deleted(): void
    {
        $ad = $this->adWithLocation();

        $this->admin()->deleteJson('/api/admin/states/'.$ad->state_id)->assertStatus(422);

        $this->assertDatabaseHas('states', ['id' => $ad->state_id]);
    }

    public function test_a_city_holding_ads_cannot_be_deleted(): void
    {
        $ad = $this->adWithLocation();

        $this->admin()->deleteJson('/api/admin/citys/'.$ad->city_id)->assertStatus(422);

        $this->assertDatabaseHas('cities', ['id' => $ad->city_id]);
    }

    public function test_a_city_holding_only_users_cannot_be_deleted(): void
    {
        $country = Country::factory()->create();
        $state = State::factory()->create(['country_id' => $country->id]);
        $city = City::factory()->create(['state_id' => $state->id]);

        User::factory()->create([
            'country_id' => $country->id,
            'state_id' => $state->id,
            'city_id' => $city->id,
        ]);

        $this->admin()->deleteJson('/api/admin/citys/'.$city->id)->assertStatus(422);

        $this->assertDatabaseHas('cities', ['id' => $city->id]);
    }

    public function test_a_soft_deleted_ad_still_blocks_the_delete(): void
    {
        $ad = $this->adWithLocation();
        $ad->delete();

        $this->admin()->deleteJson('/api/admin/citys/'.$ad->city_id)->assertStatus(422);

        $this->assertDatabaseHas('cities', ['id' => $ad->city_id]);
    }

    public function test_an_empty_city_is_still_deletable(): void
    {
        $state = State::factory()->create(['country_id' => Country::factory()->create()->id]);
        $city = City::factory()->create(['state_id' => $state->id]);

        $this->admin()->deleteJson('/api/admin/citys/'.$city->id)->assertOk();

        $this->assertDatabaseMissing('cities', ['id' => $city->id]);
    }

    public function test_an_empty_country_is_still_deletable(): void
    {
        $country = Country::factory()->create();
        State::factory()->create(['country_id' => $country->id]);

        $this->admin()->deleteJson('/api/admin/countries/'.$country->id)->assertOk();

        $this->assertDatabaseMissing('countries', ['id' => $country->id]);
    }

    private function adWithLocation(): Ad
    {
        return Ad::factory()->create();
    }

    private function admin(): self
    {
        return $this->actingAs(User::factory()->create(['role' => 'admin']), 'sanctum');
    }
}
