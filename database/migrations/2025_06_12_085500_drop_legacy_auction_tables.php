<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('auctions') || Schema::hasColumn('auctions', 'public_id')) {
            return;
        }

        Schema::disableForeignKeyConstraints();

        foreach ($this->legacyAuctionTables() as $table) {
            Schema::dropIfExists($table);
        }

        Schema::enableForeignKeyConstraints();
    }

    public function down(): void
    {
        //
    }

    /**
     * @return list<string>
     */
    private function legacyAuctionTables(): array
    {
        return [
            'outbox_messages',
            'auction_winner_reassignments',
            'auction_disputes',
            'auction_configuration_versions',
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
            'payment_methods',
        ];
    }
};
