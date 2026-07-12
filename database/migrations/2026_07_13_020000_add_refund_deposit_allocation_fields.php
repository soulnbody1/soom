<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('refund_transactions')) {
            return;
        }

        Schema::table('refund_transactions', function (Blueprint $table): void {
            if (! $this->indexExists('refund_transactions', 'idx_refund_source_payment_transaction')) {
                $table->index('payment_transaction_id', 'idx_refund_source_payment_transaction');
            }

            if ($this->indexExists('refund_transactions', 'uq_refund_source_payment_transaction')) {
                $table->dropUnique('uq_refund_source_payment_transaction');
            }
        });

        Schema::table('refund_transactions', function (Blueprint $table): void {
            if (! Schema::hasColumn('refund_transactions', 'held_refund_amount_minor')) {
                $table->unsignedBigInteger('held_refund_amount_minor')->default(0)->after('amount_minor');
            }

            if (! Schema::hasColumn('refund_transactions', 'applied_refund_amount_minor')) {
                $table->unsignedBigInteger('applied_refund_amount_minor')->default(0)->after('held_refund_amount_minor');
            }
        });

        DB::table('refund_transactions')
            ->whereNotNull('deposit_id')
            ->where('held_refund_amount_minor', 0)
            ->where('applied_refund_amount_minor', 0)
            ->update([
                'held_refund_amount_minor' => DB::raw('amount_minor'),
            ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('refund_transactions')) {
            return;
        }

        Schema::table('refund_transactions', function (Blueprint $table): void {
            if ($this->indexExists('refund_transactions', 'idx_refund_source_payment_transaction')) {
                $table->dropIndex('idx_refund_source_payment_transaction');
            }

            foreach (['applied_refund_amount_minor', 'held_refund_amount_minor'] as $column) {
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
