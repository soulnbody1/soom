<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('auction_settlements')) {
            return;
        }

        Schema::table('auction_settlements', function (Blueprint $table) {
            if (! Schema::hasColumn('auction_settlements', 'sequence_number')) {
                $table->unsignedInteger('sequence_number')->default(1)->after('winner_id');
            }

            if (! Schema::hasColumn('auction_settlements', 'is_current')) {
                $table->boolean('is_current')->default(true)->after('sequence_number');
            }

            if (! Schema::hasColumn('auction_settlements', 'current_marker')) {
                $table->unsignedTinyInteger('current_marker')->nullable()->default(1)->after('is_current');
            }

            if (! Schema::hasColumn('auction_settlements', 'previous_settlement_id')) {
                $table->foreignId('previous_settlement_id')
                    ->nullable()
                    ->after('current_marker')
                    ->constrained('auction_settlements')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('auction_settlements', 'winner_reassignment_id')) {
                $table->unsignedBigInteger('winner_reassignment_id')->nullable()->after('previous_settlement_id');
            }

            if (! Schema::hasColumn('auction_settlements', 'superseded_at')) {
                $table->timestampTz('superseded_at')->nullable()->after('winner_reassignment_id');
            }

            if (! Schema::hasColumn('auction_settlements', 'remaining_amount_minor')) {
                $table->unsignedBigInteger('remaining_amount_minor')->default(0)->after('amount_paid_minor');
            }
        });

        DB::table('auction_settlements')
            ->whereNull('sequence_number')
            ->update(['sequence_number' => 1]);

        DB::table('auction_settlements')->orderBy('auction_id')->orderBy('id')->chunkById(100, function ($settlements): void {
            $seen = [];

            foreach ($settlements as $settlement) {
                $sequence = ($seen[$settlement->auction_id] ?? 0) + 1;
                $seen[$settlement->auction_id] = $sequence;
                $isLatestForAuction = ! DB::table('auction_settlements')
                    ->where('auction_id', $settlement->auction_id)
                    ->where('id', '>', $settlement->id)
                    ->exists();

                DB::table('auction_settlements')
                    ->where('id', $settlement->id)
                    ->update([
                        'sequence_number' => $sequence,
                        'is_current' => $isLatestForAuction,
                        'current_marker' => $isLatestForAuction ? 1 : null,
                        'remaining_amount_minor' => max(0, (int) $settlement->amount_due_minor - (int) $settlement->amount_paid_minor),
                        'superseded_at' => $isLatestForAuction ? null : now(),
                    ]);
            }
        });

        $this->dropUniqueIfExists('auction_settlements', 'uq_auction_settlement_one');

        Schema::table('auction_settlements', function (Blueprint $table) {
            if (! $this->indexExists('auction_settlements', 'uq_auction_settlement_sequence')) {
                $table->unique(['auction_id', 'sequence_number'], 'uq_auction_settlement_sequence');
            }

            if (! $this->indexExists('auction_settlements', 'uq_auction_settlement_current')) {
                $table->unique(['auction_id', 'current_marker'], 'uq_auction_settlement_current');
            }

            if (! $this->indexExists('auction_settlements', 'idx_settlements_current_winner')) {
                $table->index(['auction_id', 'winner_id', 'current_marker'], 'idx_settlements_current_winner');
            }
        });

        if (Schema::hasColumn('auction_settlements', 'payment_due_at')) {
            try {
                Schema::table('auction_settlements', function (Blueprint $table) {
                    $table->timestampTz('payment_due_at')->nullable()->change();
                });
            } catch (Throwable) {
                // Some local SQLite builds cannot alter timestamp nullability; fresh schemas already have the correct shape.
            }
        }

        $this->addCheckConstraints();

        if (Schema::hasTable('refund_transactions') && ! $this->indexExists('refund_transactions', 'uq_refund_provider_refund_id')) {
            Schema::table('refund_transactions', function (Blueprint $table) {
                $table->unique(['provider', 'provider_refund_id'], 'uq_refund_provider_refund_id');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('auction_settlements')) {
            return;
        }

        Schema::table('auction_settlements', function (Blueprint $table) {
            $this->dropIndexIfExists('auction_settlements', 'idx_settlements_current_winner', $table);
            $this->dropUniqueIfExists('auction_settlements', 'uq_auction_settlement_current');
            $this->dropUniqueIfExists('auction_settlements', 'uq_auction_settlement_sequence');
        });
    }

    private function addCheckConstraints(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'pgsql'], true)) {
            return;
        }

        foreach ([
            'ALTER TABLE auction_settlements ADD CONSTRAINT chk_settlement_remaining_amount CHECK (remaining_amount_minor + amount_paid_minor = amount_due_minor)',
            'ALTER TABLE auction_settlements ADD CONSTRAINT chk_settlement_current_marker CHECK ((is_current = 1 AND current_marker = 1) OR (is_current = 0 AND current_marker IS NULL))',
        ] as $statement) {
            try {
                DB::statement($statement);
            } catch (Throwable) {
                // Constraint already exists or the database version has equivalent checks.
            }
        }
    }

    private function dropUniqueIfExists(string $table, string $index): void
    {
        if (! $this->indexExists($table, $index)) {
            return;
        }

        try {
            Schema::table($table, function (Blueprint $blueprint) use ($index): void {
                $blueprint->dropUnique($index);
            });
        } catch (Throwable) {
            try {
                DB::statement("ALTER TABLE {$table} DROP INDEX {$index}");
            } catch (Throwable) {
                // Keep migration forward-only when local drivers cannot drop a missing equivalent index.
            }
        }
    }

    private function dropIndexIfExists(string $table, string $index, Blueprint $blueprint): void
    {
        if (! $this->indexExists($table, $index)) {
            return;
        }

        try {
            $blueprint->dropIndex($index);
        } catch (Throwable) {
            try {
                DB::statement("ALTER TABLE {$table} DROP INDEX {$index}");
            } catch (Throwable) {
                // No-op for unsupported local drivers.
            }
        }
    }

    private function indexExists(string $table, string $index): bool
    {
        $connection = Schema::getConnection();

        if ($connection->getDriverName() === 'sqlite') {
            return collect($connection->select("PRAGMA index_list('{$table}')"))
                ->contains(fn ($row): bool => ($row->name ?? null) === $index);
        }

        $database = $connection->getDatabaseName();
        $prefixedTable = $connection->getTablePrefix().$table;

        return $connection->selectOne(
            'SELECT 1 FROM information_schema.statistics WHERE table_schema = ? AND table_name = ? AND index_name = ? LIMIT 1',
            [$database, $prefixedTable, $index]
        ) !== null;
    }
};
