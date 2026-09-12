<?php

declare(strict_types=1);

namespace Tests\Feature\Notification;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

final class NotificationScaleBenchmarkTest extends NotificationTestCase
{
    use RefreshDatabase;

    private const OWNER_ROWS = 50000;

    private const NEIGHBOUR_ROWS = 50000;

    public function test_the_hot_queries_are_reported_at_scale(): void
    {
        $this->requireMysql('EXPLAIN output and timings only mean something on the real engine');

        $owner = $this->notifiableUser();
        $neighbour = $this->notifiableUser();

        $this->bulkSeedNotifications($owner, self::OWNER_ROWS, 3);
        $this->bulkSeedNotifications($neighbour, self::NEIGHBOUR_ROWS, 3);

        $report = [
            'rows' => DB::table('notifications')->count(),
            'owner_rows' => $owner->notifications()->count(),
            'list' => $this->profile(fn () => $owner->notifications()
                ->orderBy('created_at', 'desc')
                ->paginate(10)),
            'unread_count' => $this->profile(fn () => $owner->unreadNotifications()->count()),
            'mark_all_single_statement' => $this->profile(fn () => $owner->unreadNotifications()
                ->toBase()
                ->update(['read_at' => now()])),
        ];

        $report['explain'] = [
            'list' => $this->explain(
                'SELECT * FROM notifications WHERE notifiable_type = ? AND notifiable_id = ? '
                .'ORDER BY created_at DESC LIMIT 10',
                [User::class, $owner->id]
            ),
            'unread_count' => $this->explain(
                'SELECT COUNT(*) FROM notifications WHERE notifiable_type = ? AND notifiable_id = ? '
                .'AND read_at IS NULL',
                [User::class, $owner->id]
            ),
        ];

        fwrite(STDERR, PHP_EOL.'[notification scale] '.json_encode($report, JSON_PRETTY_PRINT).PHP_EOL);

        $this->assertGreaterThan(0, $report['owner_rows']);
    }

    private function profile(callable $callback): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $start = microtime(true);
        $callback();
        $elapsed = round((microtime(true) - $start) * 1000, 1);

        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return ['queries' => $queries, 'ms' => $elapsed];
    }

    private function explain(string $sql, array $bindings): array
    {
        return array_map(
            static fn (object $row): array => [
                'type' => $row->type ?? null,
                'key' => $row->key ?? null,
                'rows' => $row->rows ?? null,
                'filtered' => $row->filtered ?? null,
                'extra' => $row->Extra ?? null,
            ],
            DB::select('EXPLAIN '.$sql, $bindings)
        );
    }
}
