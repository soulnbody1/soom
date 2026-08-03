<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payout_destinations', function (Blueprint $table): void {
            if (! Schema::hasColumn('payout_destinations', 'deleted_at')) {
                $table->softDeletesTz();
            }
        });
    }

    public function down(): void
    {
        Schema::table('payout_destinations', function (Blueprint $table): void {
            if (Schema::hasColumn('payout_destinations', 'deleted_at')) {
                $table->dropSoftDeletesTz();
            }
        });
    }
};
