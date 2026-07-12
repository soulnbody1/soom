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

        Schema::table('auction_deposits', function (Blueprint $table): void {
            if (! Schema::hasColumn('auction_deposits', 'hold_reason')) {
                $table->string('hold_reason', 80)->nullable()->after('released_at');
            }

            if (! Schema::hasColumn('auction_deposits', 'hold_expires_at')) {
                $table->timestampTz('hold_expires_at')->nullable()->after('hold_reason');
            }

            if (! Schema::hasColumn('auction_deposits', 'hold_metadata')) {
                $table->json('hold_metadata')->nullable()->after('hold_expires_at');
            }
        });

        Schema::table('auction_deposits', function (Blueprint $table): void {
            if (! $this->indexExists('auction_deposits', 'idx_deposits_hold_reason')) {
                $table->index(['auction_id', 'hold_reason'], 'idx_deposits_hold_reason');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('auction_deposits')) {
            return;
        }

        Schema::table('auction_deposits', function (Blueprint $table): void {
            if ($this->indexExists('auction_deposits', 'idx_deposits_hold_reason')) {
                $table->dropIndex('idx_deposits_hold_reason');
            }
        });

        Schema::table('auction_deposits', function (Blueprint $table): void {
            foreach (['hold_metadata', 'hold_expires_at', 'hold_reason'] as $column) {
                if (Schema::hasColumn('auction_deposits', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $connection = Schema::getConnection();

        if ($connection->getDriverName() === 'sqlite') {
            return collect($connection->select("PRAGMA index_list('{$table}')"))
                ->contains(fn ($row): bool => ($row->name ?? null) === $indexName);
        }

        $database = $connection->getDatabaseName();
        $prefixedTable = $connection->getTablePrefix().$table;

        return $connection->selectOne(
            'SELECT 1 FROM information_schema.statistics WHERE table_schema = ? AND table_name = ? AND index_name = ? LIMIT 1',
            [$database, $prefixedTable, $indexName]
        ) !== null;
    }
};
