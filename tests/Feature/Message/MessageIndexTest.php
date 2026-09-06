<?php

declare(strict_types=1);

namespace Tests\Feature\Message;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

final class MessageIndexTest extends MessageTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->requireMysql('index shapes are read from SHOW INDEX');
    }

    public function test_the_chat_tables_carry_no_redundant_index(): void
    {
        $this->assertSame(
            [
                'PRIMARY' => ['id'],
                'idx_messages_thread' => ['sender_id', 'receiver_id', 'id'],
                'idx_messages_unread' => ['receiver_id', 'is_read', 'sender_id'],
                'messages_ad_id_index' => ['ad_id'],
            ],
            $this->indexesOf('messages')
        );

        $this->assertSame(
            [
                'PRIMARY' => ['id'],
                'message_deletions_message_id_foreign' => ['message_id'],
                'message_deletions_user_id_message_id_unique' => ['user_id', 'message_id'],
            ],
            $this->indexesOf('message_deletions')
        );
    }

    public function test_the_thread_lookup_uses_the_thread_index(): void
    {
        $viewer = $this->chatUser();
        $partner = $this->chatUser();
        $this->makeThread($viewer, $partner, 3, 3);

        $this->assertUsesIndex(
            'idx_messages_thread',
            'select * from messages where sender_id = ? and receiver_id = ? order by id desc',
            [$viewer->id, $partner->id]
        );
    }

    public function test_the_unread_rollup_uses_the_unread_index(): void
    {
        $viewer = $this->chatUser();
        $this->makeThreads($viewer, 3);

        $this->assertUsesIndex(
            'idx_messages_unread',
            'select count(distinct sender_id) from messages where receiver_id = ? and is_read = 0',
            [$viewer->id]
        );
    }

    /**
     * @return array<string, list<string>>
     */
    private function indexesOf(string $table): array
    {
        $indexes = [];

        foreach (DB::select('SHOW INDEX FROM '.$table) as $row) {
            $indexes[$row->Key_name][(int) $row->Seq_in_index] = $row->Column_name;
        }

        foreach ($indexes as $name => $columns) {
            ksort($columns);
            $indexes[$name] = array_values($columns);
        }

        ksort($indexes);

        return $indexes;
    }

    private function assertUsesIndex(string $index, string $sql, array $bindings): void
    {
        $plan = DB::select('EXPLAIN '.$sql, $bindings);
        $used = array_filter(array_column($plan, 'key'));

        $this->assertContains(
            $index,
            $used,
            "Expected {$index} to serve [{$sql}], plan used: ".json_encode($used).'.'
        );
    }
}
