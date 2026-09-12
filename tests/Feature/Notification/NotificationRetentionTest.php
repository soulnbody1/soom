<?php

declare(strict_types=1);

namespace Tests\Feature\Notification;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;

final class NotificationRetentionTest extends NotificationTestCase
{
    use RefreshDatabase;

    public function test_it_deletes_read_notifications_past_the_retention_window(): void
    {
        $user = $this->notifiableUser();

        $stale = $this->notifications()->forUser($user)->read()->create([
            'created_at' => now()->subDays(120),
        ]);
        $recent = $this->notifications()->forUser($user)->read()->create([
            'created_at' => now()->subDays(10),
        ]);

        $this->artisan('notifications:prune')->assertExitCode(0);

        $this->assertNull(DatabaseNotification::find($stale->id));
        $this->assertNotNull(DatabaseNotification::find($recent->id));
    }

    public function test_it_never_deletes_an_unread_notification(): void
    {
        $user = $this->notifiableUser();

        $ancient = $this->notifications()->forUser($user)->create([
            'created_at' => now()->subYears(3),
        ]);

        $this->artisan('notifications:prune')->assertExitCode(0);

        $this->assertNotNull(DatabaseNotification::find($ancient->id));
    }

    public function test_a_dry_run_deletes_nothing(): void
    {
        $user = $this->notifiableUser();
        $stale = $this->notifications()->forUser($user)->read()->create([
            'created_at' => now()->subDays(120),
        ]);

        $this->artisan('notifications:prune', ['--dry-run' => true])->assertExitCode(0);

        $this->assertNotNull(DatabaseNotification::find($stale->id));
    }

    public function test_the_window_is_configurable(): void
    {
        $user = $this->notifiableUser();
        $row = $this->notifications()->forUser($user)->read()->create([
            'created_at' => now()->subDays(10),
        ]);

        $this->artisan('notifications:prune', ['--days' => 5])->assertExitCode(0);

        $this->assertNull(DatabaseNotification::find($row->id));
    }

    public function test_it_rejects_a_zero_day_window(): void
    {
        $this->artisan('notifications:prune', ['--days' => 0])->assertExitCode(1);
    }

    public function test_it_deletes_in_bounded_chunks(): void
    {
        $user = $this->notifiableUser();

        for ($index = 0; $index < 7; $index++) {
            $this->notifications()->forUser($user)->read()->create([
                'created_at' => now()->subDays(200 + $index),
            ]);
        }

        $deletes = [];
        DB::listen(function ($query) use (&$deletes): void {
            if (str_starts_with(strtolower(trim($query->sql)), 'delete')) {
                $deletes[] = $query->sql;
            }
        });

        $this->artisan('notifications:prune', ['--chunk' => 3])->assertExitCode(0);

        $this->assertSame(0, DatabaseNotification::count());
        $this->assertGreaterThan(1, count($deletes), 'The prune ran as one unbounded statement.');
    }
}
