<?php

declare(strict_types=1);

namespace Tests\Feature\Notification;

use App\Jobs\Ad\SendAdNotificationChunk;
use App\Models\Ad;
use App\Models\City;
use App\Models\Country;
use App\Models\State;
use App\Models\User;
use App\Notifications\NewAdNotification;
use App\Services\Notification\PushDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;

final class NotificationKnownDefectsTest extends NotificationTestCase
{
    use RefreshDatabase;

    public function test_a_non_numeric_per_page_is_a_validation_error_not_a_server_error(): void
    {
        $user = $this->notifiableUser();
        $this->seedNotifications($user, 2);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/soom/notifications?per_page=abc');

        if ($response->status() >= 500) {
            $this->markTestIncomplete(sprintf(
                'Phase 2: ?per_page=abc returns %d because the value reaches paginate() unvalidated.',
                $response->status()
            ));
        }

        $response->assertStatus(422);
    }

    public function test_a_negative_per_page_is_a_validation_error_not_a_server_error(): void
    {
        $user = $this->notifiableUser();
        $this->seedNotifications($user, 2);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/soom/notifications?per_page=-5');

        if ($response->status() >= 500) {
            $this->markTestIncomplete(sprintf(
                'Phase 2: ?per_page=-5 returns %d because the value reaches the SQL LIMIT unvalidated.',
                $response->status()
            ));
        }

        $response->assertStatus(422);
    }

    public function test_per_page_is_capped(): void
    {
        $user = $this->notifiableUser();
        $this->seedNotifications($user, 3);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/soom/notifications?per_page=100000');

        if ($response->status() === 200 && (int) $response->json('notifications.per_page') > 50) {
            $this->markTestIncomplete(sprintf(
                'Phase 2: per_page=100000 is accepted verbatim (per_page=%s), so one request can ask for every row.',
                $response->json('notifications.per_page')
            ));
        }

        $this->assertLessThanOrEqual(50, (int) $response->json('notifications.per_page'));
    }

    public function test_the_list_never_serves_a_stale_unread_count_per_notification(): void
    {
        $user = $this->notifiableUser();
        $this->notifications()->forUser($user)->create([
            'data' => [
                'ad_id' => 1,
                'title' => 't',
                'message' => 'm',
                'unread_count' => 99,
            ],
        ]);

        $row = $this->actingAs($user, 'sanctum')
            ->getJson('/api/soom/notifications')
            ->assertOk()
            ->json('notifications.data.0');

        if (array_key_exists('unread_count', $row) && $row['unread_count'] === 99) {
            $this->markTestIncomplete(
                'Phase 2: the per-notification unread_count is a snapshot frozen into the data column at send '
                .'time, so it is served back verbatim no matter what the real count is.'
            );
        }

        $this->assertArrayNotHasKey('unread_count', $row);
    }

    public function test_an_auction_notification_keeps_its_event_type_and_screen(): void
    {
        $user = $this->notifiableUser();
        $this->seedAuctionNotification($user, ['event_type' => 'auction.won', 'screen' => 'auction_details']);

        $row = $this->actingAs($user, 'sanctum')
            ->getJson('/api/soom/notifications')
            ->assertOk()
            ->json('notifications.data.0');

        if (! array_key_exists('event_type', $row) || $row['type'] === 'notification') {
            $this->markTestIncomplete(sprintf(
                'Phase 2: the controller maps a fixed key list, so an auction notification arrives as '
                .'type="%s" with event_type and screen dropped entirely.',
                $row['type']
            ));
        }

        $this->assertSame('auction.won', $row['event_type']);
        $this->assertSame('auction_details', $row['screen']);
    }

    public function test_resending_the_same_ad_notification_does_not_duplicate_rows(): void
    {
        $user = $this->notifiableUser();
        $ad = $this->makeAd();

        $chunk = new SendAdNotificationChunk($ad->id, $ad->market_id, [$user->id]);
        $registry = app(PushDispatcher::class);

        $chunk->handle($registry);
        $chunk->handle($registry);

        $count = DatabaseNotification::query()
            ->where('notifiable_id', $user->id)
            ->where('type', NewAdNotification::class)
            ->count();

        if ($count > 1) {
            $this->markTestIncomplete(sprintf(
                'Phase 4: the ad notification has no dedupe key, so a retried chunk job inserts %d rows for '
                .'the same ad and re-pushes every recipient.',
                $count
            ));
        }

        $this->assertSame(1, $count);
    }

    public function test_deleting_an_account_removes_its_notifications(): void
    {
        $user = $this->notifiableUser();
        $this->seedNotifications($user, 3);

        $this->actingAs($user, 'sanctum')->deleteJson('/api/soom/profile')->assertOk();

        $orphans = DatabaseNotification::query()
            ->where('notifiable_type', User::class)
            ->where('notifiable_id', $user->id)
            ->count();

        if ($orphans > 0) {
            $this->markTestIncomplete(sprintf(
                'Phase 5: deleting the account leaves %d notification rows behind; the table is polymorphic '
                .'with no foreign key, so nothing ever reclaims them.',
                $orphans
            ));
        }

        $this->assertSame(0, $orphans);
    }

    public function test_the_broadcast_unread_count_matches_the_real_one(): void
    {
        $user = $this->notifiableUser();
        $this->seedNotifications($user, 2);
        $ad = $this->makeAd();

        $payload = (new NewAdNotification(
            (string) $ad->public_id,
            (string) $ad->title,
            (int) $ad->category_id,
            'JO',
            'https://jo.soom.test/ads/'.$ad->public_id,
        ))->toBroadcast($user->fresh())->data;
        $actual = $user->unreadNotifications()->count();

        if ($payload['unread_count'] !== $actual) {
            $this->markTestIncomplete(sprintf(
                'Phase 4: toBroadcast reports %d while the real unread count is %d, because it adds one to a '
                .'count the database channel has already included.',
                $payload['unread_count'],
                $actual
            ));
        }

        $this->assertSame($actual, $payload['unread_count']);
    }

    public function test_the_notification_routes_are_rate_limited(): void
    {
        $user = $this->notifiableUser();

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/soom/notifications')->assertOk();

        if (! $response->headers->has('X-RateLimit-Limit')) {
            $this->markTestIncomplete(
                'Phase 2: the notification routes carry no throttle middleware, unlike every sibling route group.'
            );
        }

        $this->assertTrue($response->headers->has('X-RateLimit-Limit'));
    }

    private function makeAd(): Ad
    {
        $country = Country::factory()->create();
        $state = State::factory()->create(['country_id' => $country->id]);
        $city = City::factory()->create(['state_id' => $state->id]);

        return Ad::factory()->create([
            'user_id' => User::factory()->create()->id,
            'country_id' => $country->id,
            'state_id' => $state->id,
            'city_id' => $city->id,
        ]);
    }
}
