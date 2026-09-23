<?php

use App\Services\Market\MarketDataMigrator;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->pretending()) {
            return;
        }

        $migrator = app(MarketDataMigrator::class);
        $counts = $migrator->backfill();

        $orphans = array_filter([
            'content_reviews' => $counts['content_reviews_orphaned'] ?? 0,
            'outbox_messages' => $counts['outbox_messages_orphaned'] ?? 0,
        ]);
        if ($orphans !== []) {
            Log::warning('Market backfill assigned orphaned rows to the legacy market.', $orphans);
        }

        $issues = $migrator->validate();
        if ($issues !== []) {
            throw new RuntimeException('Market backfill validation failed: '.json_encode($issues, JSON_THROW_ON_ERROR));
        }
    }

    public function down(): void {}
};
