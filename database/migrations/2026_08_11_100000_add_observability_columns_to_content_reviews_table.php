<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('content_reviews')) {
            return;
        }

        Schema::table('content_reviews', function (Blueprint $table) {
            if (! Schema::hasColumn('content_reviews', 'queue_delay_ms')) {
                $table->unsignedInteger('queue_delay_ms')->nullable()->after('duration_ms');
            }

            if (! Schema::hasColumn('content_reviews', 'image_cache_hits')) {
                $table->unsignedTinyInteger('image_cache_hits')->default(0)->after('images_analyzed');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('content_reviews')) {
            return;
        }

        Schema::table('content_reviews', function (Blueprint $table) {
            if (Schema::hasColumn('content_reviews', 'queue_delay_ms')) {
                $table->dropColumn('queue_delay_ms');
            }

            if (Schema::hasColumn('content_reviews', 'image_cache_hits')) {
                $table->dropColumn('image_cache_hits');
            }
        });
    }
};
