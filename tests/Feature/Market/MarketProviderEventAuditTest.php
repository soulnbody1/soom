<?php

declare(strict_types=1);

namespace Tests\Feature\Market;

use App\Models\Auction\PaymentProviderEvent;
use App\Services\Market\MarketDataMigrator;
use App\Support\Market\MarketContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class MarketProviderEventAuditTest extends TestCase
{
    use RefreshDatabase;

    private function insertUnmatchedEvent(string $error): void
    {
        DB::table('payment_provider_events')->insert([
            'market_id' => null,
            'public_id' => (string) Str::ulid(),
            'provider' => 'ngenius',
            'event_id' => (string) Str::ulid(),
            'event_type' => 'PURCHASED',
            'payment_transaction_id' => null,
            'provider_transaction_id' => 'probe-unknown-order-reference',
            'signature_verified' => $error !== 'signature_invalid',
            'payload_redacted' => '{}',
            'received_at' => now(),
            'processed_at' => now(),
            'process_error' => $error,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_an_unmatched_inbound_webhook_keeps_its_audit_row_without_a_market(): void
    {
        $this->insertUnmatchedEvent('transaction_not_found');
        $this->insertUnmatchedEvent('signature_invalid');

        app(MarketDataMigrator::class)->backfill();

        $this->assertSame([], app(MarketDataMigrator::class)->validate());
        $this->assertSame(2, DB::table('payment_provider_events')->whereNull('market_id')->count());
    }

    public function test_the_audit_log_can_be_written_from_a_global_context(): void
    {
        $event = app(MarketContext::class)->runGlobally(
            fn (): PaymentProviderEvent => PaymentProviderEvent::query()->create([
                'provider' => 'ngenius',
                'event_id' => (string) Str::ulid(),
                'event_type' => 'unknown',
                'provider_transaction_id' => '',
                'signature_verified' => false,
                'payload_redacted' => [],
                'received_at' => now(),
            ])
        );

        $this->assertNull($event->market_id);
        $this->assertTrue($event->exists);
    }

    public function test_a_market_scoped_model_still_refuses_a_global_write(): void
    {
        $this->expectException(\LogicException::class);

        app(MarketContext::class)->runGlobally(
            fn () => \App\Models\Banner::query()->create(['image' => 'x.jpg', 'is_active' => true])
        );
    }

    public function test_a_matched_event_is_owned_by_the_market_of_its_transaction(): void
    {
        $jo = \App\Models\Market::query()->where('code', 'JO')->firstOrFail();

        $event = app(MarketContext::class)->runInMarket(
            $jo,
            fn (): PaymentProviderEvent => PaymentProviderEvent::query()->create([
                'provider' => 'ngenius',
                'event_id' => (string) Str::ulid(),
                'event_type' => 'PURCHASED',
                'provider_transaction_id' => 'ref-1',
                'signature_verified' => true,
                'payload_redacted' => [],
                'received_at' => now(),
            ])
        );

        $this->assertSame($jo->getKey(), $event->market_id);
    }
}
