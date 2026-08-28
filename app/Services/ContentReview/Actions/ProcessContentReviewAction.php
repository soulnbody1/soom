<?php

declare(strict_types=1);

namespace App\Services\ContentReview\Actions;

use App\Domain\ContentReview\Enums\ContentReviewErrorCode;
use App\Domain\ContentReview\Enums\SubjectVerdict;
use App\Domain\ContentReview\Exceptions\ContentReviewException;
use App\Domain\ContentReview\Exceptions\ContentReviewProviderException;
use App\Domain\ContentReview\ValueObjects\ReviewPolicy;
use App\Domain\ContentReview\ValueObjects\ReviewSettings;
use App\DTO\ContentReview\DeterministicCheckResult;
use App\DTO\ContentReview\ImageAnalysisPlan;
use App\DTO\ContentReview\ProviderReviewRequest;
use App\DTO\ContentReview\ProviderReviewResponse;
use App\DTO\ContentReview\ResolvedProvider;
use App\DTO\ContentReview\ReviewContentDTO;
use App\Models\ContentReview\ContentReview;
use App\Repositories\ContentReview\ContentReviewRepository;
use App\Services\ContentReview\Contracts\ContentReviewEventPublisher;
use App\Services\ContentReview\Support\ContentReviewLogContext;
use App\Services\ContentReview\Support\ContentReviewStateWriter;
use App\Services\ContentReview\Support\DeterministicContentChecks;
use App\Services\ContentReview\Support\ErrorMessageRedactor;
use App\Services\ContentReview\Support\ImageAnalysisPlanner;
use App\Services\ContentReview\Support\ProviderCallCapacity;
use App\Services\ContentReview\Support\ProviderCallGuard;
use App\Services\ContentReview\Support\ProviderOutcomeRecorder;
use App\Services\ContentReview\Support\ProviderResolver;
use App\Services\ContentReview\Support\ReviewModeResolver;
use App\Services\ContentReview\Support\ReviewPolicyResolver;
use App\Services\ContentReview\Support\ReviewPromptRenderer;
use App\Services\ContentReview\Support\ReviewSubjectVerifier;
use App\Services\ContentReview\Support\StructuredReviewResultValidator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * One review, start to finish: claim it, check it is still worth running, call the provider,
 * validate what comes back, and hand the result to the decision step.
 *
 * The order of the guards below is the design. Each one is cheaper than the next, and each
 * failure has its own terminal state, so the sequence is kept flat and visible here rather
 * than hidden behind a chain of collaborators.
 */
final class ProcessContentReviewAction
{
    public const RESULT_PROCESSED = 'processed';

    public const RESULT_SKIPPED = 'skipped';

    public const RESULT_NO_SLOT = 'no_slot';

    /** A self-clearing condition blocked the call; the review waits rather than failing. */
    public const RESULT_DEFERRED = 'deferred';

    public function __construct(
        private readonly ContentReviewRepository $reviews,
        private readonly ContentReviewStateWriter $state,
        private readonly ReviewModeResolver $modes,
        private readonly ReviewPolicyResolver $policies,
        private readonly ReviewSubjectVerifier $subjects,
        private readonly DeterministicContentChecks $deterministic,
        private readonly ReviewPromptRenderer $renderer,
        private readonly ImageAnalysisPlanner $imagePlanner,
        private readonly StructuredReviewResultValidator $validator,
        private readonly ProviderResolver $providerResolver,
        private readonly ProviderCallCapacity $capacity,
        private readonly ProviderCallGuard $callGuard,
        private readonly ProviderOutcomeRecorder $providerOutcome,
        private readonly ErrorMessageRedactor $redactor,
        private readonly ContentReviewEventPublisher $events,
        private readonly DecideContentReviewAction $decide,
        private readonly ContentReviewLogContext $logContext,
    ) {}

