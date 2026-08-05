<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('content_reviews')) {
            return;
        }

        Schema::create('content_reviews', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('subject_type', 40);
            $table->unsignedBigInteger('subject_id');
            $table->char('content_hash', 64);
            $table->string('trigger', 30);
            $table->string('mode', 20);
            $table->string('status', 20);
            $table->string('outcome', 30)->nullable();
            $table->string('reason_code', 60)->nullable();
            $table->string('recommendation', 20)->nullable();
            $table->unsignedTinyInteger('confidence')->nullable();
            $table->string('risk_level', 20)->nullable();
            $table->boolean('requires_human_review')->default(true);
            $table->string('summary_ar', 600)->nullable();
            $table->string('summary_en', 600)->nullable();
            $table->json('findings')->nullable();
            $table->json('violations')->nullable();
            $table->json('missing_information')->nullable();
            $table->json('policy_checks')->nullable();
            $table->json('categories')->nullable();
            $table->json('deterministic_findings')->nullable();
            $table->string('provider', 40)->nullable();
            $table->string('model', 80)->nullable();
            $table->string('prompt_version', 40)->nullable();
            $table->unsignedSmallInteger('result_schema_version')->default(1);
            $table->unsignedBigInteger('policy_id')->nullable();
            $table->unsignedInteger('policy_version')->nullable();
            $table->unsignedInteger('settings_version')->nullable();
            $table->unsignedTinyInteger('attempt')->default(1);
            $table->unsignedTinyInteger('max_attempts')->default(3);
            $table->string('error_code', 60)->nullable();
            $table->string('error_message', 500)->nullable();
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->unsignedBigInteger('cost_micros')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->unsignedTinyInteger('image_count')->default(0);
            $table->unsignedTinyInteger('images_analyzed')->default(0);
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->char('lease_owner', 26)->nullable();
            $table->timestampTz('leased_until')->nullable();
            $table->unsignedTinyInteger('current_marker')->nullable();
            $table->timestampTz('queued_at')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('decided_at')->nullable();
            $table->timestampTz('superseded_at')->nullable();
            $table->timestampsTz();

            $table->unique(['subject_type', 'subject_id', 'current_marker'], 'uq_content_review_active_subject');
            $table->unique(['subject_type', 'subject_id', 'content_hash', 'attempt'], 'uq_content_review_attempt');
            $table->index(['status', 'queued_at'], 'idx_content_review_sweeper');
            $table->index(['subject_type', 'subject_id', 'created_at'], 'idx_content_review_subject_history');
            $table->index('created_at', 'idx_content_review_created');
            $table->foreign('policy_id', 'fk_content_review_policy')
                ->references('id')->on('content_review_policies')->nullOnDelete();
        });

        $this->addCheckConstraints();
    }

    public function down(): void
    {
        Schema::dropIfExists('content_reviews');
    }

    private function addCheckConstraints(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'pgsql'], true)) {
            return;
        }

        DB::statement('ALTER TABLE content_reviews ADD CONSTRAINT chk_content_review_confidence CHECK (confidence IS NULL OR (confidence BETWEEN 0 AND 100))');
        DB::statement('ALTER TABLE content_reviews ADD CONSTRAINT chk_content_review_marker CHECK (current_marker IS NULL OR current_marker = 1)');
    }
};
