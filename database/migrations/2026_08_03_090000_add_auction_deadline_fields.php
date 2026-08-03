<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('auctions', function (Blueprint $table): void {
            if (! Schema::hasColumn('auctions', 'seller_deposit_due_at')) {
                $table->timestampTz('seller_deposit_due_at')->nullable()->after('published_at');
            }
        });

        Schema::table('auction_settlements', function (Blueprint $table): void {
            if (! Schema::hasColumn('auction_settlements', 'payment_grace_ends_at')) {
                $table->timestampTz('payment_grace_ends_at')->nullable()->after('payment_due_at');
            }
            if (! Schema::hasColumn('auction_settlements', 'payment_reminders_sent')) {
                $table->json('payment_reminders_sent')->nullable()->after('payment_grace_ends_at');
            }
            if (! Schema::hasColumn('auction_settlements', 'handover_reminders_sent')) {
                $table->json('handover_reminders_sent')->nullable()->after('handover_due_at');
            }
        });

        Schema::table('auction_configuration_snapshots', function (Blueprint $table): void {
            if (! Schema::hasColumn('auction_configuration_snapshots', 'winner_payment_grace_period_minutes')) {
                $table->unsignedInteger('winner_payment_grace_period_minutes')
                    ->default(1440)
                    ->after('winner_payment_deadline_minutes');
            }
            if (! Schema::hasColumn('auction_configuration_snapshots', 'seller_deposit_deadline_minutes')) {
                $table->unsignedInteger('seller_deposit_deadline_minutes')
                    ->default(2880)
                    ->after('seller_deposit_required_minor');
            }
            if (! Schema::hasColumn('auction_configuration_snapshots', 'review_sla_minutes')) {
                $table->unsignedInteger('review_sla_minutes')
                    ->default(1440)
                    ->after('handover_deadline_minutes');
            }
        });
    }

    public function down(): void
    {
        Schema::table('auctions', function (Blueprint $table): void {
            if (Schema::hasColumn('auctions', 'seller_deposit_due_at')) {
                $table->dropColumn('seller_deposit_due_at');
            }
        });

        Schema::table('auction_settlements', function (Blueprint $table): void {
            foreach (['payment_grace_ends_at', 'payment_reminders_sent', 'handover_reminders_sent'] as $column) {
                if (Schema::hasColumn('auction_settlements', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('auction_configuration_snapshots', function (Blueprint $table): void {
            foreach ([
                'winner_payment_grace_period_minutes',
                'seller_deposit_deadline_minutes',
                'review_sla_minutes',
            ] as $column) {
                if (Schema::hasColumn('auction_configuration_snapshots', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
