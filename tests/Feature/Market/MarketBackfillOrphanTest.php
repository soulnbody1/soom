<?php

declare(strict_types=1);

namespace Tests\Feature\Market;

use App\Models\ContentReview\ContentReview;
use App\Models\Market;
use App\Services\Market\MarketDataMigrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

final class MarketBackfillOrphanTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The backfill only ever sees a null market_id between the expand and the
     * contract migration, so the window is reopened here on the one driver that
     * can widen a column in place.
     */
    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Reopening the pre-contract window needs MySQL.');
        }

        DB::statement('ALTER TABLE outbox_messages MODIFY market_id BIGINT UNSIGNED NULL');
        DB::statement('ALTER TABLE content_reviews MODIFY market_id BIGINT UNSIGNED NULL');
        DB::statement('ALTER TABLE auctions MODIFY market_id BIGINT UNSIGNED NULL');
    }

    private function jordanId(): int
    {
        return (int) Market::query()->where('code', 'JO')->value('id');
    }

    private function insertOutbox(string $aggregateType, int $aggregateId): int
    {
        return (int) DB::table('outbox_messages')->insertGetId([
            'market_id' => null,
            'public_id' => (string) Str::ulid(),
            'event_id' => (string) Str::ulid(),
            'topic' => 'content_review.events',
            'event_type' => 'content_review.provider_unavailable',
            'aggregate_type' => $aggregateType,
            'aggregate_id' => $aggregateId,
            'payload' => '{}',
            'status' => 'pending',
            'attempts' => 0,
            'available_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_an_orphaned_outbox_row_no_longer_aborts_the_whole_migration(): void
    {
        $id = $this->insertOutbox(ContentReview::class, 0);

        $counts = app(MarketDataMigrator::class)->backfill();

        $this->assertSame(1, $counts['outbox_messages_orphaned']);
        $this->assertSame($this->jordanId(), (int) DB::table('outbox_messages')->where('id', $id)->value('market_id'));
        $this->assertSame([], app(MarketDataMigrator::class)->validate());
    }

    public function test_a_row_whose_owner_exists_is_not_treated_as_an_orphan(): void
    {
        $jo = Market::query()->where('code', 'JO')->firstOrFail();
        $auction = DB::table('auctions')->where('market_id', $jo->getKey())->value('id')
            ?? $this->seedAuction($jo);

        DB::table('auctions')->where('id', $auction)->update(['market_id' => null]);
        $id = $this->insertOutbox(\App\Models\Auction\Auction::class, (int) $auction);

        $counts = app(MarketDataMigrator::class)->backfill();

        $this->assertSame(0, $counts['outbox_messages_orphaned']);
        $this->assertSame($this->jordanId(), (int) DB::table('outbox_messages')->where('id', $id)->value('market_id'));
    }

    private function seedAuction(Market $market): int
    {
        return (int) app(\App\Support\Market\MarketContext::class)->runInMarket(
            $market,
            fn (): int => (int) \App\Models\Auction\Auction::factory()->create([
                'country_id' => $market->country_id,
                'currency_code' => $market->currency_code,
            ])->id
        );
    }

    public function test_an_unmapped_aggregate_type_is_reported_rather_than_guessed(): void
    {
        $this->insertOutbox('App\\Models\\Something\\Unknown', 1);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unsupported aggregate type');

        app(MarketDataMigrator::class)->backfill();
    }
}
