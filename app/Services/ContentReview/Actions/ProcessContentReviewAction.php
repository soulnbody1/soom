<?php

declare(strict_types=1);

namespace App\Services\ContentReview\Actions;

use App\Domain\ContentReview\Enums\ContentReviewErrorCode;
use App\Domain\ContentReview\Exceptions\ContentReviewException;
use App\Domain\ContentReview\Exceptions\ContentReviewProviderException;
use App\Domain\ContentReview\ValueObjects\ReviewPolicy;
use App\Domain\ContentReview\ValueObjects\ReviewSettings;
use App\DTO\ContentReview\DeterministicCheckResult;
use App\DTO\ContentReview\ImageAnalysisPlan;
use App\DTO\ContentReview\ProviderModelDescriptor;
use App\DTO\ContentReview\ProviderReviewRequest;
use App\DTO\ContentReview\ProviderReviewResponse;
use App\DTO\ContentReview\ReviewContentDTO;
use App\Models\ContentReview\ContentReview;
use App\Repositories\ContentReview\ContentReviewRepository;
use App\Services\ContentReview\Contracts\ContentReviewEventPublisher;
use App\Services\ContentReview\Contracts\ContentReviewProvider;
use App\Services\ContentReview\Providers\ContentReviewProviderFactory;
use App\Services\ContentReview\Support\ContentHasher;
use App\Services\ContentReview\Support\ContentReviewBudgetGuard;
use App\Services\ContentReview\Support\ContentReviewConcurrencyLimiter;
use App\Services\ContentReview\Support\ContentReviewLogContext;
use App\Services\ContentReview\Support\ContentReviewStateWriter;
use App\Services\ContentReview\Support\DeterministicContentChecks;
use App\Services\ContentReview\Support\ErrorMessageRedactor;
use App\Services\ContentReview\Support\ImageAnalysisPlanner;
use App\Services\ContentReview\Support\ProviderCallGuard;
use App\Services\ContentReview\Support\ProviderOutcomeRecorder;
use App\Services\ContentReview\Support\ProviderSelectionResolver;
use App\Services\ContentReview\Support\ReviewModeResolver;
use App\Services\ContentReview\Support\ReviewPolicyResolver;
use App\Services\ContentReview\Support\ReviewPromptRenderer;
use App\Services\ContentReview\Support\ReviewSubjectRegistry;
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

    public function __construct(
        private readonly ContentReviewRepository $reviews,
        private readonly ContentReviewStateWriter $state,
        private readonly ReviewModeResolver $modes,
        private readonly ReviewPolicyResolver $policies,
        private readonly ReviewSubjectRegistry $registry,
        private readonly ContentHasher $hasher,
        private readonly DeterministicContentChecks $deterministic,
        private readonly ReviewPromptRenderer $renderer,
        private readonly ImageAnalysisPlanner $imagePlanner,
        private readonly StructuredReviewResultValidator $validator,
        private readonly ContentReviewProviderFactory $providers,
        private readonly ProviderSelectionResolver $selection,
        private readonly ContentReviewBudgetGuard $budget,
        private readonly ContentReviewConcurrencyLimiter $limiter,
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

        if (! $this->registry->supports($review->subject_type)) {
            $this->state->terminate($review, ContentReviewErrorCode::SubjectNotReviewable, 'subject_type_not_supported');

            return self::RESULT_PROCESSED;
        }

        $adapter = $this->registry->for($review->subject_type);
        $subjectId = (int) $review->subject_id;

        if (! $adapter->isReviewable($subjectId)) {
            $this->state->cancel($review, ContentReviewErrorCode::SubjectNotReviewable);

            return self::RESULT_PROCESSED;
        }

        $content = $adapter->buildContent($subjectId);

        if ($content === null) {
            $this->state->terminate($review, ContentReviewErrorCode::ContentUnavailable, 'content_unavailable');

            return self::RESULT_PROCESSED;
        }

        if ($this->hasher->hashContent($content) !== $review->content_hash) {
            $this->state->markStale($review);
            $this->events->publish($review->refresh(), 'content_review.stale');

            return self::RESULT_PROCESSED;
        }

        try {
            $policy = $this->policies->activeFor($review->subject_type);
        } catch (ContentReviewException) {
            $this->state->terminate($review, ContentReviewErrorCode::PolicyMissing, 'policy_missing');

            return self::RESULT_PROCESSED;
        }

        $checks = $this->deterministic->run($content, $policy);

        if ($this->providerOutcome->isUnavailable()) {
            $this->guardFailure($review, $checks, ContentReviewErrorCode::CircuitOpen, 'circuit_open');

            return self::RESULT_PROCESSED;
        }

        $provider = $this->providers->make($this->selection->provider($settings));
        $model = $this->selection->model($settings);
        $descriptor = $this->selection->descriptor($settings);
        $maxOutputTokens = $settings->maxOutputTokens();

        if ($this->budget->exhaustedPeriod($settings, $provider->name(), $model, $maxOutputTokens) !== null) {
            $this->guardFailure($review, $checks, ContentReviewErrorCode::BudgetExhausted, 'budget_exhausted');

            return self::RESULT_PROCESSED;
        }

        $slot = $this->limiter->acquire($settings);

        if ($slot === null) {
            $this->state->releaseToQueue($review);

            return self::RESULT_NO_SLOT;
        }

        $reservation = $this->budget->reserve($settings, $provider->name(), $model, $maxOutputTokens);

        if ($reservation === null) {
            $this->limiter->release($slot);
            $this->guardFailure($review, $checks, ContentReviewErrorCode::BudgetExhausted, 'budget_exhausted');

            return self::RESULT_PROCESSED;
        }

        $plan = $this->imagePlanner->plan($content, $policy, $settings, $provider->name(), $model, $descriptor);
        $this->state->recordImageSummary($review, $this->imagePlanner->summarize($plan, $content->imageCount(), []));

        try {
            $response = $this->callProvider($provider, $review, $content, $policy, $settings, $model, $descriptor, $maxOutputTokens, $plan);
        } catch (Throwable $exception) {
            $failure = $exception instanceof ContentReviewProviderException
                ? $exception
                : ContentReviewProviderException::of(
                    ContentReviewErrorCode::UnknownError,
                    $this->redactor->redact($exception)
                );

            $this->providerOutcome->recordFailure($settings, $failure->errorCode());

            if ($failure->metrics() !== null) {
                $this->state->recordProviderMetrics($review, $failure->metrics(), $provider->name());
            }

            $this->handleProviderFailure($review->refresh(), $checks, $failure);

            return self::RESULT_PROCESSED;
        } finally {
            $this->budget->release($reservation);
            $this->limiter->release($slot);
        }

        $this->providerOutcome->recordSuccess();

        try {
            $result = $this->validator->validate($response->payload, $policy);
        } catch (ContentReviewException) {
            $this->state->recordProviderMetrics($review, $response->metrics(), $provider->name());
            $this->handleProviderFailure(
                $review->refresh(),
                $checks,
                ContentReviewProviderException::of(ContentReviewErrorCode::InvalidStructuredOutput)
            );

            return self::RESULT_PROCESSED;
        }

        $merged = $this->imagePlanner->record($plan, $result->imageChecks, $policy, $provider->name(), $model);

        $this->state->complete($review, $response, $result, $checks, $policy, $provider->name(), $this->renderer->version($policy));
        $this->state->recordImageSummary($review, $this->imagePlanner->summarize($plan, $content->imageCount(), $merged));

        $this->logCompleted($review->refresh());
        $this->events->publish($review->refresh(), 'content_review.completed');

        $this->decide->execute($review->refresh(), $result, $checks, $policy, null);

        return self::RESULT_PROCESSED;
    }

    private function callProvider(
        ContentReviewProvider $provider,
        ContentReview $review,
        ReviewContentDTO $content,
        ReviewPolicy $policy,
        ReviewSettings $settings,
        string $model,
        ?ProviderModelDescriptor $descriptor,
        int $maxOutputTokens,
        ImageAnalysisPlan $plan,
    ): ProviderReviewResponse {
        $this->callGuard->assertOutsideTransaction();

        $imageContext = $plan->promptContext();

        return $provider->analyze(new ProviderReviewRequest(
            $review->subject_type,
            $this->renderer->render($policy, $content, $imageContext),
            $this->renderer->resultSchema($policy),
            $content->textBlocks,
            $content->structuredFacts,
            $plan->attachments(),
            $model,
            $maxOutputTokens,
            $settings->timeoutSeconds(),
            $policy->locales(),
            $imageContext,
            $descriptor,
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
