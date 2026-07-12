<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if ($this->hasCurrentAuctionSchema()) {
            return;
        }

        $this->dropAuctionTables();
        $this->createAuctionSchema();
    }

    public function down(): void
    {
        $this->dropAuctionTables();
    }

    private function dropAuctionTables(): void
    {
        Schema::disableForeignKeyConstraints();

        try {
            foreach ([
                'outbox_messages',
                'auction_views',
                'auction_metrics',
                'auction_activity_logs',
                'auction_status_history',
                'refund_transactions',
                'payment_transactions',
                'payment_submissions',
                'auction_winner_reassignments',
                'auction_disputes',
                'auction_settlements',
                'auction_bids',
                'auction_deposits',
                'auction_terms_acceptances',
                'auction_participants',
                'auction_media',
                'auction_images',
                'auctions',
                'auction_configuration_versions',
                'auction_terms_versions',
                'payment_methods',
                'payment_slips',
                'auctions_configurations',
                'auction_rules',
            ] as $table) {
                Schema::dropIfExists($table);
            }
        } finally {
            Schema::enableForeignKeyConstraints();
        }
    }

    private function hasCurrentAuctionSchema(): bool
    {
        return Schema::hasTable('auctions')
            && Schema::hasColumn('auctions', 'public_id')
            && Schema::hasTable('auction_settlements')
            && Schema::hasColumn('auction_settlements', 'remaining_amount_minor')
            && Schema::hasColumn('auction_settlements', 'current_marker');
    }

    private function createAuctionSchema(): void
    {
        $createAuctionSchema = require __DIR__.'/2025_06_12_090000_create_auctions_table.php';
        $createAuctionSchema->up();
    }
};
