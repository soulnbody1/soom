<?php

declare(strict_types=1);

namespace App\Services\ContentReview\Actions;

use App\Domain\ContentReview\Enums\ContentReviewStatus;
use App\Domain\ContentReview\Enums\ReviewableSubjectType;
use App\Domain\ContentReview\Enums\ReviewMode;
use App\Domain\ContentReview\Enums\ReviewTrigger;
use App\Domain\ContentReview\ValueObjects\ReviewSettings;
use App\Jobs\ContentReview\ProcessContentReviewJob;
use App\Models\ContentReview\ContentReview;
use App\Repositories\ContentReview\ContentReviewRepository;
use App\Services\ContentReview\Contracts\ContentReviewEventPublisher;
use App\Services\ContentReview\Support\ContentHasher;
use App\Services\ContentReview\Support\ReviewModeResolver;
use App\Services\ContentReview\Support\ReviewPolicyResolver;
use App\Services\ContentReview\Support\ReviewSubjectRegistry;
use Illuminate\Support\Carbon;

final class RequestContentReviewAction
{
    public function __construct(
        private readonly ContentReviewRepository $reviews,
        private readonly ReviewModeResolver $modes,
        private readonly ReviewPolicyResolver $policies,
        private readonly ReviewSubjectRegistry $registry,
        private readonly ContentHasher $hasher,
        private readonly SupersedeContentReviewAction $supersede,
        private readonly ContentReviewEventPublisher $events,
    ) {}

    public function execute(
        ReviewableSubjectType $type,
        int $subjectId,
        ReviewTrigger $trigger,
        ?int $requestedBy = null,
        ?ReviewMode $modeCeiling = null,
    ): ?ContentReview {
        $mode = $this->modes->resolve($type);

        if ($modeCeiling !== null && ! $modeCeiling->isAtLeastAsPermissiveAs($mode)) {
            $mode = $modeCeiling;
        }

        if (! $mode->callsProvider()) {
            return null;
        }

        if (! $this->registry->supports($type)) {
            return null;
        }

        $adapter = $this->registry->for($type);

        if (! $adapter->isReviewable($subjectId)) {
            return null;
        }

        $content = $adapter->buildContent($subjectId);

        if ($content === null) {
            return null;
        }

        $settings = $this->modes->activeSettings($type);
        $effective = $this->modes->effectiveSettings($type);
        $policy = $this->policies->activeRecord($type);

        $this->supersede->execute($type, $subjectId);

        $contentHash = $this->hasher->hashContent($content);
        $attempt = $this->reviews->nextAttemptForContent($type, $subjectId, $contentHash);

        $review = $this->reviews->create([
            'subject_type' => $type->value,
            'subject_id' => $subjectId,
            'content_hash' => $contentHash,
            'trigger' => $trigger->value,
            'mode' => $mode->value,
            'status' => ContentReviewStatus::Queued->value,
            'requires_human_review' => true,
            'policy_id' => $policy?->id,
            'policy_version' => $policy === null ? null : (int) $policy->version_number,
            'settings_version' => $settings === null ? null : (int) $settings->version_number,
            'prompt_version' => $policy === null ? null : (string) $policy->prompt_version,
            'result_schema_version' => $policy === null ? 1 : (int) $policy->result_schema_version,
            'attempt' => $attempt,
            'max_attempts' => $attempt - 1 + $effective->maxAttempts(),
            'image_count' => count($content->images),
            'requested_by' => $requestedBy,
            'current_marker' => 1,
            'queued_at' => Carbon::now(),
        ]);

        $this->events->publish($review, 'content_review.queued');

        $this->dispatch($review, $effective);

        return $review;
    }

    public function dispatch(ContentReview $review, ReviewSettings $settings): void
    {
        ProcessContentReviewJob::dispatch(
            (string) $review->public_id,
            $settings->maxAttempts(),
            $settings->timeoutSeconds(),
            $settings->backoffSeconds(),
        )->afterCommit();
    }
}
