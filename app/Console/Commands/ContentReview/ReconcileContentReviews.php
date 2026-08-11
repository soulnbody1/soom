<?php

declare(strict_types=1);

namespace App\Console\Commands\ContentReview;

use App\Services\ContentReview\Actions\ReconcileContentReviewsAction;
use App\Services\ContentReview\Support\ContentReviewLogContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

final class ReconcileContentReviews extends Command
{
    protected $signature = 'content-review:reconcile
        {--apply : Apply the two idempotent repairs instead of only reporting}
        {--limit= : Maximum number of rows inspected per repair}';

    protected $description = 'Report content reviews left inconsistent by a crash, and optionally apply the two idempotent repairs.';

    public function handle(ReconcileContentReviewsAction $reconcile, ContentReviewLogContext $logContext): int
    {
        $apply = (bool) $this->option('apply');
        $report = $reconcile->execute($apply, $this->limit());

        $this->line($apply ? 'Reconciliation applied.' : 'Reconciliation dry run — nothing was changed.');

        $this->table(['check', 'found', 'repaired'], [
            [
                'expired leases',
                (string) $report['repairable']['expired_leases'],
                (string) $report['repaired']['expired_leases'],
            ],
            [
                'terminal rows still marked active',
                (string) $report['repairable']['terminal_rows_marked_active'],
                (string) $report['repaired']['terminal_rows_marked_active'],
            ],
        ]);

        $this->table(['needs an admin decision', 'count'], [
            ['queued longer than the stale window', (string) $report['needs_admin']['stale_queued']],
            ['completed without an application state', (string) $report['needs_admin']['completed_without_application_state']],
            ['subjects with more than one active review', (string) $report['needs_admin']['subjects_with_multiple_active_reviews']],
            ['decisions without a review row', (string) $report['needs_admin']['decisions_without_review']],
        ]);

        if (! $apply) {
            $this->line('Re-run with --apply to release expired leases and clear stale active markers.');
        }

        Log::info('content_review.reconcile', $logContext->operational([
            'dry_run' => ! $apply,
            'repaired' => $report['repaired']['expired_leases'] + $report['repaired']['terminal_rows_marked_active'],
        ]));

        return self::SUCCESS;
    }

    private function limit(): int
    {
        $configured = (int) config('content_review.reconcile.limit', 200);
        $value = $this->option('limit');

        return max(1, min(1000, is_scalar($value) && (string) $value !== '' ? (int) $value : $configured));
    }
}
