<?php

declare(strict_types=1);

namespace Tests\Feature\Ad;

use App\Models\Ad;
use App\Models\City;
use App\Models\Country;
use App\Models\State;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;

final class AdSecurityTest extends AdTestCase
{
    use RefreshDatabase;

    public function test_a_state_from_another_country_is_rejected(): void
    {
        $otherCountry = Country::factory()->create();
        $otherState = State::factory()->create(['country_id' => $otherCountry->id]);

        $this->postAd(['state_id' => $otherState->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['state_id']);
    }

    public function test_a_city_from_another_state_is_rejected(): void
    {
        $otherState = State::factory()->create(['country_id' => $this->country()->id]);
        $otherCity = City::factory()->create(['state_id' => $otherState->id]);

        $this->postAd(['city_id' => $otherCity->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['city_id']);
    }

    public function test_a_negative_price_is_rejected(): void
    {
        $this->postAd(['price' => -5])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['price']);
    }

    public function test_an_over_long_description_is_rejected(): void
    {
        $this->postAd(['description' => str_repeat('a', 5001)])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['description']);
    }

    public function test_svg_uploads_are_rejected(): void
    {
        $this->postAd([], [UploadedFile::fake()->create('payload.svg', 8, 'image/svg+xml')])
            ->assertStatus(422);

        $this->assertDatabaseCount('ads', 0);
    }

    public function test_malformed_attribute_json_does_not_pass_through_as_null(): void
    {
        Queue::fake();

        $this->postAd(['attributes' => '{not json'])->assertCreated();

        $this->assertDatabaseCount('attribute_values', 0);
    }

    public function test_a_soft_deleted_ad_cannot_be_favorited(): void
    {
        $ad = $this->makeAd();
        $ad->delete();

        $this->actingAs($this->adUser(), 'sanctum')
            ->postJson('/api/soom/favorites', ['ad_id' => $ad->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['ad_id']);
    }

    public function test_a_forbidden_update_keeps_its_arabic_message_and_gains_an_error_code(): void
    {
        $ad = $this->makeAd(['user_id' => $this->adUser()->id]);

        $response = $this->actingAs($this->adUser(), 'sanctum')
            ->putJson('/api/soom/ads/my/'.$ad->id, [
                'title' => 'Hijacked',
                'description' => 'Not mine',
                'price' => 1,
                'category_id' => $ad->category_id,
                'country_id' => $this->country()->id,
                'state_id' => $this->state()->id,
                'city_id' => $this->city()->id,
                'images' => [UploadedFile::fake()->image('x.jpg')],
            ]);

        $response->assertForbidden()
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'forbidden')
            ->assertJsonPath('message', '⚠️ ليس لديك صلاحية لتعديل هذا الإعلان.');
    }

    public function test_the_public_listing_is_rate_limited(): void
    {
        config(['ads.rate_limits.public_per_minute' => 3]);

        foreach (range(1, 3) as $ignored) {
            $this->getJson('/api/soom/ads')->assertOk();
        }

        $this->getJson('/api/soom/ads')->assertStatus(429);
    }

    public function test_creating_ads_is_rate_limited_per_user(): void
    {
        Queue::fake();
        config(['ads.rate_limits.write_per_hour' => 2]);

        $owner = $this->adUser();

        $this->postAd([], null, $owner)->assertCreated();
        $this->postAd([], null, $owner)->assertCreated();
        $this->postAd([], null, $owner)->assertStatus(429);

        $this->assertSame(2, Ad::count());
    }

    public function test_the_share_page_hides_a_blocked_ad(): void
    {
        $ad = $this->makeAd();
        $ad->delete();

        $this->get('/share/show/'.$ad->id)->assertNotFound();
    }

    private function postAd(array $overrides = [], ?array $images = null, ?object $actingAs = null)
    {
        Queue::fake();

        $payload = array_merge([
            'title' => 'Security fixture',
            'description' => 'Payload under test',
            'price' => 10,
            'category_id' => $this->category()->id,
            'country_id' => $this->country()->id,
            'state_id' => $this->state()->id,
            'city_id' => $this->city()->id,
        ], $overrides);

        $payload['images'] = $images ?? [UploadedFile::fake()->image('a.jpg')];

        return $this->actingAs($actingAs ?? $this->adUser(), 'sanctum')
            ->postJson('/api/soom/ads', $payload);
    }
}
