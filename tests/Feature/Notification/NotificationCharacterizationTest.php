<?php

declare(strict_types=1);

namespace Tests\Feature\Notification;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

final class NotificationCharacterizationTest extends NotificationTestCase
{
    use RefreshDatabase;

    public function test_index_returns_the_unread_count_and_a_paginator(): void
    {
        $user = $this->notifiableUser();
        $this->seedNotifications($user, 3, 2);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/soom/notifications')->assertOk();

        $this->assertSame(['unread_count', 'notifications'], array_keys($response->json()));
        $this->assertSame(3, $response->json('unread_count'));
        $this->assertSame(5, $response->json('notifications.total'));
        $this->assertSame(10, $response->json('notifications.per_page'));
        $this->assertSame($this->notificationKeys(), array_keys($response->json('notifications.data.0')));
    }

    public function test_index_orders_newest_first(): void
    {
        $user = $this->notifiableUser();
        $rows = $this->seedNotifications($user, 3);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/soom/notifications')->assertOk();

        $this->assertSame(
            $rows->first()->id,
            $response->json('notifications.data.0.id')
        );
    }

    public function test_index_only_returns_the_callers_own_notifications(): void
    {
        $user = $this->notifiableUser();
        $stranger = $this->notifiableUser();
        $this->seedNotifications($user, 2);
        $this->seedNotifications($stranger, 4);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/soom/notifications')->assertOk();

        $this->assertSame(2, $response->json('notifications.total'));
        $this->assertSame(2, $response->json('unread_count'));
    }

    public function test_index_honours_an_explicit_per_page(): void
    {
        $user = $this->notifiableUser();
        $this->seedNotifications($user, 6);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/soom/notifications?per_page=2')
            ->assertOk();

        $this->assertSame(2, $response->json('notifications.per_page'));
        $this->assertCount(2, $response->json('notifications.data'));
        $this->assertSame(3, $response->json('notifications.last_page'));
    }

    public function test_index_maps_the_ad_payload_keys(): void
    {
        $user = $this->notifiableUser();
        $this->notifications()->forUser($user)->create([
            'data' => [
                'ad_id' => 77,
                'title' => 'ad title',
                'category_id' => 9,
                'message' => 'body',
            ],
        ]);

        $row = $this->actingAs($user, 'sanctum')
            ->getJson('/api/soom/notifications')
            ->assertOk()
            ->json('notifications.data.0');

        $this->assertSame(77, $row['ad_id']);
        $this->assertSame(9, $row['category_id']);
        $this->assertSame('ad title', $row['title']);
        $this->assertSame('body', $row['message']);
        $this->assertNull($row['read_at']);
    }

    public function test_index_is_empty_for_a_user_without_notifications(): void
    {
        $response = $this->actingAs($this->notifiableUser(), 'sanctum')
            ->getJson('/api/soom/notifications')
            ->assertOk();

        $this->assertSame(0, $response->json('unread_count'));
        $this->assertSame([], $response->json('notifications.data'));
    }

    public function test_mark_all_as_read_clears_every_unread_row(): void
    {
        $user = $this->notifiableUser();
        $this->seedNotifications($user, 4, 1);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/soom/notifications/mark-all-as-read')
            ->assertOk()
            ->assertJsonPath('message', 'All notifications marked as read.')
            ->assertJsonPath('updated', 4)
            ->assertJsonPath('unread_count', 0);

        $this->assertSame(0, $user->unreadNotifications()->count());
        $this->assertSame(5, $user->notifications()->count());
    }

    public function test_mark_all_as_read_leaves_other_users_alone(): void
    {
        $user = $this->notifiableUser();
        $stranger = $this->notifiableUser();
        $this->seedNotifications($user, 2);
        $this->seedNotifications($stranger, 3);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/soom/notifications/mark-all-as-read')
            ->assertOk();

        $this->assertSame(3, $stranger->unreadNotifications()->count());
    }

    public function test_mark_single_as_read_returns_the_refreshed_unread_count(): void
    {
        $user = $this->notifiableUser();
        $rows = $this->seedNotifications($user, 3);

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/soom/notifications/'.$rows->first()->id.'/read')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Notification marked as read.')
            ->assertJsonPath('unread_count', 2);

        $this->assertNotNull($rows->first()->fresh()->read_at);
    }

    public function test_mark_single_as_read_is_idempotent(): void
    {
        $user = $this->notifiableUser();
        $row = $this->seedNotifications($user, 1)->first();

        $this->actingAs($user, 'sanctum')->putJson('/api/soom/notifications/'.$row->id.'/read')->assertOk();
        $readAt = $row->fresh()->read_at;

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/soom/notifications/'.$row->id.'/read')
            ->assertOk()
            ->assertJsonPath('unread_count', 0);

        $this->assertEquals($readAt, $row->fresh()->read_at);
    }

    public function test_mark_single_as_read_rejects_another_users_notification(): void
    {
        $user = $this->notifiableUser();
        $stranger = $this->notifiableUser();
        $row = $this->seedNotifications($stranger, 1)->first();

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/soom/notifications/'.$row->id.'/read')
            ->assertStatus(404);

        $this->assertNull($row->fresh()->read_at);
    }

    public function test_every_endpoint_requires_authentication(): void
    {
        $this->getJson('/api/soom/notifications')->assertStatus(401);
        $this->postJson('/api/soom/notifications/mark-all-as-read')->assertStatus(401);
        $this->putJson('/api/soom/notifications/'.Str::uuid().'/read')->assertStatus(401);
    }
}
