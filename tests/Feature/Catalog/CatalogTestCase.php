<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use Illuminate\Support\Facades\Storage;
use Tests\Concerns\AssertsQueryCount;
use Tests\Feature\Catalog\Concerns\CreatesCatalogFixtures;
use Tests\TestCase;

abstract class CatalogTestCase extends TestCase
{
    use AssertsQueryCount;
    use CreatesCatalogFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('spaces');
    }

    protected function requireMysql(string $reason): void
    {
        $connection = config('database.default');

        if (config("database.connections.{$connection}.driver") !== 'mysql') {
            $this->markTestSkipped('Requires MySQL: '.$reason.'.');
        }
    }

    /**
     * @return list<string>
     */
    protected function attributeResourceKeys(): array
    {
        return ['id', 'name', 'type', 'is_required', 'is_multiple', 'parent_attribute_id', 'options'];
    }

    /**
     * @return list<string>
     */
    protected function categoryResourceKeys(): array
    {
        return ['id', 'name', 'image', 'display_order', 'parent_id'];
    }
}
