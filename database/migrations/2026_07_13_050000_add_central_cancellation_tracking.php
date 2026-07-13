<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('auctions')) {
            Schema::table('auctions', function (Blueprint $table): void {
                if (! Schema::hasColumn('auctions', 'cancellation_operation_key')) {
                    $table->string('cancellation_operation_key')->nullable()->after('cancelled_at');
                    $table->unique('cancellation_operation_key', 'uq_auction_cancellation_operation');
                }
                if (! Schema::hasColumn('auctions', 'cancellation_trigger')) {
                    $table->string('cancellation_trigger', 80)->nullable()->after('cancellation_operation_key');
                }
                if (! Schema::hasColumn('auctions', 'cancellation_reason_code')) {
                    $table->string('cancellation_reason_code', 120)->nullable()->after('cancellation_trigger');
                }
                if (! Schema::hasColumn('auctions', 'cancellation_reason_text')) {
                    $table->text('cancellation_reason_text')->nullable()->after('cancellation_reason_code');
                }
                if (! Schema::hasColumn('auctions', 'cancellation_liability')) {
                    $table->string('cancellation_liability', 80)->nullable()->after('cancellation_reason_text');
                }
                if (! Schema::hasColumn('auctions', 'financial_cancellation_completed_at')) {
                    $table->timestampTz('financial_cancellation_completed_at')->nullable()->after('cancellation_liability');
                }
                if (! Schema::hasColumn('auctions', 'financial_cancellation_manual_review_required')) {
                    $table->boolean('financial_cancellation_manual_review_required')->default(false)->after('financial_cancellation_completed_at');
                }
            });
        }

        if (Schema::hasTable('auction_settlements')) {
            Schema::table('auction_settlements', function (Blueprint $table): void {
                if (! Schema::hasColumn('auction_settlements', 'cancelled_at')) {
                    $table->timestampTz('cancelled_at')->nullable()->after('completed_at');
                }
                if (! Schema::hasColumn('auction_settlements', 'cancelled_by')) {
                    $table->foreignId('cancelled_by')->nullable()->after('cancelled_at')->constrained('users')->nullOnDelete();
                }
                if (! Schema::hasColumn('auction_settlements', 'cancel_reason')) {
                    $table->text('cancel_reason')->nullable()->after('cancelled_by');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('auction_settlements')) {
            Schema::table('auction_settlements', function (Blueprint $table): void {
                if (Schema::hasColumn('auction_settlements', 'cancelled_by')) {
                    $table->dropConstrainedForeignId('cancelled_by');
                }
                foreach (['cancel_reason', 'cancelled_at'] as $column) {
                    if (Schema::hasColumn('auction_settlements', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('auctions')) {
            Schema::table('auctions', function (Blueprint $table): void {
                if (Schema::hasColumn('auctions', 'cancellation_operation_key')) {
                    $table->dropUnique('uq_auction_cancellation_operation');
                }
                foreach ([
                    'financial_cancellation_manual_review_required',
                    'financial_cancellation_completed_at',
                    'cancellation_liability',
                    'cancellation_reason_text',
                    'cancellation_reason_code',
                    'cancellation_trigger',
                    'cancellation_operation_key',
                ] as $column) {
                    if (Schema::hasColumn('auctions', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
