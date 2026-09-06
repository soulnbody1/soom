<?php

declare(strict_types=1);

namespace Tests\Feature\Message;

use Illuminate\Support\Facades\Storage;
use Tests\Concerns\AssertsQueryCount;
use Tests\Feature\Message\Concerns\CreatesChatFixtures;
use Tests\TestCase;

abstract class MessageTestCase extends TestCase
{
    use AssertsQueryCount;
    use CreatesChatFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('spaces');
    }

    protected function requireMysql(string $reason = 'the chat queries emit MySQL-only SQL'): void
    {
        $connection = config('database.default');

        if (config("database.connections.{$connection}.driver") !== 'mysql') {
            $this->markTestSkipped('Requires MySQL: '.$reason.'.');
        }
    }

    /**
     * @return list<string>
     */
    protected function messageResourceKeys(): array
    {
        return [
            'id', 'sender_id', 'receiver_id', 'content', 'is_read',
            'attachment_url', 'attachment_type', 'ad', 'created_at',
        ];
    }

    /**
     * @return list<string>
     */
    protected function conversationResourceKeys(): array
    {
        return ['user', 'last_message', 'unread_count'];
    }

    /**
     * @return list<string>
     */
    protected function conversationLastMessageKeys(): array
    {
        return ['id', 'content', 'from_me', 'created_at'];
    }
}
