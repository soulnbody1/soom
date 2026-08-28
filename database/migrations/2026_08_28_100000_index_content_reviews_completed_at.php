<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Budget periods are summed over `completed_at`, because that is when a call was actually
 * billed. The existing `created_at` index does not serve that scan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_reviews', function (Blueprint $table): void {
            $table->index('completed_at', 'idx_content_review_completed');
        });
    }

    public function down(): void
    {
        Schema::table('content_reviews', function (Blueprint $table): void {
            $table->dropIndex('idx_content_review_completed');
        });
    }
};
