<?php

declare(strict_types=1);

namespace Tests\Feature\Message;

use App\Events\ConversationUpdated;
use App\Events\MessageSent;
use App\Jobs\Message\BroadcastConversationUpdate;
use App\Jobs\SendFcmNotification;
use App\Models\Message;
use App\Services\FCMService;
use App\Services\Message\Support\ChatPresence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Mockery;
use RuntimeException;

final class MessageDeliveryTest extends MessageTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->requireMysql();
        $this->fakePresence(false);
    }

    public function test_a_push_failure_does_not_fail_an_already_persisted_send(): void
    {
        $sender = $this->chatUser();
        $receiver = $this->chatUser(['fcm_token' => 'token-that-is-no-longer-registered']);

        $this->mock(FCMService::class)
            ->shouldReceive('sendToToken')
            ->andThrow(new RuntimeException('UNREGISTERED'));

        $this->actingAs($sender, 'sanctum')
            ->postJson('/api/soom/messages', [
                'receiver_id' => $receiver->id,
                'content' => 'delivered anyway',
            ])
            ->assertOk()
            ->assertJsonPath('status', true);

        $this->assertDatabaseHas('messages', ['content' => 'delivered anyway']);
    }

    public function test_a_realtime_broadcast_failure_does_not_fail_the_send(): void
    {
        $sender = $this->chatUser();
        $receiver = $this->chatUser();

        Event::listen(MessageSent::class, function (): void {
            throw new RuntimeException('pusher is unreachable');
        });

        $this->actingAs($sender, 'sanctum')
            ->postJson('/api/soom/messages', [
                'receiver_id' => $receiver->id,
                'content' => 'still delivered',
            ])
            ->assertOk();

        $this->assertDatabaseHas('messages', ['content' => 'still delivered']);
    }

    public function test_a_presence_failure_does_not_fail_the_send(): void
    {
        $this->instance(ChatPresence::class, Mockery::mock(ChatPresence::class, function ($mock): void {
            $mock->shouldReceive('bothPresent')->andThrow(new RuntimeException('presence timeout'));
        }));

        $sender = $this->chatUser();
        $receiver = $this->chatUser();

        $response = $this->actingAs($sender, 'sanctum')
            ->postJson('/api/soom/messages', [
                'receiver_id' => $receiver->id,
                'content' => 'presence down',
            ]);

        $response->assertOk();
        $this->assertDatabaseHas('messages', ['content' => 'presence down']);
    }

    public function test_the_push_is_queued_rather_than_sent_inline(): void
    {
        Queue::fake();

        $sender = $this->chatUser();
        $receiver = $this->chatUser(['fcm_token' => 'token-abc']);

        $this->actingAs($sender, 'sanctum')
            ->postJson('/api/soom/messages', ['receiver_id' => $receiver->id, 'content' => 'hi'])
            ->assertOk();

        Queue::assertPushed(SendFcmNotification::class, 1);
    }

    public function test_no_push_is_queued_for_a_receiver_without_a_token(): void
    {
        Queue::fake();

        $sender = $this->chatUser();
        $receiver = $this->chatUser(['fcm_token' => null]);

        $this->actingAs($sender, 'sanctum')
            ->postJson('/api/soom/messages', ['receiver_id' => $receiver->id, 'content' => 'hi'])
            ->assertOk();

        Queue::assertNotPushed(SendFcmNotification::class);
    }

    public function test_the_conversation_fan_out_is_queued_once_per_participant(): void
    {
        Queue::fake();

        $sender = $this->chatUser();
        $receiver = $this->chatUser();

        $this->actingAs($sender, 'sanctum')
            ->postJson('/api/soom/messages', ['receiver_id' => $receiver->id, 'content' => 'hi'])
            ->assertOk();

        Queue::assertPushed(BroadcastConversationUpdate::class, 2);

        Queue::assertPushed(
            BroadcastConversationUpdate::class,
            fn (BroadcastConversationUpdate $job): bool => $job->viewerId === $receiver->id
                && $job->partnerId === $sender->id
                && $job->withUnreadCount === true
        );

        Queue::assertPushed(
            BroadcastConversationUpdate::class,
            fn (BroadcastConversationUpdate $job): bool => $job->viewerId === $sender->id
                && $job->partnerId === $receiver->id
                && $job->withUnreadCount === false
        );
    }

    public function test_the_message_sent_event_fires_exactly_once(): void
    {
        Queue::fake();
        Event::fake([MessageSent::class, ConversationUpdated::class]);

        $sender = $this->chatUser();
        $receiver = $this->chatUser();

        $this->actingAs($sender, 'sanctum')
            ->postJson('/api/soom/messages', ['receiver_id' => $receiver->id, 'content' => 'hi'])
            ->assertOk();

        Event::assertDispatched(MessageSent::class, 1);
        Event::assertNotDispatched(ConversationUpdated::class);
    }

    public function test_deleting_queues_the_conversation_update_with_the_legacy_event_name(): void
    {
        Queue::fake();

        $viewer = $this->chatUser();
        $partner = $this->chatUser();
        $this->makeThread($viewer, $partner, 1, 0);

        $this->actingAs($viewer, 'sanctum')
            ->deleteJson('/api/soom/messages/delete', ['user_id' => $partner->id])
            ->assertOk();

        Queue::assertPushed(
            BroadcastConversationUpdate::class,
            fn (BroadcastConversationUpdate $job): bool => $job->eventName === ConversationUpdated::DELETED
        );
    }

    public function test_the_delete_event_keeps_its_wire_name(): void
    {
        $event = new ConversationUpdated((object) [], 1, ConversationUpdated::DELETED);

        $this->assertSame('Message.delete', $event->broadcastAs());
        $this->assertSame('conversation.updated', (new ConversationUpdated((object) [], 1))->broadcastAs());
    }

    public function test_the_send_path_makes_at_most_one_outbound_call(): void
    {
        $calls = 0;

        $this->instance(ChatPresence::class, Mockery::mock(ChatPresence::class, function ($mock) use (&$calls): void {
            $mock->shouldReceive('bothPresent')->andReturnUsing(function () use (&$calls): bool {
                $calls++;

                return false;
            });
        }));

        Queue::fake();
        Event::fake();

        $sender = $this->chatUser();
        $receiver = $this->chatUser(['fcm_token' => 'token-abc']);

        $this->actingAs($sender, 'sanctum')
            ->postJson('/api/soom/messages', ['receiver_id' => $receiver->id, 'content' => 'hi'])
            ->assertOk();

        $this->assertSame(1, $calls);
    }

    public function test_a_present_pair_marks_the_message_read_on_send(): void
    {
        $this->fakePresence(true);

        $sender = $this->chatUser();
        $receiver = $this->chatUser();

        $response = $this->actingAs($sender, 'sanctum')
            ->postJson('/api/soom/messages', ['receiver_id' => $receiver->id, 'content' => 'seen'])
            ->assertOk();

        $this->assertTrue($response->json('data.is_read'));
        $this->assertTrue(Message::firstWhere('content', 'seen')->is_read);
    }

    private function fakePresence(bool $present): void
    {
        $this->instance(ChatPresence::class, Mockery::mock(ChatPresence::class, function ($mock) use ($present): void {
            $mock->shouldReceive('bothPresent')->andReturn($present);
        }));
    }
}
