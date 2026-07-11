<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('auctions') && Schema::hasColumn('auctions', 'public_id')) {
            return;
        }

        $this->dropAuctionTables();
    }

    public function down(): void
    {
        $this->dropAuctionTables();
    }

    private function dropAuctionTables(): void
    {
        Schema::disableForeignKeyConstraints();

        foreach ([
            'outbox_messages',
            'auction_views',
            'auction_metrics',
            'auction_activity_logs',
            'auction_status_history',
            'refund_transactions',
            'payment_transactions',
            'payment_submissions',
            'auction_settlements',
            'auction_bids',
            'auction_deposits',
            'auction_terms_acceptances',
            'auction_participants',
            'auction_media',
            'auction_images',
            'auctions',
            'auction_terms_versions',
            'payment_slips',
            'auctions_configurations',
            'auction_rules',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        if (Schema::hasTable('payment_methods') && ! Schema::hasColumn('payment_methods', 'public_id')) {
            Schema::dropIfExists('payment_methods');
        }

        Schema::enableForeignKeyConstraints();
    }
};
