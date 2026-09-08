<?php

declare(strict_types=1);

namespace Tests\Feature\User;

use App\Exceptions\User\AccountDeletionBlockedException;
use App\Models\AdImage;
use App\Services\User\Actions\DeleteUserAccountAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class AccountDeletionTest extends UserTestCase
{
    use RefreshDatabase;

    public function test_replacing_a_logo_deletes_the_previous_file(): void
    {
        $user = $this->member();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/soom/profile', ['logo' => UploadedFile::fake()->image('first.jpg')])
            ->assertOk();

        $first = $user->fresh()->getRawOriginal('logo');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/soom/profile', ['logo' => UploadedFile::fake()->image('second.jpg')])
            ->assertOk();

        $second = $user->fresh()->getRawOriginal('logo');

        $this->assertNotSame($first, $second);
        Storage::disk('spaces')->assertMissing($first);
        Storage::disk('spaces')->assertExists($second);
    }

    public function test_updating_without_a_logo_keeps_the_current_file(): void
    {
        $user = $this->member();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/soom/profile', ['logo' => UploadedFile::fake()->image('me.jpg')])
            ->assertOk();

        $stored = $user->fresh()->getRawOriginal('logo');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/soom/profile', ['name' => 'اسم آخر'])
            ->assertOk();

        $this->assertSame($stored, $user->fresh()->getRawOriginal('logo'));
        Storage::disk('spaces')->assertExists($stored);
    }

    public function test_deleting_an_account_removes_its_ad_images_from_storage(): void
    {
        $user = $this->member();
        $ad = $this->ad($user);

        $imagePath = 'ads/'.Str::random(20).'.jpg';
        Storage::disk('spaces')->put($imagePath, 'binary');
        AdImage::create(['ad_id' => $ad->id, 'image_path' => $imagePath]);

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/soom/profile')
            ->assertOk();

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        Storage::disk('spaces')->assertMissing($imagePath);
    }

    public function test_deleting_an_account_removes_images_of_soft_deleted_ads(): void
    {
        $user = $this->member();
        $ad = $this->ad($user);

        $imagePath = 'ads/'.Str::random(20).'.jpg';
        Storage::disk('spaces')->put($imagePath, 'binary');
        AdImage::create(['ad_id' => $ad->id, 'image_path' => $imagePath]);
        $ad->delete();

        app(DeleteUserAccountAction::class)->execute($user);

        Storage::disk('spaces')->assertMissing($imagePath);
    }

    public function test_deleting_an_account_removes_its_logo(): void
    {
        $user = $this->member();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/soom/profile', ['logo' => UploadedFile::fake()->image('me.jpg')])
            ->assertOk();

        $logoPath = $user->fresh()->getRawOriginal('logo');

        $this->actingAs($user->fresh(), 'sanctum')
            ->deleteJson('/api/soom/profile')
            ->assertOk();

        Storage::disk('spaces')->assertMissing($logoPath);
    }

    public function test_blocked_deletion_returns_409_and_keeps_every_file(): void
    {
        $user = $this->member();
        $ad = $this->ad($user);

        $imagePath = 'ads/'.Str::random(20).'.jpg';
        Storage::disk('spaces')->put($imagePath, 'binary');
        AdImage::create(['ad_id' => $ad->id, 'image_path' => $imagePath]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/soom/profile', ['logo' => UploadedFile::fake()->image('me.jpg')])
            ->assertOk();

        $logoPath = $user->fresh()->getRawOriginal('logo');
        $this->lockUserWithFinancialActivity($user->id);

        $this->actingAs($user->fresh(), 'sanctum')
            ->deleteJson('/api/soom/profile')
            ->assertStatus(409)
            ->assertJsonPath('code', 'account_deletion_blocked');

        $this->assertDatabaseHas('users', ['id' => $user->id]);
        $this->assertDatabaseHas('ad_images', ['ad_id' => $ad->id]);
        Storage::disk('spaces')->assertExists($imagePath);
        Storage::disk('spaces')->assertExists($logoPath);
    }

    public function test_blocked_admin_force_delete_returns_409(): void
    {
        $member = $this->member();
        $this->lockUserWithFinancialActivity($member->id);

        $this->actingAs($this->admin(), 'sanctum')
            ->deleteJson("/api/admin/users/force-delete/{$member->id}")
            ->assertStatus(409)
            ->assertJsonPath('code', 'account_deletion_blocked');

        $this->assertDatabaseHas('users', ['id' => $member->id]);
    }

    public function test_the_action_throws_when_references_block_the_delete(): void
    {
        $user = $this->member();
        $this->lockUserWithFinancialActivity($user->id);

        $this->expectException(AccountDeletionBlockedException::class);

        app(DeleteUserAccountAction::class)->execute($user);
    }

    private function lockUserWithFinancialActivity(int $userId): void
    {
        DB::table('payout_destinations')->insert([
            'public_id' => (string) Str::ulid(),
            'user_id' => $userId,
            'recipient_name' => 'صاحب الحساب',
            'identifier_type' => 'cliq_alias',
            'identifier_value' => 'alias',
            'is_default' => true,
            'default_marker' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
