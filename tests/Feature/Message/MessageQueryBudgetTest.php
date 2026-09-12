<?php

declare(strict_types=1);

namespace Tests\Feature\Message;

use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

final class MessageQueryBudgetTest extends MessageTestCase
{
    use RefreshDatabase;

    private const CONVERSATIONS_BUDGET = 5;

    private const CHAT_BUDGET = 3;

    private const SEARCH_BUDGET = 4;

    private const SEND_BUDGET = 11;

    private const MARK_AS_READ_BUDGET = 3;

    protected function setUp(): void
    {
        parent::setUp();

        $this->requireMysql();
    }

    public function test_conversations_list_cost_does_not_grow_with_the_thread_count(): void
    {
        $viewer = $this->chatUser();

        $this->makeThreads($viewer, 3);
        $small = $this->measure($viewer, '/api/soom/messages/conversations');

        $this->makeThreads($viewer, 9);
        $large = $this->measure($viewer, '/api/soom/messages/conversations');

        $this->assertSame(
            $small,
            $large,
            "GET /messages/conversations cost grew from {$small} to {$large} when the thread count went from 3 to 12."
        );

        $this->assertLessThanOrEqual(self::CONVERSATIONS_BUDGET, $large);
    }

    public function test_conversation_cost_does_not_grow_with_the_message_count(): void
    {
        $viewer = $this->chatUser();
        $partner = $this->chatUser();

        $this->makeThread($viewer, $partner, 2, 2);
        $small = $this->measure($viewer, '/api/soom/messages/chat/'.$partner->id);

        $this->makeThread($viewer, $partner, 8, 8);
        $large = $this->measure($viewer, '/api/soom/messages/chat/'.$partner->id);

        $this->assertSame(
            $small,
            $large,
            "GET /messages/chat/{id} cost grew from {$small} to {$large} when the thread grew from 4 to 20 messages."
        );

        $this->assertLessThanOrEqual(self::CHAT_BUDGET, $large);
    }

    public function test_search_cost_does_not_grow_with_the_thread_count(): void
    {
        $viewer = $this->chatUser();

        $this->makeThreads($viewer, 3);
        $small = $this->measure($viewer, '/api/soom/messages/search?search=a');

        $this->makeThreads($viewer, 9);
        $large = $this->measure($viewer, '/api/soom/messages/search?search=a');

        $this->assertSame($small, $large);
        $this->assertLessThanOrEqual(self::SEARCH_BUDGET, $large);
    }

    public function test_sending_a_message_cost_does_not_grow_with_the_senders_thread_count(): void
    {
        $viewer = $this->chatUser();
        $partner = $this->chatUser();
        $this->makeThread($viewer, $partner, 1, 1);

        $small = $this->measureSend($viewer, $partner);

        $this->makeThreads($viewer, 12);
        $large = $this->measureSend($viewer, $partner);

        $this->assertSame(
            $small,
            $large,
            "POST /messages cost grew from {$small} to {$large} when the sender's thread count grew by 12."
        );

        $this->assertLessThanOrEqual(self::SEND_BUDGET, $large);
    }

    public function test_mark_as_read_stays_a_single_statement(): void
    {
        $viewer = $this->chatUser();
        $partner = $this->chatUser();
        $this->makeThread($viewer, $partner, 5, 0);

        $count = $this->countQueries(fn () => $this->actingAs($viewer, 'sanctum')
            ->postJson('/api/soom/messages/markAsRead', ['user_id' => $partner->id])
            ->assertOk());

        unset($count);

        $this->assertLessThanOrEqual(self::MARK_AS_READ_BUDGET, count($this->recordedQueries()));
    }

    public function test_reading_a_conversation_writes_nothing(): void
    {
        $viewer = $this->chatUser();
        $partner = $this->chatUser();
        $this->makeThread($viewer, $partner, 3, 3);

        $this->assertNoWriteQueries(fn () => $this->actingAs($viewer, 'sanctum')
            ->getJson('/api/soom/messages/chat/'.$partner->id)
            ->assertOk());

        $this->assertNoWriteQueries(fn () => $this->actingAs($viewer, 'sanctum')
            ->getJson('/api/soom/messages/conversations')
            ->assertOk());
    }

    public function test_the_conversations_list_is_paginated_and_hydrated_inside_the_database(): void
    {
        $viewer = $this->chatUser();
        $this->makeThreads($viewer, 45);

        $response = $this->countQueries(fn () => $this->actingAs($viewer, 'sanctum')
            ->getJson('/api/soom/messages/conversations')
            ->assertOk());

        $this->assertCount(20, $response->json('data'));
        $this->assertSame(45, $response->json('meta.total'));
        $this->assertSame(3, $response->json('meta.last_page'));

        $rollup = $this->matchQuery('group by');
        $this->assertStringContainsString('limit', $rollup, "The thread rollup must page in SQL: {$rollup}");

        foreach ($this->recordedQueries() as $sql) {
            if (str_starts_with($sql, 'select * from `users`')) {
                $this->fail('Partner hydration must select explicit columns, got: '.$sql);
            }
        }
    }

    public function test_per_page_is_honoured_and_capped(): void
    {
        $viewer = $this->chatUser();
        $this->makeThreads($viewer, 8);

        $this->actingAs($viewer, 'sanctum')
            ->getJson('/api/soom/messages/conversations?per_page=5')
            ->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('meta.per_page', 5);

        $this->actingAs($viewer, 'sanctum')
            ->getJson('/api/soom/messages/conversations?per_page=1000')
            ->assertStatus(422)
            ->assertJsonValidationErrors('per_page');
    }

    private function matchQuery(string $needle): string
    {
        foreach ($this->recordedQueries() as $sql) {
            if (str_contains($sql, $needle)) {
                return $sql;
            }
        }

        $this->fail("No recorded query contained [{$needle}].");
    }

    private function measure(User $viewer, string $uri): int
    {
        $this->countQueries(fn () => $this->actingAs($viewer, 'sanctum')->getJson($uri)->assertOk());

        return count($this->recordedQueries());
    }

    private function measureSend(User $sender, User $receiver): int
    {
        $this->countQueries(fn () => $this->actingAs($sender, 'sanctum')
            ->postJson('/api/soom/messages', [
                'receiver_id' => $receiver->id,
                'content' => 'budget probe',
            ])
            ->assertOk());

        Message::where('content', 'budget probe')->delete();

        return count($this->recordedQueries());
    }
}
