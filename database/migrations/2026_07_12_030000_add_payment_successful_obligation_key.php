<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payment_transactions')) {
            return;
        }

        Schema::table('payment_transactions', function (Blueprint $table) {
            if (! Schema::hasColumn('payment_transactions', 'successful_obligation_key')) {
                $table->string('successful_obligation_key')->nullable()->after('idempotency_key');
            }
        });

        $this->backfillSuccessfulObligationKeys();
        $this->assertNoDuplicatePaymentSubmissionTransactions();
        $this->assertNoDuplicateSuccessfulObligations();

        Schema::table('payment_transactions', function (Blueprint $table) {
            if (! $this->indexExists('payment_transactions', 'uq_payment_transaction_submission')) {
                $table->unique('payment_submission_id', 'uq_payment_transaction_submission');
            }

            if (! $this->indexExists('payment_transactions', 'uq_payment_successful_obligation')) {
                $table->unique('successful_obligation_key', 'uq_payment_successful_obligation');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('payment_transactions')) {
            return;
        }

        Schema::table('payment_transactions', function (Blueprint $table) {
            if ($this->indexExists('payment_transactions', 'uq_payment_successful_obligation')) {
                $table->dropUnique('uq_payment_successful_obligation');
            }

            if ($this->indexExists('payment_transactions', 'uq_payment_transaction_submission')) {
                $table->dropUnique('uq_payment_transaction_submission');
            }

            if (Schema::hasColumn('payment_transactions', 'successful_obligation_key')) {
                $table->dropColumn('successful_obligation_key');
            }
        });
    }

    private function backfillSuccessfulObligationKeys(): void
    {
        DB::table('payment_transactions')
            ->join('payment_submissions', 'payment_submissions.id', '=', 'payment_transactions.payment_submission_id')
            ->where('payment_transactions.status', 'succeeded')
            ->whereNull('payment_transactions.successful_obligation_key')
            ->orderBy('payment_transactions.id')
            ->select([
                'payment_transactions.id',
                'payment_submissions.deposit_id',
                'payment_submissions.settlement_id',
            ])
            ->chunk(100, function ($transactions): void {
                foreach ($transactions as $transaction) {
                    $key = null;

                    if ($transaction->deposit_id !== null) {
                        $key = 'deposit:'.$transaction->deposit_id;
                    } elseif ($transaction->settlement_id !== null) {
                        $key = 'settlement:'.$transaction->settlement_id;
                    }

                    if ($key !== null) {
                        DB::table('payment_transactions')
                            ->where('id', $transaction->id)
                            ->update(['successful_obligation_key' => $key]);
                    }
                }
            });
    }

    private function assertNoDuplicatePaymentSubmissionTransactions(): void
    {
        $duplicate = DB::table('payment_transactions')
            ->select('payment_submission_id')
            ->groupBy('payment_submission_id')
            ->havingRaw('COUNT(*) > 1')
            ->first();

        if ($duplicate) {
            throw new LogicException('Duplicate payment transactions exist for payment_submission_id '.$duplicate->payment_submission_id.'. Resolve duplicates before adding uq_payment_transaction_submission.');
        }
    }

    private function assertNoDuplicateSuccessfulObligations(): void
    {
        $duplicate = DB::table('payment_transactions')
            ->select('successful_obligation_key')
            ->whereNotNull('successful_obligation_key')
            ->groupBy('successful_obligation_key')
            ->havingRaw('COUNT(*) > 1')
            ->first();

        if ($duplicate) {
            throw new LogicException('Duplicate successful payments exist for '.$duplicate->successful_obligation_key.'. Resolve duplicates before adding uq_payment_successful_obligation.');
        }
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
