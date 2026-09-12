<?php

declare(strict_types=1);

namespace Tests\Feature\Notification;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

final class NotificationSchemaTest extends NotificationTestCase
{
    use RefreshDatabase;

    public function test_the_list_path_is_indexed_for_its_ordering(): void
    {
        $this->requireMysql('index introspection needs the MySQL schema');

        $this->assertSame(
            ['notifiable_type', 'notifiable_id', 'created_at'],
            $this->indexColumns('idx_notifications_notifiable_created')
        );
    }

    public function test_the_unread_path_is_covered_by_its_own_index(): void
    {
        $this->requireMysql('index introspection needs the MySQL schema');

        $this->assertSame(
            ['notifiable_type', 'notifiable_id', 'read_at', 'created_at'],
            $this->indexColumns('idx_notifications_notifiable_unread')
        );
    }

    public function test_no_index_merely_repeats_the_prefix_of_another(): void
    {
        $this->requireMysql('index introspection needs the MySQL schema');

        $indexes = collect(Schema::getIndexes('notifications'))
            ->reject(fn (array $index): bool => ($index['type'] ?? null) === 'fulltext')
            ->map(fn (array $index): array => ['name' => $index['name'], 'columns' => array_values($index['columns'])]);

        foreach ($indexes as $candidate) {
            foreach ($indexes as $other) {
                if ($candidate['name'] === $other['name']) {
                    continue;
                }

                $this->assertNotSame(
                    $candidate['columns'],
                    array_slice($other['columns'], 0, count($candidate['columns'])),
                    "notifications.{$candidate['name']} is a redundant prefix of {$other['name']}."
                );
            }
        }
    }

    public function test_the_list_query_no_longer_sorts_in_memory(): void
    {
        $this->requireMysql('EXPLAIN is engine specific');

        $user = $this->notifiableUser();
        $this->bulkSeedNotifications($user, 2000, 4);

        $plan = $this->explain(
            'SELECT * FROM notifications WHERE notifiable_type = ? AND notifiable_id = ? '
            .'ORDER BY created_at DESC LIMIT 10',
            [\App\Models\User::class, $user->id]
        );

        $this->assertStringNotContainsStringIgnoringCase('filesort', (string) $plan->Extra);
        $this->assertSame('idx_notifications_notifiable_created', $plan->key);
    }

    public function test_the_unread_count_is_answered_from_the_index_alone(): void
    {
        $this->requireMysql('EXPLAIN is engine specific');

        $user = $this->notifiableUser();
        $this->bulkSeedNotifications($user, 2000, 2);
        $user->unreadNotifications()->toBase()->limit(1990)->update(['read_at' => now()]);

        $plan = $this->explain(
            'SELECT COUNT(*) FROM notifications WHERE notifiable_type = ? AND notifiable_id = ? '
            .'AND read_at IS NULL',
            [\App\Models\User::class, $user->id]
        );

        $this->assertStringContainsStringIgnoringCase('using index', (string) $plan->Extra);
    }

    private function explain(string $sql, array $bindings): object
    {
        return \Illuminate\Support\Facades\DB::select('EXPLAIN '.$sql, $bindings)[0];
    }

    /**
     * @return list<string>
     */
    private function indexColumns(string $name): array
    {
        foreach (Schema::getIndexes('notifications') as $index) {
            if (strcasecmp((string) $index['name'], $name) === 0) {
                return array_values($index['columns']);
            }
        }

        $this->fail("notifications has no index named {$name}.");
    }
}