    public function execute(string $publicId): string
    {
        $review = $this->reviews->findByPublicId($publicId);

        if ($review === null || $review->status->isTerminal() || $review->decided_at !== null) {
            return self::RESULT_SKIPPED;
        }

        $settings = $this->modes->effectiveSettings($review->subject_type);

        if (! $this->reviews->leaseForProcessing($review, (string) Str::ulid(), $this->leaseSeconds($settings))) {
            return self::RESULT_SKIPPED;
        }

        $review->refresh();

        $subject = $this->subjects->verify($review);

        if (! $subject->isReady()) {
            $this->settleUnusableSubject($review, $subject->verdict);

            return self::RESULT_PROCESSED;
        }

        $content = $subject->content;

        try {
            $policy = $this->policies->activeFor($review->subject_type);
        } catch (ContentReviewException) {
            $this->state->terminate($review, ContentReviewErrorCode::PolicyMissing, 'policy_missing');

            return self::RESULT_PROCESSED;
        }

        $checks = $this->deterministic->run($content, $policy);

        if ($this->providerOutcome->isUnavailable()) {
            return $this->defer($review, ContentReviewErrorCode::CircuitOpen, 'circuit_open');
        }

        $resolved = $this->providerResolver->resolve($settings);
        $maxOutputTokens = $settings->maxOutputTokens();
        $capacity = $this->capacity->acquire($settings, $resolved->name, $resolved->model, $maxOutputTokens);

        if ($capacity->slotUnavailable) {
            $this->state->releaseToQueue($review);

            return self::RESULT_NO_SLOT;
        }

        if (! $capacity->isGranted()) {
            $this->guardFailure($review, $checks, $capacity->refusal, 'budget_exhausted');

            return self::RESULT_PROCESSED;
        }

        $plan = $this->imagePlanner->plan($content, $policy, $settings, $resolved->name, $resolved->model, $resolved->descriptor);
        $this->state->recordImageSummary($review, $this->imagePlanner->summarize($plan, $content->imageCount(), []));

        try {
            $response = $this->callProvider($resolved, $review, $content, $policy, $settings, $maxOutputTokens, $plan);
        } catch (Throwable $exception) {
            $failure = $exception instanceof ContentReviewProviderException
                ? $exception
                : ContentReviewProviderException::of(
                    ContentReviewErrorCode::UnknownError,
                    $this->redactor->redact($exception)
                );

            $this->providerOutcome->recordFailure($settings, $failure->errorCode());

            if ($failure->metrics() !== null) {
                $this->state->recordProviderMetrics($review, $failure->metrics(), $resolved->name);
            }

            $this->handleProviderFailure($review->refresh(), $checks, $failure);

            return self::RESULT_PROCESSED;
        } finally {
            $this->capacity->release($capacity);
        }

        $this->providerOutcome->recordSuccess();

        try {
            $result = $this->validator->validate($response->payload, $policy);
        } catch (ContentReviewException) {
            $this->state->recordProviderMetrics($review, $response->metrics(), $resolved->name);
            $this->handleProviderFailure(
                $review->refresh(),
                $checks,
                ContentReviewProviderException::of(ContentReviewErrorCode::InvalidStructuredOutput)
            );

            return self::RESULT_PROCESSED;
        }

        $merged = $this->imagePlanner->record($plan, $result->imageChecks, $policy, $resolved->name, $resolved->model);

        $this->state->complete($review, $response, $result, $checks, $policy, $resolved->name, $this->renderer->version($policy));
        $this->state->recordImageSummary($review, $this->imagePlanner->summarize($plan, $content->imageCount(), $merged));

        $this->logCompleted($review->refresh());
        $this->events->publish($review->refresh(), 'content_review.completed');

        $this->decide->execute($review->refresh(), $result, $checks, $policy, null);

        return self::RESULT_PROCESSED;
    }

    private function callProvider(
        ResolvedProvider $resolved,
        ContentReview $review,
        ReviewContentDTO $content,
        ReviewPolicy $policy,
        ReviewSettings $settings,
        int $maxOutputTokens,
        ImageAnalysisPlan $plan,
    ): ProviderReviewResponse {
        $this->callGuard->assertOutsideTransaction();

        $imageContext = $plan->promptContext();

        return $resolved->provider->analyze(new ProviderReviewRequest(
            $review->subject_type,
            $this->renderer->render($policy, $content, $imageContext),
            $this->renderer->resultSchema($policy),
            $content->textBlocks,
            $content->structuredFacts,
            $plan->attachments(),
            $resolved->model,
            $maxOutputTokens,
            $settings->timeoutSeconds(),
            $policy->locales(),
            $imageContext,
            $resolved->descriptor,
        ));
    }

