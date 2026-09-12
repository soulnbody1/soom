<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attribute_category', function (Blueprint $table): void {
            $this->addIndex(
                $table,
                'attribute_category',
                ['category_id', 'attribute_id', 'is_inheritable'],
                'idx_attribute_category_category_attribute'
            );
        });

        $this->dropIndexIfExists('attribute_category', 'attribute_category_category_id_foreign');

        $this->guardAgainstDuplicateCategoryNames();

        Schema::table('categories', function (Blueprint $table): void {
            $this->addUnique($table, 'categories', ['parent_id', 'name'], 'uq_categories_parent_name');
        });

        $this->dropIndexIfExists('categories', 'name');
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table): void {
            if ($this->indexExists('categories', 'uq_categories_parent_name')) {
                $table->dropUnique('uq_categories_parent_name');
            }
        });

        Schema::table('attribute_category', function (Blueprint $table): void {
            if ($this->indexExists('attribute_category', 'idx_attribute_category_category_attribute')) {
                $table->dropIndex('idx_attribute_category_category_attribute');
            }

            $this->addIndex($table, 'attribute_category', ['category_id'], 'attribute_category_category_id_foreign');
        });
    }

    private function guardAgainstDuplicateCategoryNames(): void
    {
        $duplicates = DB::table('categories')
            ->select('parent_id', 'name')
            ->selectRaw('COUNT(*) as total')
            ->groupBy('parent_id', 'name')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        if ($duplicates->isEmpty()) {
            return;
        }

        $conflicts = $duplicates
            ->map(static fn ($row): string => sprintf(
                'parent_id=%s name="%s" (%d rows)',
                $row->parent_id ?? 'NULL',
                $row->name,
                $row->total
            ))
            ->implode('; ');

        throw new RuntimeException(
            'Cannot add uq_categories_parent_name: rename these conflicting categories first — '.$conflicts
        );
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

    private function dropIndexIfExists(string $table, string $name): void
    {
        if ($this->driver() !== 'mysql' || ! $this->indexExists($table, $name)) {
            return;
        }

        DB::statement("ALTER TABLE `{$table}` DROP INDEX `{$name}`");
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
