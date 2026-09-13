<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            $table->index(['sender_id', 'receiver_id', 'id'], 'idx_messages_thread');
            $table->index(['receiver_id', 'is_read', 'sender_id'], 'idx_messages_unread');
        });

        Schema::table('messages', function (Blueprint $table): void {
            $table->dropIndex('idx_messages_sender_thread');
            $table->dropIndex('idx_messages_receiver_thread');
            $table->dropIndex('messages_sender_id_index');
            $table->dropIndex('messages_receiver_id_index');
            $table->dropIndex('messages_is_read_index');
            $table->dropIndex('messages_created_at_index');
        });

        Schema::table('message_deletions', function (Blueprint $table): void {
            $table->dropIndex('message_deletions_user_id_message_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('message_deletions', function (Blueprint $table): void {
            $table->index(
                ['user_id', 'message_id'],
                'message_deletions_user_id_message_id_index'
            );
        });

        Schema::table('messages', function (Blueprint $table): void {
            $table->index(['sender_id', 'receiver_id'], 'idx_messages_sender_thread');
            $table->index(
                ['receiver_id', 'sender_id', 'is_read'],
                'idx_messages_receiver_thread'
            );

            $table->index('sender_id');
            $table->index('receiver_id');
            $table->index('is_read');
            $table->index('created_at');
        });

        Schema::table('messages', function (Blueprint $table): void {
            $table->dropIndex('idx_messages_thread');
            $table->dropIndex('idx_messages_unread');
        });
    }
};
