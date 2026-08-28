<?php

declare(strict_types=1);

namespace App\Services\ContentReview\Actions;

use App\Domain\ContentReview\Enums\ContentReviewStatus;
use App\Repositories\ContentReview\ContentReviewDecisionRepository;
use App\Repositories\ContentReview\ContentReviewMetricsRepository;
use App\Repositories\ContentReview\ContentReviewRepository;
use Illuminate\Support\Carbon;

final class ReconcileContentReviewsAction
{
    public function __construct(
        private readonly ContentReviewRepository $reviews,
        private readonly ContentReviewMetricsRepository $metrics,
        private readonly ContentReviewDecisionRepository $decisions,
    ) {}

    public function execute(bool $apply, int $limit): array
    {
        $now = Carbon::now();
        $staleQueuedBefore = $now->copy()->subSeconds($this->staleQueuedSeconds());

        $expiredLeases = $this->reviews->expiredLeases($now, $limit);
        $activeTerminals = $this->reviews->terminalRowsStillMarkedActive($limit);

        $report = [
            'applied' => $apply,
            'limit' => $limit,
            'repairable' => [
                'expired_leases' => $expiredLeases->count(),
                'terminal_rows_marked_active' => $activeTerminals->count(),
            ],
            'repaired' => [
                'expired_leases' => 0,
                'terminal_rows_marked_active' => 0,
            ],
            'needs_admin' => [
                'stale_queued' => $this->metrics->staleQueuedCount($staleQueuedBefore),
                'completed_without_application_state' => $this->reviews->completedWithoutApplicationStateCount(
                    $now->copy()->subSeconds($this->staleQueuedSeconds())
                ),
                'subjects_with_multiple_active_reviews' => $this->reviews->subjectsWithMultipleActiveReviews(),
                'decisions_without_review' => $this->decisions->orphanCount(),
            ],
        ];

        if (! $apply) {
            return $report;
        }

        foreach ($expiredLeases as $review) {
            if ($review->status !== ContentReviewStatus::Running || $review->leased_until === null) {
                continue;
            }

            $this->reviews->update($review, [
                'status' => ContentReviewStatus::Queued->value,
                'lease_owner' => null,
                'leased_until' => null,
            ]);

            $report['repaired']['expired_leases']++;
        }

        foreach ($activeTerminals as $review) {
            if ($review->current_marker === null) {
                continue;
            }

            $this->reviews->deactivate($review);
            $report['repaired']['terminal_rows_marked_active']++;
        }

        return $report;
    }

    private function staleQueuedSeconds(): int
    {
        return max(60, (int) config('content_review.reconcile.stale_queued_seconds', 3600));
    }
}
