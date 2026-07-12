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
            if (! Schema::hasColumn('refund_transactions', 'attempt_count')) {
                $table->unsignedInteger('attempt_count')->default(0)->after('idempotency_key');
            }

            if (! Schema::hasColumn('refund_transactions', 'last_error')) {
                $table->text('last_error')->nullable()->after('failure_reason');
            }

            if (! Schema::hasColumn('refund_transactions', 'next_retry_at')) {
                $table->timestampTz('next_retry_at')->nullable()->after('last_error');
            }

            if (! Schema::hasColumn('refund_transactions', 'processing_started_at')) {
                $table->timestampTz('processing_started_at')->nullable()->after('next_retry_at');
            }

            if (! Schema::hasColumn('refund_transactions', 'processing_token')) {
                $table->string('processing_token', 80)->nullable()->after('processing_started_at');
            }

            if (! Schema::hasColumn('refund_transactions', 'lease_expires_at')) {
                $table->timestampTz('lease_expires_at')->nullable()->after('processing_token');
            }

            if (! Schema::hasColumn('refund_transactions', 'provider_response')) {
                $table->json('provider_response')->nullable()->after('provider_refund_id');
            }

            if (! Schema::hasColumn('refund_transactions', 'manual_confirmed_by')) {
                $table->foreignId('manual_confirmed_by')->nullable()->after('provider_response')->constrained('users')->nullOnDelete();
            }

            if (! Schema::hasColumn('refund_transactions', 'manual_confirmed_at')) {
                $table->timestampTz('manual_confirmed_at')->nullable()->after('manual_confirmed_by');
            }

            if (! Schema::hasColumn('refund_transactions', 'manual_confirmation_reason')) {
                $table->text('manual_confirmation_reason')->nullable()->after('manual_confirmed_at');
            }

            if (! Schema::hasColumn('refund_transactions', 'succeeded_at')) {
                $table->timestampTz('succeeded_at')->nullable()->after('processed_at');
            }

            if (! Schema::hasColumn('refund_transactions', 'failed_at')) {
                $table->timestampTz('failed_at')->nullable()->after('succeeded_at');
            }

            if (! Schema::hasColumn('refund_transactions', 'cancelled_at')) {
                $table->timestampTz('cancelled_at')->nullable()->after('failed_at');
            }

            if (! Schema::hasColumn('refund_transactions', 'cancelled_by')) {
                $table->foreignId('cancelled_by')->nullable()->after('cancelled_at')->constrained('users')->nullOnDelete();
            }

            if (! Schema::hasColumn('refund_transactions', 'cancellation_reason')) {
                $table->text('cancellation_reason')->nullable()->after('cancelled_by');
            }
        });

        Schema::table('refund_transactions', function (Blueprint $table): void {
            if (! $this->indexExists('refund_transactions', 'idx_refunds_lifecycle_due')) {
                $table->index(['status', 'next_retry_at', 'lease_expires_at'], 'idx_refunds_lifecycle_due');
            }

            if (! $this->indexExists('refund_transactions', 'idx_refunds_processing_token')) {
                $table->index('processing_token', 'idx_refunds_processing_token');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('refund_transactions')) {
            return;
        }

        Schema::table('refund_transactions', function (Blueprint $table): void {
            if ($this->indexExists('refund_transactions', 'idx_refunds_processing_token')) {
                $table->dropIndex('idx_refunds_processing_token');
            }

            if ($this->indexExists('refund_transactions', 'idx_refunds_lifecycle_due')) {
                $table->dropIndex('idx_refunds_lifecycle_due');
            }
        });

        Schema::table('refund_transactions', function (Blueprint $table): void {
            foreach ([
                'cancellation_reason',
                'cancelled_by',
                'cancelled_at',
                'failed_at',
                'succeeded_at',
                'manual_confirmation_reason',
                'manual_confirmed_at',
                'manual_confirmed_by',
                'provider_response',
                'lease_expires_at',
                'processing_token',
                'processing_started_at',
                'next_retry_at',
                'last_error',
                'attempt_count',
            ] as $column) {
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
