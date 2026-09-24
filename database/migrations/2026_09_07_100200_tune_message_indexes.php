<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addIndexIfMissing(
            'messages',
            ['sender_id', 'receiver_id', 'id'],
            'idx_messages_thread'
        );
        $this->addIndexIfMissing(
            'messages',
            ['receiver_id', 'is_read', 'sender_id'],
            'idx_messages_unread'
        );

        foreach ([
            'idx_messages_sender_thread',
            'idx_messages_receiver_thread',
            'messages_sender_id_index',
            'messages_receiver_id_index',
            'messages_is_read_index',
            'messages_created_at_index',
        ] as $index) {
            $this->dropIndexIfPresent('messages', $index);
        }

        $this->dropIndexIfPresent('message_deletions', 'message_deletions_user_id_message_id_index');
    }

    public function down(): void
    {
        $this->addIndexIfMissing(
            'message_deletions',
            ['user_id', 'message_id'],
            'message_deletions_user_id_message_id_index'
        );

        $this->addIndexIfMissing(
            'messages',
            ['sender_id', 'receiver_id'],
            'idx_messages_sender_thread'
        );
        $this->addIndexIfMissing(
            'messages',
            ['receiver_id', 'sender_id', 'is_read'],
            'idx_messages_receiver_thread'
        );
        $this->addIndexIfMissing('messages', ['sender_id'], 'messages_sender_id_index');
        $this->addIndexIfMissing('messages', ['receiver_id'], 'messages_receiver_id_index');
        $this->addIndexIfMissing('messages', ['is_read'], 'messages_is_read_index');
        $this->addIndexIfMissing('messages', ['created_at'], 'messages_created_at_index');

        $this->dropIndexIfPresent('messages', 'idx_messages_thread');
        $this->dropIndexIfPresent('messages', 'idx_messages_unread');
    }

    /** @param array<int, string> $columns */
    private function addIndexIfMissing(string $tableName, array $columns, string $index): void
    {
        if (Schema::hasIndex($tableName, $index)) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($columns, $index): void {
            $table->index($columns, $index);
        });
    }

    private function dropIndexIfPresent(string $tableName, string $index): void
    {
        if (! Schema::hasIndex($tableName, $index)) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($index): void {
            $table->dropIndex($index);
        });
    }
};
