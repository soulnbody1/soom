<?php

declare(strict_types=1);

namespace App\Console\Commands\ContentReview;

use App\Models\ContentReview\ContentReview;
use App\Repositories\ContentReview\ContentReviewRepository;
use App\Services\ContentReview\Actions\RequestContentReviewAction;
use App\Services\ContentReview\Support\ReviewModeResolver;
use Illuminate\Console\Command;

final class DispatchPendingContentReviews extends Command
{
    protected $signature = 'content-review:dispatch-pending {--limit= : Maximum number of reviews to re-dispatch}';

    protected $description = 'Re-dispatch queued content reviews and reclaim reviews whose worker lease expired.';

    public function handle(
        ContentReviewRepository $reviews,
        RequestContentReviewAction $requests,
        ReviewModeResolver $modes,
    ): int {
        if (config('content_review.enabled') !== true) {
            $this->info('Content review is disabled; nothing to dispatch.');

            return self::SUCCESS;
        }

        $limit = (int) ($this->option('limit') ?? config('content_review.sweeper.batch', 25));
        $requeueAfter = (int) config('content_review.sweeper.requeue_after_seconds', 60);

        $due = $reviews->dueForDispatch(max(1, $limit), max(1, $requeueAfter));
        $dispatched = 0;

        foreach ($due as $review) {
            if ($this->reclaim($reviews, $review)) {
                $requests->dispatch($review, $modes->effectiveSettings($review->subject_type));
                $dispatched++;
            }
        }

        $this->info("Re-dispatched {$dispatched} content review(s).");

        return self::SUCCESS;
    }

    private function reclaim(ContentReviewRepository $reviews, ContentReview $review): bool
    {
        if ($review->status->isTerminal() || $review->decided_at !== null || $review->current_marker === null) {
            return false;
        }

        $reviews->update($review, [
            'lease_owner' => null,
            'leased_until' => null,
        ]);

        return true;
    }
}
