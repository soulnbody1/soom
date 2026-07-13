<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('auction_settlements')) {
            Schema::table('auction_settlements', function (Blueprint $table): void {
                if (! Schema::hasColumn('auction_settlements', 'defaulted_at')) {
                    $table->timestampTz('defaulted_at')->nullable()->after('completed_at');
                }
                if (! Schema::hasColumn('auction_settlements', 'default_reason')) {
                    $table->text('default_reason')->nullable()->after('defaulted_at');
                }
                if (! Schema::hasColumn('auction_settlements', 'overridden_by')) {
                    $table->foreignId('overridden_by')->nullable()->after('default_reason')->constrained('users')->nullOnDelete();
                }
                if (! Schema::hasColumn('auction_settlements', 'overridden_at')) {
                    $table->timestampTz('overridden_at')->nullable()->after('overridden_by');
                }
                if (! Schema::hasColumn('auction_settlements', 'override_reason')) {
                    $table->text('override_reason')->nullable()->after('overridden_at');
                }
                if (! Schema::hasColumn('auction_settlements', 'original_payment_due_at')) {
                    $table->timestampTz('original_payment_due_at')->nullable()->after('override_reason');
                }
            });
        }

        if (Schema::hasTable('auction_winner_reassignments')) {
            Schema::table('auction_winner_reassignments', function (Blueprint $table): void {
                if (! Schema::hasColumn('auction_winner_reassignments', 'previous_settlement_id')) {
                    $table->foreignId('previous_settlement_id')
                        ->nullable()
                        ->after('to_user_id')
                        ->constrained('auction_settlements')
                        ->nullOnDelete();
                }
                if (! Schema::hasColumn('auction_winner_reassignments', 'new_settlement_id')) {
                    $table->foreignId('new_settlement_id')
                        ->nullable()
                        ->after('previous_settlement_id')
                        ->constrained('auction_settlements')
                        ->nullOnDelete();
                }
            });

            Schema::table('auction_winner_reassignments', function (Blueprint $table): void {
                $table->unique(
                    ['auction_id', 'from_user_id', 'previous_settlement_id'],
                    'uq_winner_reassignment_default'
                );
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('auction_winner_reassignments')) {
            Schema::table('auction_winner_reassignments', function (Blueprint $table): void {
                $table->dropUnique('uq_winner_reassignment_default');
                if (Schema::hasColumn('auction_winner_reassignments', 'new_settlement_id')) {
                    $table->dropConstrainedForeignId('new_settlement_id');
                }
                if (Schema::hasColumn('auction_winner_reassignments', 'previous_settlement_id')) {
                    $table->dropConstrainedForeignId('previous_settlement_id');
                }
            });
        }

        if (Schema::hasTable('auction_settlements')) {
            Schema::table('auction_settlements', function (Blueprint $table): void {
                foreach ([
                    'original_payment_due_at',
                    'override_reason',
                    'overridden_at',
                    'overridden_by',
                    'default_reason',
                    'defaulted_at',
                ] as $column) {
                    if (Schema::hasColumn('auction_settlements', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
