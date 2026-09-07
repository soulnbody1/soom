<?php

declare(strict_types=1);

namespace Tests\Feature\Message;

use App\Models\Message;
use App\Models\User;
use App\Services\Message\Actions\DeleteMessagesAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

final class MessageDeletionTest extends MessageTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->requireMysql();
    }

    public function test_a_sender_recalls_a_message_for_both_sides_inside_the_window(): void
    {
        [$sender, $receiver, $message] = $this->aged(10);

        $this->deleteAs($sender, $receiver, [$message->id])->assertOk();

        $this->assertDatabaseMissing('messages', ['id' => $message->id]);
        $this->assertSame([], $this->conversationIds($receiver, $sender));
    }

    public function test_a_message_just_inside_the_window_is_still_recalled(): void
    {
        [$sender, $receiver, $message] = $this->aged(DeleteMessagesAction::RECALL_WINDOW_SECONDS - 5);

        $this->deleteAs($sender, $receiver, [$message->id])->assertOk();

        $this->assertDatabaseMissing('messages', ['id' => $message->id]);
    }

    public function test_a_message_past_the_window_is_hidden_from_the_sender_only(): void
    {
        [$sender, $receiver, $message] = $this->aged(DeleteMessagesAction::RECALL_WINDOW_SECONDS + 1);

        $this->deleteAs($sender, $receiver, [$message->id])->assertOk();

        $this->assertDatabaseHas('messages', ['id' => $message->id]);
        $this->assertDatabaseHas('message_deletions', [
            'user_id' => $sender->id,
            'message_id' => $message->id,
        ]);

        $this->assertSame([], $this->conversationIds($sender, $receiver));
        $this->assertSame([$message->id], $this->conversationIds($receiver, $sender));
    }

    public function test_a_receiver_never_recalls_a_message_even_inside_the_window(): void
    {
        [$sender, $receiver, $message] = $this->aged(5);

        $this->deleteAs($receiver, $sender, [$message->id])->assertOk();

        $this->assertDatabaseHas('messages', ['id' => $message->id]);
        $this->assertSame([], $this->conversationIds($receiver, $sender));
        $this->assertSame([$message->id], $this->conversationIds($sender, $receiver));
    }

    public function test_deleting_a_thread_recalls_only_the_callers_recent_messages(): void
    {
        $viewer = $this->chatUser();
        $partner = $this->chatUser();

        $recent = $this->sendFixture($viewer, $partner);
        $old = $this->sendFixture($viewer, $partner);
        $inbound = $this->sendFixture($partner, $viewer);

        $this->age($old, DeleteMessagesAction::RECALL_WINDOW_SECONDS + 60);
        $this->age($inbound, 5);

        $this->deleteAs($viewer, $partner)->assertOk();

        $this->assertDatabaseMissing('messages', ['id' => $recent->id]);
        $this->assertDatabaseHas('messages', ['id' => $old->id]);
        $this->assertDatabaseHas('messages', ['id' => $inbound->id]);

        $this->assertSame([], $this->conversationIds($viewer, $partner));
        $this->assertEqualsCanonicalizing(
            [$old->id, $inbound->id],
            $this->conversationIds($partner, $viewer)
        );
    }

    public function test_deleting_a_foreign_message_is_forbidden_and_writes_nothing(): void
    {
        $intruder = $this->chatUser();
        $alice = $this->chatUser();
        $bob = $this->chatUser();

        $private = $this->sendFixture($alice, $bob);

        $this->deleteAs($intruder, $alice, [$private->id])->assertForbidden();

        $this->assertDatabaseHas('messages', ['id' => $private->id]);
        $this->assertSame(0, DB::table('message_deletions')->count());
    }

    public function test_a_mixed_batch_containing_a_foreign_message_is_rejected_atomically(): void
    {
        $viewer = $this->chatUser();
        $partner = $this->chatUser();
        $stranger = $this->chatUser();

        $own = $this->sendFixture($viewer, $partner);
        $foreign = $this->sendFixture($stranger, $partner);

        $this->deleteAs($viewer, $partner, [$own->id, $foreign->id])->assertForbidden();

        $this->assertDatabaseHas('messages', ['id' => $own->id]);
        $this->assertDatabaseHas('messages', ['id' => $foreign->id]);
        $this->assertSame(0, DB::table('message_deletions')->count());
    }

    public function test_deleting_is_idempotent(): void
    {
        [$sender, $receiver, $message] = $this->aged(DeleteMessagesAction::RECALL_WINDOW_SECONDS + 1);

        $this->deleteAs($sender, $receiver, [$message->id])->assertOk();
        $this->deleteAs($sender, $receiver, [$message->id])->assertOk();

        $this->assertSame(1, DB::table('message_deletions')
            ->where('user_id', $sender->id)
            ->where('message_id', $message->id)
            ->count());
    }

    public function test_deleting_a_thread_costs_the_same_whatever_the_thread_size(): void
    {
        $viewer = $this->chatUser();
        $small = $this->chatUser();
        $large = $this->chatUser();

        $this->makeThread($viewer, $small, 1, 1);
        $this->makeThread($viewer, $large, 20, 20);
        Message::query()->update(['created_at' => now()->subDay()]);

        $this->countQueries(fn () => $this->deleteAs($viewer, $small)->assertOk());
        $smallCost = count($this->recordedQueries());

        $this->countQueries(fn () => $this->deleteAs($viewer, $large)->assertOk());
        $largeCost = count($this->recordedQueries());

        $this->assertSame(
            $smallCost,
            $largeCost,
            "Thread deletion cost grew from {$smallCost} to {$largeCost} between a 2-message and a 40-message thread."
        );
    }

    private function aged(int $seconds): array
    {
        $sender = $this->chatUser();
        $receiver = $this->chatUser();
        $message = $this->sendFixture($sender, $receiver);

        $this->age($message, $seconds);

        return [$sender, $receiver, $message];
    }

    private function age(Message $message, int $seconds): void
    {
        $message->forceFill(['created_at' => now()->subSeconds($seconds)])->saveQuietly();
    }

    private function deleteAs(User $actor, User $partner, ?array $messageIds = null)
    {
        $payload = ['user_id' => $partner->id];

        if ($messageIds !== null) {
            $payload['message_ids'] = $messageIds;
        }

        return $this->actingAs($actor, 'sanctum')->deleteJson('/api/soom/messages/delete', $payload);
    }

    private function conversationIds(User $viewer, User $partner): array
    {
        return array_column(
            $this->actingAs($viewer, 'sanctum')
                ->getJson('/api/soom/messages/chat/'.$partner->id)
                ->json('data'),
            'id'
        );
    }
}
