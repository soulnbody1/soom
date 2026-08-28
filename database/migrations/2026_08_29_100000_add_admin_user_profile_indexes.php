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
            $table->index(['sender_id', 'receiver_id'], 'idx_messages_sender_thread');
            $table->index(['receiver_id', 'sender_id', 'is_read'], 'idx_messages_receiver_thread');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            $table->dropIndex('idx_messages_sender_thread');
            $table->dropIndex('idx_messages_receiver_thread');
        });
    }
};
