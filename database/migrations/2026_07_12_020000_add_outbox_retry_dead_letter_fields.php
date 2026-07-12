<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('outbox_messages')) {
            return;
        }

        Schema::table('outbox_messages', function (Blueprint $table): void {
            if (! Schema::hasColumn('outbox_messages', 'next_retry_at')) {
                $table->timestampTz('next_retry_at')->nullable()->after('available_at');
            }

            if (! Schema::hasColumn('outbox_messages', 'dead_lettered_at')) {
                $table->timestampTz('dead_lettered_at')->nullable()->after('published_at');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('outbox_messages')) {
            return;
        }

        Schema::table('outbox_messages', function (Blueprint $table): void {
            if (Schema::hasColumn('outbox_messages', 'dead_lettered_at')) {
                $table->dropColumn('dead_lettered_at');
            }

            if (Schema::hasColumn('outbox_messages', 'next_retry_at')) {
                $table->dropColumn('next_retry_at');
            }
        });
    }
};
