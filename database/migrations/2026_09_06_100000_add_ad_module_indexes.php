<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Index the ad module.
 *
 * The ads table shipped without a single explicit index, so every listing,
 * filter and sort was a full scan, and three call sites issue MATCH ... AGAINST
 * against FULLTEXT indexes that no migration ever created (MySQL error 1191).
 *
 * Design notes:
 *
 * - Composites end with created_at, not id. InnoDB appends the primary key to
 *   every secondary index, so (col, deleted_at, created_at) is physically
 *   (col, deleted_at, created_at, id). That serves today's "ORDER BY created_at
 *   DESC" and tomorrow's stable "ORDER BY created_at DESC, id DESC" from the
 *   same index, so no code has to change in this migration.
 *
 * - MySQL creates a single-column index for every foreign key. Once a composite
 *   starting with that same column exists the single-column one is a redundant
 *   prefix, and the composite still satisfies the constraint, so it is dropped.
 *
 * - Anything MySQL-specific (FULLTEXT, prefix indexes on TEXT, the implicit FK
 *   indexes) is guarded by driver, so the SQLite test suite still migrates.
 */
return new class extends Migration
{
    /**
     * Redundant single-column indexes, keyed by the composite that replaces them.
     * Each listed column is the leftmost prefix of its replacement.
     */
    private const REDUNDANT_FOREIGN_KEY_INDEXES = [
        // ads_country_id_foreign is intentionally absent: no composite starts with
        // country_id, so that index is still the only one backing the constraint.
        'ads' => [
            'ads_category_id_foreign' => 'category_id',
            'ads_user_id_foreign' => 'user_id',
            'ads_state_id_foreign' => 'state_id',
            'ads_city_id_foreign' => 'city_id',
        ],
        'categories' => [
            'categories_parent_id_foreign' => 'parent_id',
        ],
        'attribute_values' => [
            'attribute_values_ad_id_foreign' => 'ad_id',
            'attribute_values_attribute_id_foreign' => 'attribute_id',
        ],
        'user_ad_interactions' => [
            'user_ad_interactions_user_id_foreign' => 'user_id',
            'user_ad_interactions_ad_id_foreign' => 'ad_id',
        ],
    ];

    public function up(): void
    {
        $this->removeDuplicateInteractions();

        $this->indexAds();
        $this->indexCategories();
        $this->indexUsers();
        $this->indexAttributeValues();
        $this->indexUserAdInteractions();
        $this->indexAdReels();

        $this->dropAlreadyCoveredIndexes();
        $this->dropRedundantForeignKeyIndexes();
    }

    public function down(): void
    {
        $this->restoreRedundantForeignKeyIndexes();
        $this->restoreAlreadyCoveredIndexes();

        Schema::table('ad_reels', function (Blueprint $table): void {
            $this->dropIndexIfExists($table, 'ad_reels', 'idx_ad_reels_created');
        });

        Schema::table('user_ad_interactions', function (Blueprint $table): void {
            $this->dropIndexIfExists($table, 'user_ad_interactions', 'idx_user_ad_interactions_ad_action');
            $this->dropUniqueIfExists($table, 'user_ad_interactions', 'uq_user_ad_interactions_user_ad_action');
        });

        Schema::table('attribute_values', function (Blueprint $table): void {
            $this->dropIndexIfExists($table, 'attribute_values', 'idx_attribute_values_ad_attribute');
            $this->dropIndexIfExists($table, 'attribute_values', 'idx_attribute_values_attribute_value');
        });

        Schema::table('users', function (Blueprint $table): void {
            $this->dropIndexIfExists($table, 'users', 'ft_users_name_phone');
        });

        Schema::table('categories', function (Blueprint $table): void {
            $this->dropIndexIfExists($table, 'categories', 'ft_categories_name');
            $this->dropIndexIfExists($table, 'categories', 'idx_categories_parent_order');
        });

        Schema::table('ads', function (Blueprint $table): void {
            foreach ([
                'ft_ads_title_description',
                'idx_ads_featured',
                'idx_ads_deleted_price',
                'idx_ads_city_deleted_created',
                'idx_ads_state_deleted_created',
                'idx_ads_user_created',
                'idx_ads_category_deleted_created',
                'idx_ads_deleted_created',
            ] as $index) {
                $this->dropIndexIfExists($table, 'ads', $index);
            }
        });
    }

    /**
     * The listing, home, category, admin and owner paths all filter on one of
     * category/user/country/state/city plus deleted_at, then sort by created_at.
     */
    private function indexAds(): void
    {
        Schema::table('ads', function (Blueprint $table): void {
            // WHERE deleted_at IS NULL ORDER BY created_at DESC — the bare listing,
            // the pagination COUNT(*), and the home feed.
            $this->addIndex($table, 'ads', ['deleted_at', 'created_at'], 'idx_ads_deleted_created');

            // WHERE category_id IN (subtree) AND deleted_at IS NULL ORDER BY created_at DESC.
            $this->addIndex($table, 'ads', ['category_id', 'deleted_at', 'created_at'], 'idx_ads_category_deleted_created');

            // "My ads" reads with withTrashed(), so deleted_at is deliberately absent here.
            $this->addIndex($table, 'ads', ['user_id', 'created_at'], 'idx_ads_user_created');

            // city_id and state_id are selective (56 and 8 distinct values in
            // production). country_id deliberately has no index: every ad shares the
            // same country, so such an index could never narrow a result set, and
            // measurements showed it luring the optimizer into a skip scan that made
            // the pagination COUNT slower. Add one only if the platform goes
            // multi-country.
            $this->addIndex($table, 'ads', ['city_id', 'deleted_at', 'created_at'], 'idx_ads_city_deleted_created');
            $this->addIndex($table, 'ads', ['state_id', 'deleted_at', 'created_at'], 'idx_ads_state_deleted_created');

            // Price range filtering, which always accompanies deleted_at IS NULL.
            $this->addIndex($table, 'ads', ['deleted_at', 'price'], 'idx_ads_deleted_price');

            // scopeFeatured: ORDER BY is_featured DESC, id DESC. The implicit primary
            // key suffix supplies the id half, so is_featured alone is enough.
            $this->addIndex($table, 'ads', ['is_featured'], 'idx_ads_featured');
        });

        $this->addFullText('ads', ['title', 'description'], 'ft_ads_title_description');
    }

    private function indexCategories(): void
    {
        Schema::table('categories', function (Blueprint $table): void {
            // The category tree is walked by parent_id and rendered by display_order.
            $this->addIndex($table, 'categories', ['parent_id', 'display_order'], 'idx_categories_parent_order');
        });

        $this->addFullText('categories', ['name'], 'ft_categories_name');
    }

    /**
     * App\Repositories\User\Queries\UserSearchQuery matches on (name, phone).
     */
    private function indexUsers(): void
    {
        $this->addFullText('users', ['name', 'phone'], 'ft_users_name_phone');
    }

    private function indexAttributeValues(): void
    {
        // value is TEXT, so MySQL needs a prefix length; 191 characters keeps the
        // key inside InnoDB's limit while covering realistic attribute values.
        if ($this->driver() === 'mysql') {
            DB::statement('ALTER TABLE `attribute_values` ADD INDEX `idx_attribute_values_attribute_value` (`attribute_id`, `value`(191))');
        } else {
            Schema::table('attribute_values', function (Blueprint $table): void {
                $this->addIndex($table, 'attribute_values', ['attribute_id', 'value'], 'idx_attribute_values_attribute_value');
            });
        }

        Schema::table('attribute_values', function (Blueprint $table): void {
            $this->addIndex($table, 'attribute_values', ['ad_id', 'attribute_id'], 'idx_attribute_values_ad_attribute');
        });
    }

    /**
     * The interaction write is a read-then-insert with no constraint behind it, so
     * concurrent taps duplicate rows and inflate the "COUNT(*) >= 3" audience
     * threshold in SendAdNotification. The unique key makes the write race-safe.
     */
    private function indexUserAdInteractions(): void
    {
        Schema::table('user_ad_interactions', function (Blueprint $table): void {
            $this->addUnique($table, 'user_ad_interactions', ['user_id', 'ad_id', 'action'], 'uq_user_ad_interactions_user_ad_action');

            // SendAdNotification aggregates by ad and action.
            $this->addIndex($table, 'user_ad_interactions', ['ad_id', 'action'], 'idx_user_ad_interactions_ad_action');
        });
    }

    private function indexAdReels(): void
    {
        Schema::table('ad_reels', function (Blueprint $table): void {
            // The reel feed windows on created_at >= ? and then sorts by it.
            $this->addIndex($table, 'ad_reels', ['created_at'], 'idx_ad_reels_created');
        });
    }

    /**
     * Two indexes duplicate a key that already exists on the same leading columns.
     */
    private function dropAlreadyCoveredIndexes(): void
    {
        // Identical to ad_reel_views_ad_reel_id_user_id_unique.
        Schema::table('ad_reel_views', function (Blueprint $table): void {
            $this->dropIndexIfExists($table, 'ad_reel_views', 'ad_reel_views_ad_reel_id_user_id_index');
        });

        // Leftmost prefix of favorites_user_id_ad_id_unique.
        Schema::table('favorites', function (Blueprint $table): void {
            $this->dropIndexIfExists($table, 'favorites', 'favorites_user_id_index');
        });
    }

    private function restoreAlreadyCoveredIndexes(): void
    {
        Schema::table('ad_reel_views', function (Blueprint $table): void {
            $this->addIndex($table, 'ad_reel_views', ['ad_reel_id', 'user_id'], 'ad_reel_views_ad_reel_id_user_id_index');
        });

        Schema::table('favorites', function (Blueprint $table): void {
            $this->addIndex($table, 'favorites', ['user_id'], 'favorites_user_id_index');
        });
    }

    /**
     * MySQL auto-creates these for the foreign keys. Each is now the leftmost
     * prefix of a composite added above, which still satisfies the constraint.
     */
    private function dropRedundantForeignKeyIndexes(): void
    {
        if ($this->driver() !== 'mysql') {
            return;
        }

        foreach (self::REDUNDANT_FOREIGN_KEY_INDEXES as $table => $indexes) {
            foreach (array_keys($indexes) as $index) {
                if ($this->indexExists($table, $index)) {
                    DB::statement("ALTER TABLE `{$table}` DROP INDEX `{$index}`");
                }
            }
        }
    }

    private function restoreRedundantForeignKeyIndexes(): void
    {
        if ($this->driver() !== 'mysql') {
            return;
        }

        foreach (self::REDUNDANT_FOREIGN_KEY_INDEXES as $table => $indexes) {
            foreach ($indexes as $index => $column) {
                if (! $this->indexExists($table, $index)) {
                    DB::statement("ALTER TABLE `{$table}` ADD INDEX `{$index}` (`{$column}`)");
                }
            }
        }
    }

    /**
     * Collapse pre-existing duplicates so the unique key can be created. Only rows
     * the new constraint would forbid are removed, and the oldest of each group is
     * kept, so no interaction is lost.
     */
    private function removeDuplicateInteractions(): void
    {
        if (! Schema::hasTable('user_ad_interactions')) {
            return;
        }

        $duplicateIds = DB::table('user_ad_interactions as duplicate')
            ->select('duplicate.id')
            ->joinSub(
                DB::table('user_ad_interactions')
                    ->selectRaw('user_id, ad_id, action, MIN(id) as keep_id')
                    ->groupBy('user_id', 'ad_id', 'action'),
                'kept',
                function ($join): void {
                    $join->on('duplicate.user_id', '=', 'kept.user_id')
                        ->on('duplicate.ad_id', '=', 'kept.ad_id')
                        ->on('duplicate.action', '=', 'kept.action');
                }
            )
            ->whereColumn('duplicate.id', '>', 'kept.keep_id')
            ->pluck('duplicate.id');

        if ($duplicateIds->isEmpty()) {
            return;
        }

        DB::table('user_ad_interactions')->whereIn('id', $duplicateIds)->delete();
    }

    private function addFullText(string $table, array $columns, string $name): void
    {
        // FULLTEXT is MySQL-only; SQLite runs the same queries through a LIKE path.
        if ($this->driver() !== 'mysql' || $this->indexExists($table, $name)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($columns, $name): void {
            $blueprint->fullText($columns, $name);
        });
    }

    private function addIndex(Blueprint $table, string $tableName, array $columns, string $name): void
    {
        if (! $this->indexExists($tableName, $name)) {
            $table->index($columns, $name);
        }
    }

    private function addUnique(Blueprint $table, string $tableName, array $columns, string $name): void
    {
        if (! $this->indexExists($tableName, $name)) {
            $table->unique($columns, $name);
        }
    }

    private function dropIndexIfExists(Blueprint $table, string $tableName, string $name): void
    {
        if ($this->indexExists($tableName, $name)) {
            $table->dropIndex($name);
        }
    }

    private function dropUniqueIfExists(Blueprint $table, string $tableName, string $name): void
    {
        if ($this->indexExists($tableName, $name)) {
            $table->dropUnique($name);
        }
    }

    private function indexExists(string $table, string $name): bool
    {
        if (! Schema::hasTable($table)) {
            return false;
        }

        foreach (Schema::getIndexes($table) as $index) {
            if (strcasecmp((string) $index['name'], $name) === 0) {
                return true;
            }
        }

        return false;
    }

    private function driver(): string
    {
        return Schema::getConnection()->getDriverName();
    }
};
