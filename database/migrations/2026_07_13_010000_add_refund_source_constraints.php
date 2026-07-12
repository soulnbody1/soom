<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('refund_transactions')) {
            return;
        }

        Schema::table('refund_transactions', function (Blueprint $table): void {
            if (! Schema::hasColumn('refund_transactions', 'obligation_type')) {
                $table->string('obligation_type', 40)->nullable()->after('payment_transaction_id');
            }

            if (! Schema::hasColumn('refund_transactions', 'obligation_id')) {
                $table->unsignedBigInteger('obligation_id')->nullable()->after('obligation_type');
            }
        });

        Schema::table('refund_transactions', function (Blueprint $table): void {
            if (! $this->indexExists('refund_transactions', 'uq_refund_source_payment_transaction')) {
                $table->unique('payment_transaction_id', 'uq_refund_source_payment_transaction');
            }

            if (! $this->indexExists('refund_transactions', 'idx_refund_obligation')) {
                $table->index(['obligation_type', 'obligation_id'], 'idx_refund_obligation');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('refund_transactions')) {
            return;
        }

        Schema::table('refund_transactions', function (Blueprint $table): void {
            if ($this->indexExists('refund_transactions', 'uq_refund_source_payment_transaction')) {
                $table->dropUnique('uq_refund_source_payment_transaction');
            }

            if ($this->indexExists('refund_transactions', 'idx_refund_obligation')) {
                $table->dropIndex('idx_refund_obligation');
            }
        });

        Schema::table('refund_transactions', function (Blueprint $table): void {
            foreach (['obligation_id', 'obligation_type'] as $column) {
                if (Schema::hasColumn('refund_transactions', $column)) {
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
