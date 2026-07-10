<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('auction_deposits')) {
            return;
        }

        Schema::table('auction_deposits', function (Blueprint $table) {
            if (! Schema::hasColumn('auction_deposits', 'verified_at')) {
                $table->timestamp('verified_at')->nullable()->after('paid_at');
            }

            if (! Schema::hasColumn('auction_deposits', 'verified_by')) {
                $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete()->after('verified_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('auction_deposits', function (Blueprint $table) {
            $table->dropForeign(['verified_by']);
            $table->dropColumn(['verified_at', 'verified_by']);
        });
    }
};
