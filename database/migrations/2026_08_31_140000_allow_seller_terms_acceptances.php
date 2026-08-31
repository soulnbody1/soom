<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('auction_terms_acceptances', function (Blueprint $table): void {
            $table->dropForeign(['participant_id']);
        });

        Schema::table('auction_terms_acceptances', function (Blueprint $table): void {
            $table->foreignId('participant_id')->nullable()->change();
        });

        Schema::table('auction_terms_acceptances', function (Blueprint $table): void {
            $table->foreign('participant_id')->references('id')->on('auction_participants')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('auction_terms_acceptances', function (Blueprint $table): void {
            $table->dropForeign(['participant_id']);
        });

        Schema::table('auction_terms_acceptances', function (Blueprint $table): void {
            $table->foreignId('participant_id')->nullable(false)->change();
        });

        Schema::table('auction_terms_acceptances', function (Blueprint $table): void {
            $table->foreign('participant_id')->references('id')->on('auction_participants')->cascadeOnDelete();
        });
    }
};
