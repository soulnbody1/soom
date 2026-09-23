<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const OPTIONAL_MARKET_TABLES = ['payment_provider_events'];

    /** @var array<int, string> */
    private array $tables = [
        'banners', 'announcements', 'charity_systems', 'support_contacts', 'support_tickets',
        'ads', 'ad_images', 'ad_reels', 'ad_reel_views', 'ad_views', 'user_ad_interactions',
        'auctions', 'auction_terms_versions', 'auction_configuration_versions',
        'auction_configuration_snapshots', 'auction_participants', 'auction_terms_acceptances',
        'auction_deposits', 'payment_submissions', 'auction_bids', 'auction_settlements',
        'auction_disputes', 'auction_winner_reassignments', 'auction_seller_payouts',
        'auction_media', 'auction_status_history', 'auction_activity_logs', 'auction_metrics',
        'auction_views', 'refund_transactions', 'payment_transactions',
        'payment_provider_events', 'payment_methods', 'outbox_messages', 'content_reviews',
        'content_review_policies', 'content_review_settings',
    ];

    public function up(): void
    {
        foreach ($this->tables as $tableName) {
            if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'market_id')) {
                continue;
            }

            $optional = in_array($tableName, self::OPTIONAL_MARKET_TABLES, true);

            if (! $optional && DB::table($tableName)->whereNull('market_id')->exists()) {
                throw new RuntimeException("Cannot contract {$tableName}: NULL market_id values remain.");
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName, $optional): void {
                $table->unsignedBigInteger('market_id')->nullable($optional)->change();
                $table->foreign('market_id', 'fk_'.$this->short($tableName).'_market')
                    ->references('id')->on('markets')->restrictOnDelete();
            });
        }

        Schema::table('ads', fn (Blueprint $table) => $table->char('currency_code', 3)->nullable(false)->change());

        Schema::table('payment_methods', function (Blueprint $table): void {
            $table->dropUnique('payment_methods_code_unique');
            $table->unique(['id', 'market_id'], 'uq_payment_methods_id_market');
            $table->unique(['market_id', 'code'], 'uq_payment_methods_market_code');
            $table->dropColumn(['country_codes', 'currency_codes']);
        });

        Schema::table('ads', fn (Blueprint $table) => $table->unique(['id', 'market_id'], 'uq_ads_id_market'));
        Schema::table('ad_reels', fn (Blueprint $table) => $table->unique(['id', 'market_id'], 'uq_ad_reels_id_market'));
        Schema::table('auctions', fn (Blueprint $table) => $table->unique(['id', 'market_id'], 'uq_auctions_id_market'));
        Schema::table('auction_terms_versions', function (Blueprint $table): void {
            $table->dropUnique('auction_terms_versions_version_number_unique');
            $table->unique(['market_id', 'version_number'], 'uq_terms_market_version');
        });
        Schema::table('auction_configuration_versions', function (Blueprint $table): void {
            $table->dropUnique('auction_configuration_versions_version_number_unique');
            $table->unique(['market_id', 'version_number'], 'uq_config_market_version');
        });

        Schema::table('ads', fn (Blueprint $table) => $table->index(['market_id', 'category_id', 'created_at'], 'idx_ads_market_category_created'));
        Schema::table('auctions', function (Blueprint $table): void {
            $table->index(['market_id', 'status', 'starts_at'], 'idx_auctions_market_status_starts');
            $table->index(['market_id', 'status', 'ends_at'], 'idx_auctions_market_status_ends');
        });
        Schema::table('payment_submissions', fn (Blueprint $table) => $table->index(['market_id', 'status', 'created_at'], 'idx_submissions_market_status_created'));
        Schema::table('payment_transactions', fn (Blueprint $table) => $table->index(['market_id', 'status', 'expires_at'], 'idx_transactions_market_status_due'));
        Schema::table('refund_transactions', fn (Blueprint $table) => $table->index(['market_id', 'status', 'next_retry_at'], 'idx_refunds_market_status_due'));
        Schema::table('auction_seller_payouts', fn (Blueprint $table) => $table->index(['market_id', 'status', 'created_at'], 'idx_payouts_market_status_created'));
        Schema::table('outbox_messages', fn (Blueprint $table) => $table->index(['market_id', 'status', 'available_at'], 'idx_outbox_market_status_due'));
        Schema::table('content_reviews', fn (Blueprint $table) => $table->index(['market_id', 'status', 'queued_at'], 'idx_reviews_market_status_queued'));

        if (DB::getDriverName() === 'mysql') {
            $this->addMySqlCompositeConstraints();
        }
    }

    public function down(): void {}

    private function addMySqlCompositeConstraints(): void
    {
        DB::statement('ALTER TABLE ads DROP FOREIGN KEY ads_country_id_foreign, ADD INDEX idx_ads_market_country (market_id, country_id), ADD INDEX idx_ads_market_currency (market_id, currency_code), ADD CONSTRAINT fk_ads_market_country FOREIGN KEY (market_id, country_id) REFERENCES markets (id, country_id), ADD CONSTRAINT fk_ads_market_currency FOREIGN KEY (market_id, currency_code) REFERENCES markets (id, currency_code)');
        DB::statement('ALTER TABLE auctions DROP FOREIGN KEY auctions_country_id_foreign, ADD INDEX idx_auctions_market_country (market_id, country_id), ADD INDEX idx_auctions_market_currency (market_id, currency_code), ADD CONSTRAINT fk_auctions_market_country FOREIGN KEY (market_id, country_id) REFERENCES markets (id, country_id), ADD CONSTRAINT fk_auctions_market_currency FOREIGN KEY (market_id, currency_code) REFERENCES markets (id, currency_code)');

        foreach (['ad_images', 'ad_reels', 'ad_views', 'user_ad_interactions'] as $table) {
            $short = $this->short($table);
            DB::statement("ALTER TABLE {$table} DROP FOREIGN KEY {$table}_ad_id_foreign, ADD INDEX idx_{$short}_ad_market (ad_id, market_id), ADD CONSTRAINT fk_{$short}_ad_market FOREIGN KEY (ad_id, market_id) REFERENCES ads (id, market_id)");
        }

        DB::statement('ALTER TABLE ad_reel_views DROP FOREIGN KEY ad_reel_views_ad_reel_id_foreign, ADD INDEX idx_ad_reel_views_reel_market (ad_reel_id, market_id), ADD CONSTRAINT fk_ad_reel_views_reel_market FOREIGN KEY (ad_reel_id, market_id) REFERENCES ad_reels (id, market_id)');

        $auctionChildren = [
            'auction_media', 'auction_participants', 'auction_terms_acceptances',
            'auction_deposits', 'payment_submissions', 'auction_bids', 'auction_settlements',
            'auction_disputes', 'auction_winner_reassignments', 'auction_seller_payouts',
            'refund_transactions', 'payment_transactions', 'auction_configuration_snapshots',
            'auction_status_history', 'auction_activity_logs', 'auction_metrics', 'auction_views',
        ];

        foreach ($auctionChildren as $table) {
            $oldForeign = $table === 'auction_configuration_snapshots'
                ? 'fk_snapshot_auction'
                : $table.'_auction_id_foreign';
            $short = $this->short($table);
            DB::statement("ALTER TABLE {$table} DROP FOREIGN KEY {$oldForeign}, ADD INDEX idx_{$short}_auction_market (auction_id, market_id), ADD CONSTRAINT fk_{$short}_auction_market FOREIGN KEY (auction_id, market_id) REFERENCES auctions (id, market_id)");
        }

        foreach (['payment_submissions', 'payment_transactions'] as $table) {
            $short = $this->short($table);
            DB::statement("ALTER TABLE {$table} DROP FOREIGN KEY {$table}_payment_method_id_foreign, ADD INDEX idx_{$short}_method_market (payment_method_id, market_id), ADD CONSTRAINT fk_{$short}_method_market FOREIGN KEY (payment_method_id, market_id) REFERENCES payment_methods (id, market_id)");
        }

        foreach (['auction_deposits', 'payment_submissions', 'auction_bids', 'auction_settlements', 'refund_transactions', 'payment_transactions', 'auction_configuration_snapshots'] as $table) {
            $short = $this->short($table);
            DB::statement("ALTER TABLE {$table} ADD INDEX idx_{$short}_market_currency (market_id, currency_code), ADD CONSTRAINT fk_{$short}_market_currency FOREIGN KEY (market_id, currency_code) REFERENCES markets (id, currency_code)");
        }
    }

    private function short(string $table): string
    {
        return substr(str_replace(['auction_', 'payment_', 'content_review_'], ['a_', 'p_', 'cr_'], $table), 0, 38);
    }
};
