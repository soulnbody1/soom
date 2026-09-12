<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const UNIQUE_INDEX = 'uq_refresh_tokens_token';

    private const EXPIRY_INDEX = 'idx_refresh_tokens_expires';

    public function up(): void
    {
        $this->removeDuplicateTokens();

        Schema::table('refresh_tokens', function (Blueprint $table): void {
            if (! $this->indexExists(self::UNIQUE_INDEX)) {
                $table->unique('token', self::UNIQUE_INDEX);
            }

            if (! $this->indexExists(self::EXPIRY_INDEX)) {
                $table->index('expires_at', self::EXPIRY_INDEX);
            }
        });
    }

    public function down(): void
    {
        Schema::table('refresh_tokens', function (Blueprint $table): void {
            if ($this->indexExists(self::UNIQUE_INDEX)) {
                $table->dropUnique(self::UNIQUE_INDEX);
            }

            if ($this->indexExists(self::EXPIRY_INDEX)) {
                $table->dropIndex(self::EXPIRY_INDEX);
            }
        });
    }

    private function removeDuplicateTokens(): void
    {
        $duplicates = DB::table('refresh_tokens')
            ->select('token')
            ->groupBy('token')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('token');

        foreach ($duplicates as $token) {
            $keep = DB::table('refresh_tokens')->where('token', $token)->max('id');

            DB::table('refresh_tokens')->where('token', $token)->where('id', '<', $keep)->delete();
        }
    }

    private function indexExists(string $name): bool
    {
        if (! Schema::hasTable('refresh_tokens')) {
            return false;
        }

        foreach (Schema::getIndexes('refresh_tokens') as $index) {
            if (strcasecmp((string) $index['name'], $name) === 0) {
                return true;
            }
        }

        return false;
    }
};
