<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('content_review_settings')) {
            return;
        }

        Schema::create('content_review_settings', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('scope', 40);
            $table->unsignedInteger('version_number');
            $table->json('settings');
            $table->boolean('is_active')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('published_at')->nullable();
            $table->timestampsTz();

            $table->unique(['scope', 'version_number'], 'uq_content_review_settings_version');
            $table->index(['scope', 'is_active'], 'idx_content_review_settings_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_review_settings');
    }
};
