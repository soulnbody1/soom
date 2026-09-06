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
        Schema::table('password_reset_tokens', function (Blueprint $table): void {
            if (! Schema::hasColumn('password_reset_tokens', 'attempts')) {
                $table->unsignedTinyInteger('attempts')->default(0)->after('token');
            }
        });

        DB::table('password_reset_tokens')->delete();
    }

    public function down(): void
    {
        Schema::table('password_reset_tokens', function (Blueprint $table): void {
            if (Schema::hasColumn('password_reset_tokens', 'attempts')) {
                $table->dropColumn('attempts');
            }
        });
    }
};
