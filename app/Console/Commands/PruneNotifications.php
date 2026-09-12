<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Notifications\DatabaseNotification;

class PruneNotifications extends Command
{
    protected $signature = 'notifications:prune {--days= : Delete read notifications older than this many days}
                                                {--chunk=1000 : Rows deleted per statement}
                                                {--dry-run : Report what would be deleted without deleting}';

    protected $description = 'حذف الإشعارات المقروءة الأقدم من مدة الاحتفاظ المحددة';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?? config('notifications.retention_days'));

        if ($days < 1) {
            $this->error('Retention must be at least one day.');

            return self::FAILURE;
        }

        $cutoff = now()->subDays($days);
        $chunk = max(1, (int) $this->option('chunk'));

        $query = fn () => DatabaseNotification::query()
            ->whereNotNull('read_at')
            ->where('created_at', '<', $cutoff);

        $candidates = $query()->count();

        if ($this->option('dry-run')) {
            $this->info("Would delete {$candidates} read notifications older than {$cutoff->toDateTimeString()}.");

            return self::SUCCESS;
        }

        $deleted = 0;

        do {
            $removed = $query()->limit($chunk)->delete();
            $deleted += $removed;
        } while ($removed === $chunk);

        $this->info("Deleted {$deleted} read notifications older than {$cutoff->toDateTimeString()}.");

        return self::SUCCESS;
    }
}
