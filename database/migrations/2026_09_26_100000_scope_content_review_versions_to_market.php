<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_review_policies', function (Blueprint $table): void {
            $table->dropUnique('uq_content_review_policy_version');
            $table->unique(
                ['market_id', 'subject_type', 'version_number'],
                'uq_review_policy_market_subject_version'
            );
        });

        Schema::table('content_review_settings', function (Blueprint $table): void {
            $table->dropUnique('uq_content_review_settings_version');
            $table->unique(
                ['market_id', 'scope', 'version_number'],
                'uq_review_settings_market_scope_version'
            );
        });
    }

    public function down(): void
    {
        Schema::table('content_review_policies', function (Blueprint $table): void {
            $table->dropUnique('uq_review_policy_market_subject_version');
            $table->unique(['subject_type', 'version_number'], 'uq_content_review_policy_version');
        });

        Schema::table('content_review_settings', function (Blueprint $table): void {
            $table->dropUnique('uq_review_settings_market_scope_version');
            $table->unique(['scope', 'version_number'], 'uq_content_review_settings_version');
        });
    }
};
