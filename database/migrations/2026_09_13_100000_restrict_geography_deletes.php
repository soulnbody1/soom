<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const CONSTRAINTS = [
        ['ads', 'ads_country_id_foreign', 'country_id', 'countries'],
        ['ads', 'ads_state_id_foreign', 'state_id', 'states'],
        ['ads', 'ads_city_id_foreign', 'city_id', 'cities'],
        ['users', 'users_country_id_foreign', 'country_id', 'countries'],
        ['users', 'users_state_id_foreign', 'state_id', 'states'],
        ['users', 'users_city_id_foreign', 'city_id', 'cities'],
    ];

    public function up(): void
    {
        $this->rebuild('RESTRICT');
    }

    public function down(): void
    {
        $this->rebuild('CASCADE');
    }

    private function rebuild(string $rule): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        foreach (self::CONSTRAINTS as [$table, $name, $column, $referenced]) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                continue;
            }

            if ($this->constraintExists($table, $name)) {
                DB::statement("ALTER TABLE `{$table}` DROP FOREIGN KEY `{$name}`");
            }

            if (! $this->indexExists($table, $column)) {
                DB::statement("ALTER TABLE `{$table}` ADD INDEX `{$name}` (`{$column}`)");
            }

            DB::statement(
                "ALTER TABLE `{$table}` ADD CONSTRAINT `{$name}` FOREIGN KEY (`{$column}`) ".
                "REFERENCES `{$referenced}` (`id`) ON DELETE {$rule}"
            );
        }
    }

    private function constraintExists(string $table, string $name): bool
    {
        return DB::table('information_schema.TABLE_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', $table)
            ->where('CONSTRAINT_NAME', $name)
            ->where('CONSTRAINT_TYPE', 'FOREIGN KEY')
            ->exists();
    }

    private function indexExists(string $table, string $column): bool
    {
        foreach (Schema::getIndexes($table) as $index) {
            if (($index['columns'][0] ?? null) === $column) {
                return true;
            }
        }

        return false;
    }
};
