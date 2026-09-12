<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const DUPLICATES = [
        ['ads', 'title', 'ft_ads_title_description'],
        ['users', 'name', 'ft_users_name_phone'],
        ['categories', 'name', 'ft_categories_name'],
        ['message_deletions', 'message_deletions_user_id_message_id_index', 'message_deletions_user_id_message_id_unique'],
    ];

    private const UNUSED = [
        ['ads', 'idx_price'],
    ];

    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        foreach (self::DUPLICATES as [$table, $drop, $keep]) {
            if ($this->columnsOf($table, $drop) === null) {
                continue;
            }

            if ($this->columnsOf($table, $drop) !== $this->columnsOf($table, $keep)) {
                continue;
            }

            DB::statement("ALTER TABLE `{$table}` DROP INDEX `{$drop}`");
        }

        foreach (self::UNUSED as [$table, $drop]) {
            if ($this->columnsOf($table, $drop) !== null) {
                DB::statement("ALTER TABLE `{$table}` DROP INDEX `{$drop}`");
            }
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        if ($this->columnsOf('ads', 'title') === null) {
            DB::statement('ALTER TABLE `ads` ADD FULLTEXT `title` (`title`, `description`)');
        }

        if ($this->columnsOf('users', 'name') === null) {
            DB::statement('ALTER TABLE `users` ADD FULLTEXT `name` (`name`, `phone`)');
        }

        if ($this->columnsOf('categories', 'name') === null) {
            DB::statement('ALTER TABLE `categories` ADD FULLTEXT `name` (`name`)');
        }

        if ($this->columnsOf('message_deletions', 'message_deletions_user_id_message_id_index') === null) {
            DB::statement('ALTER TABLE `message_deletions` ADD INDEX `message_deletions_user_id_message_id_index` (`user_id`, `message_id`)');
        }

        if ($this->columnsOf('ads', 'idx_price') === null) {
            DB::statement('ALTER TABLE `ads` ADD INDEX `idx_price` (`price`)');
        }
    }

    private function columnsOf(string $table, string $index): ?string
    {
        if (! Schema::hasTable($table)) {
            return null;
        }

        foreach (Schema::getIndexes($table) as $candidate) {
            if (strcasecmp((string) $candidate['name'], $index) === 0) {
                return implode(',', $candidate['columns']);
            }
        }

        return null;
    }
};
