<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('content_review_decisions')) {
            return;
        }

        Schema::create('content_review_decisions', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->unsignedBigInteger('review_id')->nullable();
            $table->string('subject_type', 40);
            $table->unsignedBigInteger('subject_id');
            $table->string('decision', 20);
            $table->string('decided_by_type', 20);
            $table->foreignId('decided_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('relation_to_recommendation', 30)->nullable();
            $table->string('ai_recommendation', 20)->nullable();
            $table->unsignedTinyInteger('ai_confidence')->nullable();
            $table->text('reason')->nullable();
            $table->timestampTz('decided_at');
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['subject_type', 'subject_id', 'decided_at'], 'idx_content_review_decision_subject');
            $table->index('review_id', 'idx_content_review_decision_review');
            $table->index(['decided_by_type', 'decided_at'], 'idx_content_review_decision_actor');
            $table->foreign('review_id', 'fk_content_review_decision_review')
                ->references('id')->on('content_reviews')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_review_decisions');
    }
};
