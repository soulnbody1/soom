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
        if (! Schema::hasTable('device_tokens')) {
            Schema::create('device_tokens', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('token', 512);
                $table->string('platform', 20)->nullable();
                $table->timestamp('last_used_at')->nullable();
                $table->timestamps();

                $table->unique('token', 'uq_device_tokens_token');
                $table->index(['user_id', 'last_used_at'], 'idx_device_tokens_user_used');
            });
        }

        $this->backfillFromUsers();
    }

    public function down(): void
    {
        Schema::dropIfExists('device_tokens');
    }

    private function backfillFromUsers(): void
    {
        if (! Schema::hasTable('users') || ! Schema::hasColumn('users', 'fcm_token')) {
            return;
        }

        DB::table('users')
            ->select('id', 'fcm_token', 'updated_at')
            ->whereNotNull('fcm_token')
            ->where('fcm_token', '!=', '')
            ->orderBy('id')
            ->chunkById(500, function ($users): void {
                $rows = [];

                foreach ($users as $user) {
                    $rows[$user->fcm_token] = [
                        'user_id' => $user->id,
                        'token' => $user->fcm_token,
                        'platform' => null,
                        'last_used_at' => $user->updated_at,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }

                foreach (array_values($rows) as $row) {
                    DB::table('device_tokens')->updateOrInsert(
                        ['token' => $row['token']],
                        $row
                    );
                }
            });
    }
};
