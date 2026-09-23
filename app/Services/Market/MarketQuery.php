<?php

declare(strict_types=1);

namespace App\Services\Market;

use App\Support\Market\MarketContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final readonly class MarketQuery
{
    private const TABLES = [
        'ads', 'ad_images', 'ad_reels', 'ad_reel_views', 'ad_views', 'user_ad_interactions',
        'banners', 'announcements', 'charity_systems', 'support_contacts', 'support_tickets',
        'auctions', 'auction_terms_versions', 'auction_configuration_versions',
        'auction_configuration_snapshots', 'auction_participants', 'auction_terms_acceptances',
        'auction_deposits', 'payment_submissions', 'auction_bids', 'auction_settlements',
        'auction_disputes', 'auction_winner_reassignments', 'auction_seller_payouts',
        'auction_media', 'auction_status_history', 'auction_activity_logs', 'auction_metrics',
        'auction_views', 'refund_transactions', 'payment_transactions', 'payment_provider_events',
        'payment_methods', 'outbox_messages', 'content_reviews', 'content_review_policies',
        'content_review_settings',
    ];

    public function __construct(private MarketContext $context) {}

    public function table(string $table): Builder
    {
        [$base, $qualifier] = $this->names($table);
        if (! in_array($base, self::TABLES, true)) {
            throw new InvalidArgumentException("Table [{$base}] is not registered as market-scoped.");
        }

        $query = DB::table($table);
        $state = $this->context->state();

        return $state->mode->requiresMarket()
            ? $query->where($qualifier.'.market_id', $state->market->getKey())
            : $query;
    }

    /** @return array{string, string} */
    private function names(string $table): array
    {
        $parts = preg_split('/\s+as\s+|\s+/', trim($table), 2, PREG_SPLIT_NO_EMPTY);
        $base = $parts[0] ?? '';
        $qualifier = $parts[1] ?? $base;

        return [$base, $qualifier];
    }
}
