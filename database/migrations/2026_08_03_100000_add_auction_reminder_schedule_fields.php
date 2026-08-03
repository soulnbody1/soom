<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('auction_configuration_snapshots', function (Blueprint $table): void {
            if (! Schema::hasColumn('auction_configuration_snapshots', 'winner_payment_reminder_hours')) {
                $table->json('winner_payment_reminder_hours')->nullable()->after('winner_payment_grace_period_minutes');
            }
            if (! Schema::hasColumn('auction_configuration_snapshots', 'handover_reminder_hours')) {
                $table->json('handover_reminder_hours')->nullable()->after('handover_deadline_minutes');
            }
        });

        Schema::table('auctions', function (Blueprint $table): void {
            if (! Schema::hasColumn('auctions', 'seller_deposit_deadline_processed_at')) {
                $table->timestampTz('seller_deposit_deadline_processed_at')->nullable()->after('seller_deposit_due_at');
            }
        });

        Schema::table('auction_settlements', function (Blueprint $table): void {
            if (! Schema::hasColumn('auction_settlements', 'auto_defaulted')) {
                $table->boolean('auto_defaulted')->default(false)->after('default_reason');
            }
        });
    }

    public function down(): void
    {
        Schema::table('auction_configuration_snapshots', function (Blueprint $table): void {
            foreach (['winner_payment_reminder_hours', 'handover_reminder_hours'] as $column) {
                if (Schema::hasColumn('auction_configuration_snapshots', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('auctions', function (Blueprint $table): void {
            if (Schema::hasColumn('auctions', 'seller_deposit_deadline_processed_at')) {
                $table->dropColumn('seller_deposit_deadline_processed_at');
            }
        });

        Schema::table('auction_settlements', function (Blueprint $table): void {
            if (Schema::hasColumn('auction_settlements', 'auto_defaulted')) {
                $table->dropColumn('auto_defaulted');
            }
        });
    }
};
