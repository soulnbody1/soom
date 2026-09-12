<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

final class CatalogSchemaTest extends CatalogTestCase
{
    use RefreshDatabase;

    public function test_the_pivot_is_indexed_for_the_inheritance_lookup(): void
    {
        $this->requireMysql('index introspection needs the MySQL schema');

        $this->assertSame(
            ['category_id', 'attribute_id', 'is_inheritable'],
            $this->indexColumns('attribute_category', 'idx_attribute_category_category_attribute')
        );
    }

    public function test_the_pivot_has_no_index_that_merely_repeats_another_prefix(): void
    {
        $this->requireMysql('index introspection needs the MySQL schema');

        foreach (['attribute_category', 'attribute_category_exceptions', 'categories'] as $table) {
            $indexes = collect(Schema::getIndexes($table))
                ->reject(fn (array $index): bool => ($index['type'] ?? null) === 'fulltext')
                ->map(fn (array $index): array => ['name' => $index['name'], 'columns' => $index['columns']]);

            foreach ($indexes as $candidate) {
                foreach ($indexes as $other) {
                    if ($candidate['name'] === $other['name']) {
                        continue;
                    }

                    $this->assertNotSame(
                        $candidate['columns'],
                        array_slice($other['columns'], 0, count($candidate['columns'])),
                        "{$table}.{$candidate['name']} is a redundant prefix of {$other['name']}."
                    );
                }
            }
        }
    }

    public function test_a_category_name_is_unique_within_its_parent(): void
    {
        $this->requireMysql('the unique key is only created on MySQL');

        $this->assertSame(
            ['parent_id', 'name'],
            $this->indexColumns('categories', 'uq_categories_parent_name')
        );
    }

    public function test_the_database_rejects_a_duplicate_name_under_the_same_parent(): void
    {
        $this->requireMysql('the unique key is only created on MySQL');

        $parent = $this->category();
        $this->category($parent->id, 'duplicate');

        $this->expectException(\Illuminate\Database\QueryException::class);

        $this->category($parent->id, 'duplicate');
    }

    /**
     * @return list<string>
     */
    private function indexColumns(string $table, string $name): array
    {
        foreach (Schema::getIndexes($table) as $index) {
            if (strcasecmp((string) $index['name'], $name) === 0) {
                return array_values($index['columns']);
            }
        }

        $this->fail("{$table} has no index named {$name}.");
    }
}
