<?php

namespace App\Services\Market;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

final class MarketDataMigrator
{
    private const OPTIONAL_MARKET_TABLES = ['payment_provider_events'];

    /** @var array<int, string> */
    private array $auctionChildren = [
        'auction_media', 'auction_participants', 'auction_terms_acceptances', 'auction_deposits',
        'payment_submissions', 'auction_bids', 'auction_settlements', 'auction_disputes',
        'auction_winner_reassignments', 'auction_seller_payouts', 'refund_transactions',
        'payment_transactions', 'auction_configuration_snapshots', 'auction_status_history',
        'auction_activity_logs', 'auction_metrics', 'auction_views',
    ];

    /** @return array<string, int> */
    public function backfill(): array
    {
        $counts = [];
        $markets = DB::table('markets')->get()->keyBy('country_id');
        $jo = DB::table('markets')->where('code', 'JO')->first();
        if ($jo === null) {
            throw new RuntimeException('JO market is required for legacy content backfill.');
        }

        foreach (['ads', 'auctions'] as $table) {
            $counts[$table] = 0;
            DB::table($table)->whereNull('market_id')->orderBy('id')->chunkById(500, function ($rows) use ($table, $markets, &$counts): void {
                foreach ($rows as $row) {
                    $market = $markets->get($row->country_id);
                    if ($market === null) {
                        throw new RuntimeException("{$table} row {$row->id} has no market for country {$row->country_id}.");
                    }

                    $values = ['market_id' => $market->id];
                    if ($table === 'ads') {
                        if ($row->currency_code !== null && strtoupper($row->currency_code) !== $market->currency_code) {
                            throw new RuntimeException("Ad {$row->id} currency does not match its market.");
                        }
                        $values['currency_code'] = $market->currency_code;
                    } elseif (strtoupper($row->currency_code) !== $market->currency_code) {
                        throw new RuntimeException("Auction {$row->id} currency does not match its market.");
                    }

                    $counts[$table] += DB::table($table)->where('id', $row->id)->whereNull('market_id')->update($values);
                }
            });
        }

        foreach (['ad_images', 'ad_reels', 'ad_views', 'user_ad_interactions'] as $table) {
            $counts[$table] = $this->backfillFromParent($table, 'ad_id', 'ads');
        }
        $counts['ad_reel_views'] = $this->backfillFromParent('ad_reel_views', 'ad_reel_id', 'ad_reels');

        foreach ($this->auctionChildren as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'market_id')) {
                continue;
            }
            $counts[$table] = $this->backfillFromParent($table, 'auction_id', 'auctions');
        }

        $counts['payment_provider_events'] = $this->backfillFromParent('payment_provider_events', 'payment_transaction_id', 'payment_transactions', true);

        foreach (['banners', 'announcements', 'charity_systems', 'support_contacts', 'support_tickets', 'content_review_policies', 'content_review_settings'] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'market_id')) {
                $counts[$table] = DB::table($table)->whereNull('market_id')->update(['market_id' => $jo->id]);
            }
        }

        $this->backfillVersions('auction_terms_versions', 'terms_version_id', (int) $jo->id, $counts);
        $this->backfillVersions('auction_configuration_versions', 'configuration_version_id', (int) $jo->id, $counts);
        $this->backfillPaymentMethods((int) $jo->id, $counts);
        $this->backfillContentReviews((int) $jo->id, $counts);
        $this->backfillOutbox((int) $jo->id, $counts);
        $counts['market_category'] = $this->backfillJordanCategories((int) $jo->id);
        $counts['device_token_market'] = $this->backfillJordanDevices((int) $jo->id);

        return $counts;
    }

    /** @return array<string, int> */
    public function validate(): array
    {
        $issues = [];
        $tables = DB::getSchemaBuilder()->getTables();

        foreach ($tables as $definition) {
            $table = is_array($definition) ? ($definition['name'] ?? null) : ($definition->name ?? null);
            if (! is_string($table) || ! Schema::hasColumn($table, 'market_id')) {
                continue;
            }

            $nulls = DB::table($table)->whereNull('market_id')->count();
            if ($nulls > 0 && ! in_array($table, self::OPTIONAL_MARKET_TABLES, true)) {
                $issues["{$table}.null_market"] = $nulls;
            }

            $orphans = DB::table($table.' as scoped')->leftJoin('markets', 'markets.id', '=', 'scoped.market_id')
                ->whereNotNull('scoped.market_id')->whereNull('markets.id')->count();
            if ($orphans > 0) {
                $issues["{$table}.orphan_market"] = $orphans;
            }
        }

        foreach (['ads', 'auctions'] as $table) {
            $mismatches = DB::table($table.' as resource')->join('markets', 'markets.id', '=', 'resource.market_id')
                ->whereColumn('resource.country_id', '!=', 'markets.country_id')->count();
            if ($mismatches > 0) {
                $issues["{$table}.country_mismatch"] = $mismatches;
            }

            $currencyMismatches = DB::table($table.' as resource')->join('markets', 'markets.id', '=', 'resource.market_id')
                ->whereColumn('resource.currency_code', '!=', 'markets.currency_code')->count();
            if ($currencyMismatches > 0) {
                $issues["{$table}.currency_mismatch"] = $currencyMismatches;
            }
        }

        foreach ($this->auctionChildren as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'auction_id')) {
                continue;
            }
            $mixed = DB::table($table.' as child')->join('auctions', 'auctions.id', '=', 'child.auction_id')
                ->whereColumn('child.market_id', '!=', 'auctions.market_id')->count();
            if ($mixed > 0) {
                $issues["{$table}.auction_market_mismatch"] = $mixed;
            }
        }

        return $issues;
    }

    private function backfillFromParent(string $table, string $foreignKey, string $parent, bool $nullableParent = false): int
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'market_id')) {
            return 0;
        }

        $updated = 0;
        DB::table($table)->whereNull('market_id')->orderBy('id')->chunkById(500, function ($rows) use ($table, $foreignKey, $parent, $nullableParent, &$updated): void {
            foreach ($rows as $row) {
                $parentId = $row->{$foreignKey};
                if ($parentId === null && $nullableParent) {
                    continue;
                }
                $marketId = DB::table($parent)->where('id', $parentId)->value('market_id');
                if ($marketId === null) {
                    throw new RuntimeException("{$table} row {$row->id} cannot derive its market from {$parent}.");
                }
                $updated += DB::table($table)->where('id', $row->id)->whereNull('market_id')->update(['market_id' => $marketId]);
            }
        });

        return $updated;
    }

    private function backfillVersions(string $table, string $foreignKey, int $defaultMarketId, array &$counts): void
    {
        $counts[$table] = 0;
        foreach (DB::table($table)->whereNull('market_id')->orderBy('id')->get() as $version) {
            $markets = DB::table('auctions')->where($foreignKey, $version->id)->whereNotNull('market_id')->distinct()->pluck('market_id');
            if ($markets->count() > 1) {
                throw new RuntimeException("{$table} row {$version->id} is shared by multiple markets and must be split explicitly.");
            }
            $counts[$table] += DB::table($table)->where('id', $version->id)->update(['market_id' => $markets->first() ?? $defaultMarketId]);
        }
    }

    private function backfillPaymentMethods(int $defaultMarketId, array &$counts): void
    {
        $counts['payment_methods'] = 0;
        foreach (DB::table('payment_methods')->whereNull('market_id')->orderBy('id')->get() as $method) {
            $markets = collect();
            foreach (['payment_submissions', 'payment_transactions'] as $table) {
                if (Schema::hasColumn($table, 'payment_method_id')) {
                    $markets = $markets->merge(DB::table($table)->where('payment_method_id', $method->id)->whereNotNull('market_id')->pluck('market_id'));
                }
            }
            $markets = $markets->unique()->values();
            if ($markets->count() > 1) {
                throw new RuntimeException("Payment method {$method->id} is shared by multiple markets and must be split explicitly.");
            }
            $counts['payment_methods'] += DB::table('payment_methods')->where('id', $method->id)->update(['market_id' => $markets->first() ?? $defaultMarketId]);
        }
    }

    private function backfillContentReviews(int $defaultMarketId, array &$counts): void
    {
        $counts['content_reviews'] = 0;
        $counts['content_reviews_orphaned'] = 0;
        foreach (DB::table('content_reviews')->whereNull('market_id')->orderBy('id')->get() as $review) {
            $table = match (strtolower((string) $review->subject_type)) {
                'ad', 'ads' => 'ads',
                'auction', 'auctions' => 'auctions',
                default => null,
            };
            if ($table === null) {
                throw new RuntimeException("Content review {$review->id} has an unsupported subject type [{$review->subject_type}].");
            }

            $marketId = $this->resolveOwnerMarket($table, (int) $review->subject_id, $defaultMarketId, $counts['content_reviews_orphaned'])
                ?? throw new RuntimeException("Content review {$review->id} references {$table} {$review->subject_id} without a market.");

            $counts['content_reviews'] += DB::table('content_reviews')->where('id', $review->id)->update(['market_id' => $marketId]);
        }
    }

    private function backfillOutbox(int $defaultMarketId, array &$counts): void
    {
        $counts['outbox_messages'] = 0;
        $counts['outbox_messages_orphaned'] = 0;
        foreach (DB::table('outbox_messages')->whereNull('market_id')->orderBy('id')->get() as $message) {
            $table = match (strtolower(class_basename((string) $message->aggregate_type))) {
                'auction' => 'auctions',
                'ad' => 'ads',
                'contentreview' => 'content_reviews',
                default => null,
            };
            if ($table === null) {
                throw new RuntimeException("Outbox message {$message->id} has an unsupported aggregate type [{$message->aggregate_type}].");
            }

            $marketId = $this->resolveOwnerMarket($table, (int) $message->aggregate_id, $defaultMarketId, $counts['outbox_messages_orphaned'])
                ?? throw new RuntimeException("Outbox message {$message->id} references {$table} {$message->aggregate_id} without a market.");

            $counts['outbox_messages'] += DB::table('outbox_messages')->where('id', $message->id)->update(['market_id' => $marketId]);
        }
    }

    private function resolveOwnerMarket(string $table, int $ownerId, int $defaultMarketId, int &$orphans): ?int
    {
        $owner = DB::table($table)->where('id', $ownerId)->first(['market_id']);
        if ($owner !== null) {
            return $owner->market_id === null ? null : (int) $owner->market_id;
        }

        $orphans++;

        return $defaultMarketId;
    }

    private function backfillJordanCategories(int $marketId): int
    {
        if (! Schema::hasTable('market_category')) {
            return 0;
        }

        $inserted = 0;
        DB::table('categories')->select('id', 'display_order')->orderBy('id')->chunkById(500, function ($rows) use ($marketId, &$inserted): void {
            foreach ($rows as $row) {
                $inserted += (int) DB::table('market_category')->insertOrIgnore([
                    'market_id' => $marketId,
                    'category_id' => $row->id,
                    'display_order' => (int) $row->display_order,
                    'is_visible' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });

        return $inserted;
    }

    private function backfillJordanDevices(int $marketId): int
    {
        if (! Schema::hasTable('device_token_market') || ! Schema::hasTable('device_tokens')) {
            return 0;
        }

        $inserted = 0;
        DB::table('device_tokens')->select('id')->orderBy('id')->chunkById(500, function ($rows) use ($marketId, &$inserted): void {
            foreach ($rows as $row) {
                $inserted += (int) DB::table('device_token_market')->insertOrIgnore([
                    'device_token_id' => $row->id,
                    'market_id' => $marketId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });

        return $inserted;
    }
}
