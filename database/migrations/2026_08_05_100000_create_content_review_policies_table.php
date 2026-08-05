<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('content_review_policies')) {
            return;
        }

        Schema::create('content_review_policies', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('subject_type', 40);
            $table->unsignedInteger('version_number');
            $table->string('name', 120);
            $table->json('policy');
            $table->string('prompt_version', 40)->default('v1');
            $table->unsignedSmallInteger('result_schema_version')->default(1);
            $table->boolean('is_active')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('published_at')->nullable();
            $table->timestampsTz();

            $table->unique(['subject_type', 'version_number'], 'uq_content_review_policy_version');
            $table->index(['subject_type', 'is_active'], 'idx_content_review_policy_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_review_policies');
    }
};