    /**
     * A retryable code with attempts left goes back to the queue and the exception is rethrown
     * so the worker applies its backoff. Anything else is terminal and needs a human.
     */
    private function handleProviderFailure(
        ContentReview $review,
        DeterministicCheckResult $checks,
        ContentReviewProviderException $exception,
    ): void {
        $code = $exception->errorCode();
        $message = $this->redactor->redactString($exception->getMessage());

        if ($code->isRetryable() && (int) $review->attempt < (int) $review->max_attempts) {
            $this->state->requeue($review, $checks, $code, $message);

            throw $exception;
        }

        $this->state->markFailed($review, $checks, $code, $message);

        $this->logFailure($review->refresh(), 'provider_failure');
        $this->events->publish($review->refresh(), 'content_review.failed');
        $this->decide->executeWithoutResult($review->refresh(), $checks, $code);
    }

    /**
     * The subject can no longer be reviewed as recorded. Each verdict settles differently: a
     * subject that has left review is cancelled rather than failed, and content that moved on
     * is superseded so the next submission gets its own review.
     */
    private function settleUnusableSubject(ContentReview $review, SubjectVerdict $verdict): void
    {
        match ($verdict) {
            SubjectVerdict::NotReviewable => $this->state->cancel($review, ContentReviewErrorCode::SubjectNotReviewable),
            SubjectVerdict::ContentChanged => $this->markStale($review),
            default => $this->state->terminate($review, $verdict->errorCode(), $verdict->value),
        };
    }

    private function markStale(ContentReview $review): ContentReview
    {
        $stale = $this->state->markStale($review);
        $this->events->publish($review->refresh(), 'content_review.stale');

        return $stale;
    }

    /**
     * The provider is temporarily unreachable, which says nothing about this review. Failing it
     * here would hand a human every listing queued during a short outage, so it goes back to the
     * queue instead, keeping its attempts. The worker's own retryUntil window bounds the wait:
     * if the condition outlasts it, the job's failure handler escalates as before.
     */
    private function defer(ContentReview $review, ContentReviewErrorCode $code, string $reasonCode): string
    {
        $this->state->defer($review, $code, $reasonCode);

        $refreshed = $review->refresh();
        Log::info('content_review.deferred', $this->logContext->forReview($refreshed, [
            'stage' => $reasonCode,
            'retryable' => true,
        ]));

        $this->events->publishOperational('content_review.'.$reasonCode, [
            'review_id' => (string) $refreshed->public_id,
            'error_code' => $code->value,
        ]);

        return self::RESULT_DEFERRED;
    }

    /**
     * A guard refused to spend anything, so the review never reached the provider. It still
     * needs an outcome, and the operational event is what tells an admin why.
     */
    private function guardFailure(
        ContentReview $review,
        DeterministicCheckResult $checks,
        ContentReviewErrorCode $code,
        string $reasonCode,
    ): void {
        $this->state->markFailed($review, $checks, $code, null, $reasonCode);

        $refreshed = $review->refresh();
        $this->logFailure($refreshed, $reasonCode);

        $this->events->publishOperational('content_review.'.$reasonCode, [
            'review_id' => (string) $refreshed->public_id,
            'error_code' => $code->value,
        ]);

        $this->decide->executeWithoutResult($refreshed, $checks, $code);
    }

    private function logCompleted(ContentReview $review): void
    {
        Log::info('content_review.completed', $this->logContext->forReview($review, [
            'stage' => 'analysis',
            'queue_delay_ms' => $review->queue_delay_ms === null ? null : (int) $review->queue_delay_ms,
            'images_analyzed' => (int) $review->images_analyzed,
            'image_cache_hits' => (int) $review->image_cache_hits,
        ]));
    }

    private function logFailure(ContentReview $review, string $stage): void
    {
        Log::warning('content_review.failed', $this->logContext->forReview($review, [
            'stage' => $stage,
            'attempt_limit' => (int) $review->max_attempts,
        ]));
    }

    /**
     * The lease has to outlive the worker, which in turn outlives the HTTP call, or a job still
     * running gets its row reclaimed underneath it.
     */
    private function leaseSeconds(ReviewSettings $settings): int
    {
        return max(
            $settings->timeoutSeconds() + 30,
            (int) config('content_review.sweeper.lease_seconds', 300)
        );
    }
}
