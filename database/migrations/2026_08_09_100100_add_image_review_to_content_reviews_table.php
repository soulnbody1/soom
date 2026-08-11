<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('content_reviews') || Schema::hasColumn('content_reviews', 'image_review')) {
            return;
        }

        Schema::table('content_reviews', function (Blueprint $table) {
            $table->json('image_review')->nullable()->after('images_analyzed');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('content_reviews') || ! Schema::hasColumn('content_reviews', 'image_review')) {
            return;
        }

        Schema::table('content_reviews', function (Blueprint $table) {
            $table->dropColumn('image_review');
        });
    }
};
