<?php

declare(strict_types=1);

namespace Tests\Feature\User;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

final class AdminUsersCharacterizationTest extends UserTestCase
{
    use RefreshDatabase;

    public function test_index_lists_non_admin_users_including_blocked_ones(): void
    {
        $admin = $this->admin();
        $active = $this->member(['name' => 'نشط']);
        $blocked = $this->member(['name' => 'محظور']);
        $blocked->delete();

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/users')
            ->assertOk()
            ->assertJsonStructure(['data' => ['*' => $this->userResourceKeys()]]);

        $ids = array_column($response->json('data'), 'id');

        $this->assertContains($active->id, $ids);
        $this->assertContains($blocked->id, $ids);
        $this->assertNotContains($admin->id, $ids);
    }

    public function test_index_reports_whether_each_user_has_ads(): void
    {
        $admin = $this->admin();
        $withAds = $this->member();
        $this->ad($withAds);
        $withoutAds = $this->member();

        $rows = collect($this->actingAs($admin, 'sanctum')->getJson('/api/admin/users')->json('data'))
            ->keyBy('id');

        $this->assertTrue($rows[$withAds->id]['hasAds']);
        $this->assertFalse($rows[$withoutAds->id]['hasAds']);
    }

    public function test_index_is_forbidden_for_members(): void
    {
        $this->actingAs($this->member(), 'sanctum')
            ->getJson('/api/admin/users')
            ->assertForbidden();
    }

    public function test_index_stays_within_a_query_budget(): void
    {
        $admin = $this->admin();

        foreach (range(1, 15) as $ignored) {
            $this->ad($this->member());
        }

        $this->assertQueryCountAtMost(8, function () use ($admin) {
            $this->actingAs($admin, 'sanctum')->getJson('/api/admin/users')->assertOk();
        });
    }

    public function test_index_never_exposes_push_tokens(): void
    {
        $member = $this->member();
        $member->forceFill(['fcm_token' => 'secret-device-token'])->save();

        $rows = $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/admin/users')
            ->assertOk()
            ->json('data');

        foreach ($rows as $row) {
            $this->assertArrayNotHasKey('fcm_token', $row);
        }
    }

    public function test_analytics_splits_users_by_ad_ownership(): void
    {
        $this->ad($this->member());
        $this->member();

        $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/admin/users/analytics')
            ->assertOk()
            ->assertJsonStructure(['countUsersHasAds', 'countUsersNotHasAds']);
    }

    public function test_analytics_counts_blocked_users_too(): void
    {
        $admin = $this->admin();
        $blocked = $this->member();
        $this->ad($blocked);
        $blocked->delete();

        $analytics = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/users/analytics')
            ->assertOk()
            ->json();

        $this->assertSame(
            User::withTrashed()->where('role', '!=', 'admin')->count(),
            $analytics['countUsersHasAds'] + $analytics['countUsersNotHasAds']
        );
        $this->assertSame(1, $analytics['countUsersHasAds']);
    }

    public function test_analytics_excludes_admins(): void
    {
        $admin = $this->admin();
        $this->admin();
        $member = $this->member();

        $analytics = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/users/analytics')
            ->assertOk()
            ->json();

        $this->assertSame(1, $analytics['countUsersHasAds'] + $analytics['countUsersNotHasAds']);
        $this->assertSame(0, $analytics['countUsersHasAds']);
        $this->assertNotNull($member->id);
    }

    public function test_analytics_matches_the_listing_scope(): void
    {
        $admin = $this->admin();
        $this->ad($this->member());
        $this->member();
        $blocked = $this->member();
        $blocked->delete();

        $analytics = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/users/analytics')
            ->assertOk()
            ->json();

        $listed = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/users')
            ->assertOk()
            ->json('meta.total');

        $this->assertSame(
            (int) $listed,
            $analytics['countUsersHasAds'] + $analytics['countUsersNotHasAds']
        );
    }

    public function test_toggle_block_soft_deletes_then_restores_a_user(): void
    {
        $admin = $this->admin();
        $member = $this->member();

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/admin/users/toggle-block/{$member->id}")
            ->assertOk()
            ->assertJsonPath('message', 'تم حظر المستخدم .');

        $this->assertSoftDeleted('users', ['id' => $member->id]);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/admin/users/toggle-block/{$member->id}")
            ->assertOk()
            ->assertJsonPath('message', 'تم استرجاع المستخدم بنجاح.');

        $this->assertNotSoftDeleted('users', ['id' => $member->id]);
    }

    public function test_toggle_block_returns_not_found_for_unknown_user(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->putJson('/api/admin/users/toggle-block/999999')
            ->assertNotFound()
            ->assertJsonPath('message', 'المستخدم غير موجود.');
    }

    public function test_force_delete_removes_the_user_and_their_ads(): void
    {
        $member = $this->member();
        $ad = $this->ad($member);

        $this->actingAs($this->admin(), 'sanctum')
            ->deleteJson("/api/admin/users/force-delete/{$member->id}")
            ->assertOk();

        $this->assertDatabaseMissing('users', ['id' => $member->id]);
        $this->assertDatabaseMissing('ads', ['id' => $ad->id]);
    }

    public function test_force_delete_returns_not_found_for_unknown_user(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->deleteJson('/api/admin/users/force-delete/999999')
            ->assertNotFound()
            ->assertJsonPath('message', 'المستخدم غير موجود.');
    }

    public function test_force_delete_reaches_a_blocked_user(): void
    {
        $blocked = $this->member();
        $blocked->delete();

        $this->actingAs($this->admin(), 'sanctum')
            ->deleteJson("/api/admin/users/force-delete/{$blocked->id}")
            ->assertOk();

        $this->assertDatabaseMissing('users', ['id' => $blocked->id]);
    }

    public function test_force_delete_refuses_to_target_an_admin(): void
    {
        $peer = $this->admin();

        $this->actingAs($this->admin(), 'sanctum')
            ->deleteJson("/api/admin/users/force-delete/{$peer->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('users', ['id' => $peer->id]);
    }

    public function test_toggle_block_refuses_to_target_another_admin(): void
    {
        $peer = $this->admin();

        $this->actingAs($this->admin(), 'sanctum')
            ->putJson("/api/admin/users/toggle-block/{$peer->id}")
            ->assertForbidden();

        $this->assertNotSoftDeleted('users', ['id' => $peer->id]);
    }

    public function test_toggle_block_refuses_to_target_the_acting_admin(): void
    {
        $actor = $this->admin();

        $this->actingAs($actor, 'sanctum')
            ->putJson("/api/admin/users/toggle-block/{$actor->id}")
            ->assertForbidden();

        $this->assertNotSoftDeleted('users', ['id' => $actor->id]);
    }
}
