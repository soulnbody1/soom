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
        if (Schema::hasTable('content_review_image_checks')) {
            return;
        }

        Schema::create('content_review_image_checks', function (Blueprint $table) {
            $table->id();
            $table->char('image_sha256', 64);
            $table->string('provider', 40);
            $table->string('model', 80);
            $table->unsignedInteger('policy_version')->default(0);
            $table->unsignedSmallInteger('result_schema_version')->default(1);
            $table->string('verdict', 20);
            $table->string('risk_level', 20)->nullable();
            $table->json('findings')->nullable();
            $table->unsignedBigInteger('cost_micros')->nullable();
            $table->timestampTz('analyzed_at');
            $table->timestampsTz();

            $table->unique(
                ['image_sha256', 'provider', 'model', 'policy_version', 'result_schema_version'],
                'uq_content_review_image_check'
            );
            $table->index('image_sha256', 'idx_content_review_image_check_sha');
            $table->index('created_at', 'idx_content_review_image_check_created');
        });

        $this->addCheckConstraints();
    }

    public function down(): void
    {
        Schema::dropIfExists('content_review_image_checks');
    }

    private function addCheckConstraints(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'pgsql'], true)) {
            return;
        }

        DB::statement("ALTER TABLE content_review_image_checks ADD CONSTRAINT chk_content_review_image_check_verdict CHECK (verdict IN ('clean', 'flagged', 'needs_human'))");
        DB::statement('ALTER TABLE content_review_image_checks ADD CONSTRAINT chk_content_review_image_check_sha CHECK (CHAR_LENGTH(image_sha256) = 64)');
    }
};
