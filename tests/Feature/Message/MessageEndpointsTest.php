<?php

declare(strict_types=1);

namespace Tests\Feature\Message;

use App\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

final class MessageEndpointsTest extends MessageTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->requireMysql();
    }

    public function test_every_message_route_rejects_a_guest(): void
    {
        $routes = [
            ['postJson', '/api/soom/messages'],
            ['getJson', '/api/soom/messages/chat/1'],
            ['getJson', '/api/soom/messages/conversations'],
            ['postJson', '/api/soom/messages/markAsRead'],
            ['deleteJson', '/api/soom/messages/delete'],
            ['getJson', '/api/soom/messages/search'],
        ];

        foreach ($routes as [$method, $uri]) {
            $this->{$method}($uri)->assertUnauthorized();
        }
    }

    public function test_store_persists_the_message_and_returns_the_message_resource(): void
    {
        $sender = $this->chatUser();
        $receiver = $this->chatUser();

        $response = $this->actingAs($sender, 'sanctum')
            ->postJson('/api/soom/messages', [
                'receiver_id' => $receiver->id,
                'content' => 'مرحبا',
            ]);

        $response->assertOk()
            ->assertJson([
                'status' => true,
                'message' => 'تم إرسال الرسالة بنجاح',
            ]);

        $this->assertSame($this->messageResourceKeys(), array_keys($response->json('data')));
        $this->assertSame('مرحبا', $response->json('data.content'));
        $this->assertSame($sender->id, $response->json('data.sender_id'));
        $this->assertSame($receiver->id, $response->json('data.receiver_id'));
        $this->assertFalse($response->json('data.is_read'));
        $this->assertNull($response->json('data.ad'));
        $this->assertNull($response->json('data.attachment_url'));

        $this->assertDatabaseHas('messages', [
            'sender_id' => $sender->id,
            'receiver_id' => $receiver->id,
            'content' => 'مرحبا',
            'is_read' => 0,
        ]);
    }

    public function test_store_rejects_an_unknown_receiver(): void
    {
        $sender = $this->chatUser();

        $this->actingAs($sender, 'sanctum')
            ->postJson('/api/soom/messages', ['receiver_id' => 999999, 'content' => 'hi'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('receiver_id');
    }

    public function test_conversation_returns_both_directions_newest_first(): void
    {
        $viewer = $this->chatUser();
        $partner = $this->chatUser();

        $outbound = $this->sendFixture($viewer, $partner, ['content' => 'first']);
        $inbound = $this->sendFixture($partner, $viewer, ['content' => 'second']);

        $response = $this->actingAs($viewer, 'sanctum')
            ->getJson('/api/soom/messages/chat/'.$partner->id);

        $response->assertOk()->assertJson(['status' => true]);

        $this->assertSame($this->messageResourceKeys(), array_keys($response->json('data.0')));
        $this->assertSame([$inbound->id, $outbound->id], array_column($response->json('data'), 'id'));
        $this->assertSame(20, $response->json('meta.per_page'));
        $this->assertSame(2, $response->json('meta.total'));
    }

    public function test_conversation_hides_messages_the_viewer_deleted(): void
    {
        $viewer = $this->chatUser();
        $partner = $this->chatUser();

        $kept = $this->sendFixture($partner, $viewer, ['content' => 'kept']);
        $hidden = $this->sendFixture($partner, $viewer, ['content' => 'hidden']);
        $this->hideFromUser($viewer, $hidden);

        $response = $this->actingAs($viewer, 'sanctum')
            ->getJson('/api/soom/messages/chat/'.$partner->id);

        $this->assertSame([$kept->id], array_column($response->json('data'), 'id'));

        $partnerResponse = $this->actingAs($partner, 'sanctum')
            ->getJson('/api/soom/messages/chat/'.$viewer->id);

        $this->assertEqualsCanonicalizing(
            [$kept->id, $hidden->id],
            array_column($partnerResponse->json('data'), 'id')
        );
    }

    public function test_conversation_hides_outbound_messages_the_viewer_deleted(): void
    {
        $viewer = $this->chatUser();
        $partner = $this->chatUser();

        $kept = $this->sendFixture($viewer, $partner, ['content' => 'kept']);
        $hidden = $this->sendFixture($viewer, $partner, ['content' => 'hidden']);
        $this->hideFromUser($viewer, $hidden);

        $response = $this->actingAs($viewer, 'sanctum')
            ->getJson('/api/soom/messages/chat/'.$partner->id);

        $this->assertSame([$kept->id], array_column($response->json('data'), 'id'));
    }

    public function test_conversation_with_a_stranger_is_empty(): void
    {
        $viewer = $this->chatUser();
        $stranger = $this->chatUser();
        $other = $this->chatUser();

        $this->sendFixture($stranger, $other, ['content' => 'private']);

        $response = $this->actingAs($viewer, 'sanctum')
            ->getJson('/api/soom/messages/chat/'.$stranger->id);

        $response->assertOk();
        $this->assertSame([], $response->json('data'));
    }

    public function test_conversations_list_groups_by_partner_with_unread_counts(): void
    {
        $viewer = $this->chatUser();
        $partner = $this->chatUser();

        $this->sendFixture($viewer, $partner, ['content' => 'mine']);
        $newest = $this->sendFixture($partner, $viewer, ['content' => 'theirs']);

        $response = $this->actingAs($viewer, 'sanctum')
            ->getJson('/api/soom/messages/conversations');

        $response->assertOk()->assertJson(['status' => true]);

        $this->assertSame($this->conversationResourceKeys(), array_keys($response->json('data.0')));
        $this->assertSame(
            $this->conversationLastMessageKeys(),
            array_keys($response->json('data.0.last_message'))
        );
        $this->assertSame($partner->id, $response->json('data.0.user.id'));
        $this->assertSame($newest->id, $response->json('data.0.last_message.id'));
        $this->assertFalse($response->json('data.0.last_message.from_me'));
        $this->assertSame(1, (int) $response->json('data.0.unread_count'));
        $this->assertSame(1, $response->json('TotalUnreadConversationsCount'));
    }

    public function test_conversations_list_excludes_threads_the_viewer_deleted_entirely(): void
    {
        $viewer = $this->chatUser();
        $partner = $this->chatUser();

        $message = $this->sendFixture($partner, $viewer);
        $this->hideFromUser($viewer, $message);

        $response = $this->actingAs($viewer, 'sanctum')
            ->getJson('/api/soom/messages/conversations');

        $this->assertSame([], $response->json('data'));
        $this->assertSame(0, $response->json('TotalUnreadConversationsCount'));
    }

    public function test_search_filters_conversations_by_partner_name(): void
    {
        $viewer = $this->chatUser();
        $match = $this->chatUser(['name' => 'Zainab Khalil']);
        $miss = $this->chatUser(['name' => 'Omar Nasser']);

        $this->sendFixture($match, $viewer);
        $this->sendFixture($miss, $viewer);

        $response = $this->actingAs($viewer, 'sanctum')
            ->getJson('/api/soom/messages/search?search=zainab');

        $response->assertOk()->assertJson(['status' => true]);

        $this->assertSame([$match->id], array_column(
            array_column($response->json('data'), 'user'),
            'id'
        ));
    }

    public function test_search_without_a_term_returns_every_conversation(): void
    {
        $viewer = $this->chatUser();
        $this->makeThreads($viewer, 3);

        $response = $this->actingAs($viewer, 'sanctum')
            ->getJson('/api/soom/messages/search');

        $response->assertOk();
        $this->assertCount(3, $response->json('data'));
    }

    public function test_mark_as_read_flips_only_inbound_unread_messages(): void
    {
        $viewer = $this->chatUser();
        $partner = $this->chatUser();
        $other = $this->chatUser();

        $inbound = $this->sendFixture($partner, $viewer);
        $outbound = $this->sendFixture($viewer, $partner);
        $unrelated = $this->sendFixture($other, $viewer);

        $this->actingAs($viewer, 'sanctum')
            ->postJson('/api/soom/messages/markAsRead', ['user_id' => $partner->id])
            ->assertOk()
            ->assertExactJson(['status' => true]);

        $this->assertTrue($inbound->refresh()->is_read);
        $this->assertFalse($outbound->refresh()->is_read);
        $this->assertFalse($unrelated->refresh()->is_read);
    }

    public function test_delete_with_message_ids_hides_them_from_the_deleter_only(): void
    {
        $viewer = $this->chatUser();
        $partner = $this->chatUser();

        $target = $this->sendFixture($partner, $viewer, ['content' => 'target']);
        $kept = $this->sendFixture($partner, $viewer, ['content' => 'kept']);

        $this->actingAs($viewer, 'sanctum')
            ->deleteJson('/api/soom/messages/delete', [
                'user_id' => $partner->id,
                'message_ids' => [$target->id],
            ])
            ->assertOk()
            ->assertJson(['status' => true, 'message' => 'تم الحذف بنجاح']);

        $this->assertDatabaseHas('message_deletions', [
            'user_id' => $viewer->id,
            'message_id' => $target->id,
        ]);
        $this->assertDatabaseHas('messages', ['id' => $target->id]);

        $response = $this->actingAs($viewer, 'sanctum')
            ->getJson('/api/soom/messages/chat/'.$partner->id);

        $this->assertSame([$kept->id], array_column($response->json('data'), 'id'));
    }

    public function test_delete_without_message_ids_hides_the_whole_thread(): void
    {
        $viewer = $this->chatUser();
        $partner = $this->chatUser();

        $this->makeThread($viewer, $partner, inbound: 2, outbound: 0);

        $this->actingAs($viewer, 'sanctum')
            ->deleteJson('/api/soom/messages/delete', ['user_id' => $partner->id])
            ->assertOk()
            ->assertJson(['status' => true]);

        $this->assertSame(2, Message::count());
        $this->assertSame(2, (int) DB::table('message_deletions')->where('user_id', $viewer->id)->count());

        $response = $this->actingAs($viewer, 'sanctum')
            ->getJson('/api/soom/messages/conversations');

        $this->assertSame([], $response->json('data'));
    }

    public function test_paging_a_conversation_never_repeats_a_message(): void
    {
        $viewer = $this->chatUser();
        $partner = $this->chatUser();

        $this->makeThread($viewer, $partner, 15, 15);
        Message::query()->update(['created_at' => now()->subHour()]);

        $first = $this->actingAs($viewer, 'sanctum')
            ->getJson('/api/soom/messages/chat/'.$partner->id.'?page=1')->assertOk();
        $second = $this->actingAs($viewer, 'sanctum')
            ->getJson('/api/soom/messages/chat/'.$partner->id.'?page=2')->assertOk();

        $firstIds = array_column($first->json('data'), 'id');
        $secondIds = array_column($second->json('data'), 'id');

        $this->assertSame([], array_intersect($firstIds, $secondIds));
        $this->assertCount(30, array_unique(array_merge($firstIds, $secondIds)));
    }

    public function test_delete_requires_a_user_id(): void
    {
        $viewer = $this->chatUser();

        $this->actingAs($viewer, 'sanctum')
            ->deleteJson('/api/soom/messages/delete', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('user_id');
    }
}
