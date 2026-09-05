<?php

declare(strict_types=1);

namespace Tests\Feature\Ad;

use Illuminate\Support\Facades\Storage;
use Tests\Concerns\AssertsQueryCount;
use Tests\Feature\Ad\Concerns\CreatesAdFixtures;
use Tests\TestCase;

/**
 * Base for the Ad characterization suite.
 *
 * These tests pin the CURRENT public JSON contract so the module refactor can be
 * verified as behaviour-preserving. They must keep passing untouched through
 * every phase — a failure here means the API contract broke.
 */
abstract class AdTestCase extends TestCase
{
    use AssertsQueryCount;
    use CreatesAdFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('spaces');
    }

    /**
     * Some ad endpoints emit MySQL-only SQL (MATCH ... AGAINST, FIELD()), so they
     * can only be characterized on MySQL — run them via phpunit.ads-mysql.xml.
     */
    protected function requireMysql(string $reason = 'the endpoint emits MySQL-only SQL'): void
    {
        $connection = config('database.default');

        if (config("database.connections.{$connection}.driver") !== 'mysql') {
            $this->markTestSkipped('Requires MySQL: '.$reason.'.');
        }
    }

    /**
     * The exact key set AdResource emits today, in order.
     *
     * @return list<string>
     */
    protected function adResourceKeys(): array
    {
        return [
            'id', 'title', 'description', 'price', 'category', 'location',
            'latitude', 'longitude', 'attributes', 'user', 'images',
            'created_at', 'views_count', 'is_favorite', 'status', 'is_featured',
        ];
    }
}
