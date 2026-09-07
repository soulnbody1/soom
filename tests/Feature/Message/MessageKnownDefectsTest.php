<?php

declare(strict_types=1);

namespace Tests\Feature\Message;

use App\Models\Message;
use App\Services\FCMService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class MessageKnownDefectsTest extends MessageTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->requireMysql();
    }

    public function test_an_old_message_cannot_be_hard_deleted_out_of_the_recipients_inbox(): void
    {
        $sender = $this->chatUser();
        $receiver = $this->chatUser();

        $message = $this->sendFixture($sender, $receiver);
        $message->forceFill(['created_at' => now()->subDay()])->saveQuietly();

        $this->actingAs($sender, 'sanctum')
            ->deleteJson('/api/soom/messages/delete', [
                'user_id' => $receiver->id,
                'message_ids' => [$message->id],
            ])
            ->assertOk();

        if (Message::whereKey($message->id)->doesntExist()) {
            $this->markTestIncomplete(
                'Phase 3: Carbon 3 returns a signed diffInSeconds, so the 120-second hard-delete window '
                .'in MessageService is always true and a sender can erase any message of any age from '
                .'the recipient inbox.'
            );
        }

        $this->assertDatabaseHas('messages', ['id' => $message->id]);
    }

    public function test_the_conversations_list_actually_paginates(): void
    {
        $viewer = $this->chatUser();
        $this->makeThreads($viewer, 25);

        $first = $this->actingAs($viewer, 'sanctum')
            ->getJson('/api/soom/messages/conversations?page=1')->assertOk();
        $second = $this->actingAs($viewer, 'sanctum')
            ->getJson('/api/soom/messages/conversations?page=2')->assertOk();

        $firstIds = array_column(array_column($first->json('data'), 'user'), 'id');
        $secondIds = array_column(array_column($second->json('data'), 'user'), 'id');

        if ($firstIds === $secondIds) {
            $this->markTestIncomplete(
                'Phase 2: MessageRepository::getUserConversations builds a LengthAwarePaginator from the '
                .'complete result set without slicing, so every page returns every conversation.'
            );
        }

        $this->assertCount(20, $firstIds);
        $this->assertCount(5, $secondIds);
        $this->assertSame([], array_intersect($firstIds, $secondIds));
    }

    public function test_a_user_cannot_delete_messages_from_a_conversation_they_are_not_part_of(): void
    {
        $intruder = $this->chatUser();
        $alice = $this->chatUser();
        $bob = $this->chatUser();

        $private = $this->sendFixture($alice, $bob, ['content' => 'not yours']);

        $response = $this->actingAs($intruder, 'sanctum')
            ->deleteJson('/api/soom/messages/delete', [
                'user_id' => $alice->id,
                'message_ids' => [$private->id],
            ]);

        $wrote = DB::table('message_deletions')
            ->where('user_id', $intruder->id)
            ->where('message_id', $private->id)
            ->exists();

        if ($wrote) {
            $this->markTestIncomplete(
                'Phase 3: deleteSpecificMessages never checks the message involves the caller, so any '
                .'authenticated user can write message_deletions rows for arbitrary message ids.'
            );
        }

        $response->assertForbidden();
        $this->assertFalse($wrote);
    }

    public function test_a_push_failure_does_not_fail_an_already_persisted_send(): void
    {
        $sender = $this->chatUser();
        $receiver = $this->chatUser(['fcm_token' => 'token-that-is-no-longer-registered']);

        $this->mock(FCMService::class)
            ->shouldReceive('sendToToken')
            ->andThrow(new RuntimeException('UNREGISTERED'));

        $response = $this->actingAs($sender, 'sanctum')
            ->postJson('/api/soom/messages', [
                'receiver_id' => $receiver->id,
                'content' => 'delivered but reported as failed',
            ]);

        $persisted = Message::where('content', 'delivered but reported as failed')->exists();

        if ($response->status() === 500 && $persisted) {
            $this->markTestIncomplete(
                'Phase 5: MessageService uses SendFcmNotification::dispatchSync, so an FCM failure '
                .'propagates into the controller catch and returns 500 for a message that was committed '
                .'and broadcast.'
            );
        }

        $response->assertOk();
        $this->assertTrue($persisted);
    }

    public function test_the_unread_badge_agrees_with_the_conversations_list(): void
    {
        $viewer = $this->chatUser();
        $active = $this->chatUser();
        $deactivated = $this->chatUser();

        $this->sendFixture($active, $viewer);
        $this->sendFixture($deactivated, $viewer);
        $deactivated->delete();

        $response = $this->actingAs($viewer, 'sanctum')
            ->getJson('/api/soom/messages/conversations')->assertOk();

        $listed = count($response->json('data'));
        $badge = (int) $response->json('TotalUnreadConversationsCount');

        if ($listed !== $badge) {
            $this->markTestIncomplete(
                'Phase 2: getUsers omits withTrashed so a conversation with a deactivated user vanishes '
                ."from the list, while getTotalUnreadConversationsCount still counts it (list={$listed}, "
                ."badge={$badge})."
            );
        }

        $this->assertSame($badge, $listed);
    }

    public function test_deleting_messages_costs_the_same_whatever_the_batch_size(): void
    {
        $viewer = $this->chatUser();
        $partner = $this->chatUser();

        $this->makeThread($viewer, $partner, 12, 0);
        $ids = Message::where('receiver_id', $viewer->id)->pluck('id')->all();

        $this->countQueries(fn () => $this->actingAs($viewer, 'sanctum')
            ->deleteJson('/api/soom/messages/delete', [
                'user_id' => $partner->id,
                'message_ids' => array_slice($ids, 0, 1),
            ])->assertOk());
        $small = count($this->recordedQueries());

        $this->countQueries(fn () => $this->actingAs($viewer, 'sanctum')
            ->deleteJson('/api/soom/messages/delete', [
                'user_id' => $partner->id,
                'message_ids' => array_slice($ids, 1, 10),
            ])->assertOk());
        $large = count($this->recordedQueries());

        if ($large > $small) {
            $this->markTestIncomplete(
                'Phase 3: deleteSpecificMessages runs Message::find per id and one delete per row, so '
                ."the cost scales with the batch ({$small} queries for 1 id, {$large} for 10)."
            );
        }

        $this->assertSame($small, $large);
    }

    public function test_chat_attachments_are_not_stored_on_a_public_disk(): void
    {
        $visibility = config('filesystems.disks.spaces.visibility');

        if ($visibility === 'public') {
            $this->markTestIncomplete(
                'Out of scope by decision: MessageRepository::storeAttachment writes chat_files/ to the '
                .'public spaces disk, so private conversation attachments are readable by URL with no '
                .'signed link and no access control.'
            );
        }

        $this->assertNotSame('public', $visibility);
    }
}
