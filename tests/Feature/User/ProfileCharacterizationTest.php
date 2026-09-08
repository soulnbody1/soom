<?php

declare(strict_types=1);

namespace Tests\Feature\User;

use App\Models\City;
use App\Models\Country;
use App\Models\State;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

final class ProfileCharacterizationTest extends UserTestCase
{
    use RefreshDatabase;

    public function test_show_returns_the_authenticated_profile_shape(): void
    {
        $this->actingAs($this->member(), 'sanctum')
            ->getJson('/api/soom/profile')
            ->assertOk()
            ->assertJsonStructure(['data' => $this->userResourceKeys()]);
    }

    public function test_show_requires_authentication(): void
    {
        $this->getJson('/api/soom/profile')->assertUnauthorized();
    }

    public function test_update_persists_editable_fields(): void
    {
        $user = $this->member();
        $country = Country::create(['name' => 'الأردن']);
        $state = State::create(['name' => 'عمان', 'country_id' => $country->id]);
        $city = City::create(['name' => 'صويلح', 'state_id' => $state->id]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/soom/profile', [
                'name' => 'اسم جديد',
                'gender' => 'male',
                'birth_date' => '1990-05-05',
                'country_id' => $country->id,
                'state_id' => $state->id,
                'city_id' => $city->id,
                'allow_ad_notifications' => false,
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'اسم جديد')
            ->assertJsonPath('data.gender', 'male')
            ->assertJsonPath('data.allow_ad_notifications', false)
            ->assertJsonPath('data.address', 'الأردن,عمان,صويلح');

        $this->assertSame('اسم جديد', $user->fresh()->name);
    }

    public function test_update_rejects_phone_changes(): void
    {
        $this->actingAs($this->member(), 'sanctum')
            ->postJson('/api/soom/profile', ['phone' => '+962700000999'])
            ->assertStatus(422);
    }

    public function test_update_rejects_role_escalation(): void
    {
        $user = $this->member();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/soom/profile', ['role' => 'admin'])
            ->assertOk();

        $this->assertSame('user', $user->fresh()->role);
    }

    public function test_update_stores_uploaded_logo(): void
    {
        $user = $this->member();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/soom/profile', ['logo' => UploadedFile::fake()->image('me.jpg')])
            ->assertOk();

        $stored = $user->fresh()->getRawOriginal('logo');

        $this->assertNotNull($stored);
        Storage::disk('spaces')->assertExists($stored);
        $this->assertStringContainsString($stored, (string) $response->json('data.logo'));
    }

    public function test_destroy_removes_the_account_and_its_ads(): void
    {
        $user = $this->member();
        $ad = $this->ad($user);

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/soom/profile')
            ->assertOk()
            ->assertJsonPath('message', 'تم حذف الحساب والإعلانات المرتبطة به نهائياً.');

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertDatabaseMissing('ads', ['id' => $ad->id]);
    }

    public function test_destroy_revokes_access_tokens(): void
    {
        $user = $this->member();
        $user->createToken('api');

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/soom/profile')
            ->assertOk();

        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_id' => $user->id,
            'tokenable_type' => 'App\Models\User',
        ]);
    }
}
