<?php

declare(strict_types=1);

namespace Tests\Feature\Message;

use App\Http\Requests\StoreMessageRequest;
use App\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;

final class MessageSecurityTest extends MessageTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->requireMysql();
        RateLimiter::clear('chat-send:user:0');
    }

    public function test_mark_as_read_rejects_a_non_numeric_partner(): void
    {
        $viewer = $this->chatUser();

        $this->actingAs($viewer, 'sanctum')
            ->postJson('/api/soom/messages/markAsRead', ['user_id' => 'not-an-id'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('user_id');
    }

    public function test_mark_as_read_rejects_an_unknown_partner(): void
    {
        $viewer = $this->chatUser();

        $this->actingAs($viewer, 'sanctum')
            ->postJson('/api/soom/messages/markAsRead', ['user_id' => 999999])
            ->assertStatus(422)
            ->assertJsonValidationErrors('user_id');
    }

    public function test_mark_as_read_requires_a_partner(): void
    {
        $viewer = $this->chatUser();

        $this->actingAs($viewer, 'sanctum')
            ->postJson('/api/soom/messages/markAsRead', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('user_id');
    }

    public function test_an_empty_message_is_rejected(): void
    {
        $sender = $this->chatUser();
        $receiver = $this->chatUser();

        $this->actingAs($sender, 'sanctum')
            ->postJson('/api/soom/messages', ['receiver_id' => $receiver->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('content');

        $this->assertSame(0, Message::count());
    }

    public function test_a_blank_content_string_is_rejected(): void
    {
        $sender = $this->chatUser();
        $receiver = $this->chatUser();

        $this->actingAs($sender, 'sanctum')
            ->postJson('/api/soom/messages', ['receiver_id' => $receiver->id, 'content' => '   '])
            ->assertStatus(422);

        $this->assertSame(0, Message::count());
    }

    public function test_a_user_cannot_message_themselves(): void
    {
        $sender = $this->chatUser();

        $this->actingAs($sender, 'sanctum')
            ->postJson('/api/soom/messages', ['receiver_id' => $sender->id, 'content' => 'hi'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('receiver_id');

        $this->assertSame(0, Message::count());
    }

    public function test_content_longer_than_the_limit_is_rejected(): void
    {
        $sender = $this->chatUser();
        $receiver = $this->chatUser();

        $this->actingAs($sender, 'sanctum')
            ->postJson('/api/soom/messages', [
                'receiver_id' => $receiver->id,
                'content' => str_repeat('a', StoreMessageRequest::MAX_CONTENT_LENGTH + 1),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('content');

        $this->assertSame(0, Message::count());
    }

    public function test_content_at_the_limit_is_accepted(): void
    {
        $sender = $this->chatUser();
        $receiver = $this->chatUser();

        $this->actingAs($sender, 'sanctum')
            ->postJson('/api/soom/messages', [
                'receiver_id' => $receiver->id,
                'content' => str_repeat('a', StoreMessageRequest::MAX_CONTENT_LENGTH),
            ])
            ->assertOk();

        $this->assertSame(1, Message::count());
    }

    public function test_the_send_route_is_rate_limited(): void
    {
        $sender = $this->chatUser();
        $receiver = $this->chatUser();

        config()->set('chat.rate_limits.send_per_minute', 3);
        RateLimiter::clear('chat-send:user:'.$sender->id);

        for ($i = 0; $i < 3; $i++) {
            $this->actingAs($sender, 'sanctum')
                ->postJson('/api/soom/messages', ['receiver_id' => $receiver->id, 'content' => 'x'.$i])
                ->assertOk();
        }

        $this->actingAs($sender, 'sanctum')
            ->postJson('/api/soom/messages', ['receiver_id' => $receiver->id, 'content' => 'over'])
            ->assertStatus(429);

        $this->assertSame(3, Message::count());

        RateLimiter::clear('chat-send:user:'.$sender->id);
    }

    public function test_the_read_routes_are_rate_limited(): void
    {
        $viewer = $this->chatUser();

        config()->set('chat.rate_limits.read_per_minute', 2);
        RateLimiter::clear('chat-read:user:'.$viewer->id);

        $this->actingAs($viewer, 'sanctum')->getJson('/api/soom/messages/conversations')->assertOk();
        $this->actingAs($viewer, 'sanctum')->getJson('/api/soom/messages/conversations')->assertOk();
        $this->actingAs($viewer, 'sanctum')->getJson('/api/soom/messages/conversations')->assertStatus(429);

        RateLimiter::clear('chat-read:user:'.$viewer->id);
    }

    public function test_a_non_numeric_conversation_id_is_not_routed(): void
    {
        $viewer = $this->chatUser();

        $this->actingAs($viewer, 'sanctum')
            ->getJson('/api/soom/messages/chat/abc')
            ->assertNotFound();
    }

    public function test_marking_a_conversation_read_refreshes_the_unread_badge(): void
    {
        $viewer = $this->chatUser();
        $partner = $this->chatUser();
        $this->makeThread($viewer, $partner, 2, 0);

        $before = $this->actingAs($viewer, 'sanctum')
            ->getJson('/api/soom/messages/conversations')
            ->json('TotalUnreadConversationsCount');

        $this->actingAs($viewer, 'sanctum')
            ->postJson('/api/soom/messages/markAsRead', ['user_id' => $partner->id])
            ->assertOk()
            ->assertExactJson(['status' => true]);

        $after = $this->actingAs($viewer, 'sanctum')
            ->getJson('/api/soom/messages/conversations')
            ->json('TotalUnreadConversationsCount');

        $this->assertSame(1, $before);
        $this->assertSame(0, $after);
    }
}
