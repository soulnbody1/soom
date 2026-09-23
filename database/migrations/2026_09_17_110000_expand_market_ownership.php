<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var array<int, string> */
    private array $tables = [
        'banners', 'announcements', 'charity_systems', 'support_contacts', 'support_tickets',
        'ads', 'ad_images', 'ad_reels', 'ad_reel_views', 'ad_views', 'user_ad_interactions',
        'auctions', 'auction_terms_versions', 'auction_configuration_versions',
        'auction_configuration_snapshots', 'auction_participants', 'auction_terms_acceptances',
        'auction_deposits', 'payment_submissions', 'auction_bids', 'auction_settlements',
        'auction_disputes', 'auction_winner_reassignments', 'auction_seller_payouts',
        'auction_media', 'auction_status_history', 'auction_activity_logs', 'auction_metrics',
        'auction_views',
        'refund_transactions', 'payment_transactions', 'payment_provider_events',
        'payment_methods', 'outbox_messages', 'content_reviews', 'content_review_policies',
        'content_review_settings',
    ];

    public function up(): void
    {
        foreach ($this->tables as $tableName) {
            if (! Schema::hasTable($tableName) || Schema::hasColumn($tableName, 'market_id')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                $table->unsignedBigInteger('market_id')->nullable()->after('id');
                $table->index('market_id', 'idx_'.$this->short($tableName).'_market');
            });
        }

        if (! Schema::hasColumn('ads', 'currency_code')) {
            Schema::table('ads', fn (Blueprint $table) => $table->char('currency_code', 3)->nullable()->after('price'));
        }

        if (! Schema::hasTable('market_category')) {
            Schema::create('market_category', function (Blueprint $table): void {
                $table->foreignId('market_id')->constrained('markets')->cascadeOnDelete();
                $table->foreignId('category_id')->constrained('categories')->cascadeOnDelete();
                $table->boolean('is_visible')->default(true);
                $table->unsignedInteger('display_order')->default(0);
                $table->timestamps();
                $table->primary(['market_id', 'category_id']);
                $table->index(['market_id', 'is_visible', 'display_order'], 'idx_market_category_visible');
            });
        }

        if (! Schema::hasTable('device_token_market')) {
            Schema::create('device_token_market', function (Blueprint $table): void {
                $table->foreignId('device_token_id')->constrained('device_tokens')->cascadeOnDelete();
                $table->foreignId('market_id')->constrained('markets')->cascadeOnDelete();
                $table->timestamps();
                $table->primary(['device_token_id', 'market_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('device_token_market');
        Schema::dropIfExists('market_category');

        foreach (array_reverse($this->tables) as $tableName) {
            if (Schema::hasTable($tableName) && Schema::hasColumn($tableName, 'market_id')) {
                Schema::table($tableName, fn (Blueprint $table) => $table->dropColumn('market_id'));
            }
        }
    }

    private function short(string $table): string
    {
        return substr(str_replace(['auction_', 'payment_', 'content_review_'], ['a_', 'p_', 'cr_'], $table), 0, 38);
    }
};
