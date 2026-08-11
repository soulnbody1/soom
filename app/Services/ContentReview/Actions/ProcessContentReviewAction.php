<?php

declare(strict_types=1);

namespace App\Services\ContentReview\Actions;

use App\Domain\ContentReview\Enums\ContentReviewErrorCode;
use App\Domain\ContentReview\Enums\ContentReviewOutcome;
use App\Domain\ContentReview\Enums\ContentReviewStatus;
use App\Domain\ContentReview\Exceptions\ContentReviewException;
use App\Domain\ContentReview\Exceptions\ContentReviewProviderException;
use App\Domain\ContentReview\ValueObjects\ReviewPolicy;
use App\DTO\ContentReview\DeterministicCheckResult;
use App\DTO\ContentReview\ImageCheckResult;
use App\DTO\ContentReview\ImageReviewSummary;
use App\DTO\ContentReview\PreparedImage;
use App\DTO\ContentReview\ProviderReviewRequest;
use App\DTO\ContentReview\ProviderReviewResponse;
use App\DTO\ContentReview\ReviewContentDTO;
use App\DTO\ContentReview\StructuredReviewResult;
use App\Models\ContentReview\ContentReview;
use App\Repositories\ContentReview\ContentReviewRepository;
use App\Services\ContentReview\Contracts\ContentReviewEventPublisher;
use App\Services\ContentReview\Providers\ContentReviewProviderFactory;
use App\Services\ContentReview\Support\ContentHasher;
use App\Services\ContentReview\Support\ContentReviewBudgetGuard;
use App\Services\ContentReview\Support\ContentReviewCircuitBreaker;
use App\Services\ContentReview\Support\ContentReviewConcurrencyLimiter;
use App\Services\ContentReview\Support\ContentReviewImageLoader;
use App\Services\ContentReview\Support\ContentReviewImageScreener;
use App\Services\ContentReview\Support\DeterministicContentChecks;
use App\Services\ContentReview\Support\ErrorMessageRedactor;
use App\Services\ContentReview\Support\ProviderCallGuard;
use App\Services\ContentReview\Support\ProviderHealthJournal;
use App\Services\ContentReview\Support\ReviewModeResolver;
use App\Services\ContentReview\Support\ReviewPolicyResolver;
use App\Services\ContentReview\Support\ReviewPromptRenderer;
use App\Services\ContentReview\Support\ReviewSubjectRegistry;
use App\Services\ContentReview\Support\StructuredReviewResultValidator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Throwable;

final class ProcessContentReviewAction
{
    public const RESULT_PROCESSED = 'processed';

    public const RESULT_SKIPPED = 'skipped';

    public const RESULT_NO_SLOT = 'no_slot';

    public function __construct(
        private readonly ContentReviewRepository $reviews,
        private readonly ReviewModeResolver $modes,
        private readonly ReviewPolicyResolver $policies,
        private readonly ReviewSubjectRegistry $registry,
        private readonly ContentHasher $hasher,
        private readonly DeterministicContentChecks $deterministic,
        private readonly ReviewPromptRenderer $renderer,
        private readonly ContentReviewImageLoader $images,
        private readonly ContentReviewImageScreener $screener,
        private readonly StructuredReviewResultValidator $validator,
        private readonly ContentReviewProviderFactory $providers,
        private readonly ContentReviewCircuitBreaker $breaker,
        private readonly ContentReviewBudgetGuard $budget,
        private readonly ContentReviewConcurrencyLimiter $limiter,
        private readonly ProviderCallGuard $callGuard,
        private readonly ErrorMessageRedactor $redactor,
        private readonly ProviderHealthJournal $health,
        private readonly ContentReviewEventPublisher $events,
        private readonly DecideContentReviewAction $decide,
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
            $this->terminate($review, ContentReviewErrorCode::SubjectNotReviewable, 'subject_type_not_supported');

            return self::RESULT_PROCESSED;
        }

        $adapter = $this->registry->for($review->subject_type);
        $subjectId = (int) $review->subject_id;

        if (! $adapter->isReviewable($subjectId)) {
            $this->cancel($review, ContentReviewErrorCode::SubjectNotReviewable);

            return self::RESULT_PROCESSED;
        }

        $content = $adapter->buildContent($subjectId);

        if ($content === null) {
            $this->terminate($review, ContentReviewErrorCode::ContentUnavailable, 'content_unavailable');

            return self::RESULT_PROCESSED;
        }

        if ($this->hasher->hashContent($content) !== $review->content_hash) {
            $this->stale($review);

            return self::RESULT_PROCESSED;
        }

        try {
            $policy = $this->policies->activeFor($review->subject_type);
        } catch (ContentReviewException) {
            $this->terminate($review, ContentReviewErrorCode::PolicyMissing, 'policy_missing');

            return self::RESULT_PROCESSED;
        }

        $checks = $this->deterministic->run($content, $policy);

        if ($this->breaker->isOpen()) {
            $this->guardFailure($review, $checks, ContentReviewErrorCode::CircuitOpen, 'circuit_open');

            return self::RESULT_PROCESSED;
        }

        $model = $this->model($settings);
        $maxOutputTokens = $this->maxOutputTokens($settings);
        $exhausted = $this->budget->exhaustedPeriod($settings, $model, $maxOutputTokens);

        if ($exhausted !== null) {
            $this->guardFailure($review, $checks, ContentReviewErrorCode::BudgetExhausted, 'budget_exhausted');

            return self::RESULT_PROCESSED;
        }

        $slot = $this->limiter->acquire($settings);

        if ($slot === null) {
            $this->reviews->update($review, [
                'status' => ContentReviewStatus::Queued->value,
                'lease_owner' => null,
                'leased_until' => null,
            ]);

            return self::RESULT_NO_SLOT;
        }

        $reservation = $this->budget->reserve($settings, $model, $maxOutputTokens);

        if ($reservation === null) {
            $this->limiter->release($slot);
            $this->guardFailure($review, $checks, ContentReviewErrorCode::BudgetExhausted, 'budget_exhausted');

            return self::RESULT_PROCESSED;
        }

        $plan = $this->imagePlan($content, $policy, $settings, $model);
        $this->persistImageSummary($review, $content, $policy, $plan, []);

        try {
            $response = $this->callProvider($review, $content, $policy, $settings, $model, $maxOutputTokens, $plan);
        } catch (ContentReviewProviderException $exception) {
            $this->breaker->recordFailure($settings);
            $this->health->recordFailure($exception->errorCode());
            $this->alertIfProviderJustBecameUnavailable($settings, $exception->errorCode());
            $this->handleProviderFailure($review, $checks, $exception);

            return self::RESULT_PROCESSED;
        } catch (Throwable $exception) {
            $this->breaker->recordFailure($settings);
            $this->health->recordFailure(ContentReviewErrorCode::UnknownError);
            $this->alertIfProviderJustBecameUnavailable($settings, ContentReviewErrorCode::UnknownError);
            $this->handleProviderFailure(
                $review,
                $checks,
                ContentReviewProviderException::of(
                    ContentReviewErrorCode::UnknownError,
                    $this->redactor->redact($exception)
                )
            );

            return self::RESULT_PROCESSED;
        } finally {
            $this->budget->release($reservation);
            $this->limiter->release($slot);
        }

        $this->breaker->recordSuccess();
        $this->health->recordSuccess();

        try {
            $result = $this->validator->validate($response->payload, $policy);
        } catch (ContentReviewException) {
            $this->persistProviderMetrics($review, $response);
            $this->handleProviderFailure(
                $review->refresh(),
                $checks,
                ContentReviewProviderException::of(ContentReviewErrorCode::InvalidStructuredOutput)
            );

            return self::RESULT_PROCESSED;
        }

        $merged = $this->screener->record(
            $plan['prepared'],
            $plan['cached'],
            $result->imageChecks,
            $this->resolvedProviderName($settings),
            $model,
            $this->policyVersionKey($policy),
            $policy->resultSchemaVersion,
        );

        $this->persistResult($review, $response, $result, $checks, $policy);
        $this->persistImageSummary($review, $content, $policy, $plan, $merged);
        $this->events->publish($review->refresh(), 'content_review.completed');

        $this->decide->execute($review->refresh(), $result, $checks, $policy, null);

        return self::RESULT_PROCESSED;
    }

    private function callProvider(
        ContentReview $review,
        ReviewContentDTO $content,
        ReviewPolicy $policy,
        array $settings,
        string $model,
        int $maxOutputTokens,
        array $plan,
    ): ProviderReviewResponse {
        $this->callGuard->assertOutsideTransaction();

        $imageContext = $this->imageContext($plan);

        $request = new ProviderReviewRequest(
            $review->subject_type,
            $this->renderer->render($policy, $content, $imageContext),
            $this->renderer->resultSchema($policy),
            $content->textBlocks,
            $content->structuredFacts,
            $this->attachedImages($plan),
            $model,
            $maxOutputTokens,
            $this->timeoutSeconds($settings),
            $policy->locales(),
            $imageContext,
        );

        return $this->providers->make($this->providerName($settings))->analyze($request);
    }

    private function imagePlan(ReviewContentDTO $content, ReviewPolicy $policy, array $settings, string $model): array
    {
        if (($settings['analyze_images'] ?? true) !== true || ! $policy->analyzeImages()) {
            return ['enabled' => false, 'prepared' => [], 'cached' => [], 'pending' => []];
        }

        $prepared = $this->images->prepare($content, $policy);
        $cached = $this->screener->cached(
            $prepared,
            $this->resolvedProviderName($settings),
            $model,
            $this->policyVersionKey($policy),
            $policy->resultSchemaVersion,
        );

        return [
            'enabled' => true,
            'prepared' => $prepared,
            'cached' => $cached,
            'pending' => $this->screener->pending($prepared, $cached),
        ];
    }

    private function attachedImages(array $plan): array
    {
        return array_map(static fn (PreparedImage $image): array => [
            'ref' => $image->ref,
            'mime' => (string) $image->mime,
            'bytes' => (string) $image->bytes,
            'sha256' => (string) $image->sha256,
            'sort_order' => $image->sortOrder,
        ], $plan['pending']);
    }

    private function imageContext(array $plan): array
    {
        $failed = array_values(array_filter(
            $plan['prepared'],
            static fn (PreparedImage $image): bool => ! $image->isReady()
        ));

        return [
            'attached' => array_map(static fn (PreparedImage $image): string => $image->ref, $plan['pending']),
            'reused' => array_map(static fn (ImageCheckResult $check): array => [
                'ref' => $check->ref,
                'verdict' => $check->verdict->value,
                'risk_level' => $check->riskLevel?->value,
            ], array_values($plan['cached'])),
            'failed' => array_map(static fn (PreparedImage $image): array => [
                'ref' => $image->ref,
                'failure_code' => (string) $image->failureCode,
            ], $failed),
        ];
    }

    private function persistImageSummary(
        ContentReview $review,
        ReviewContentDTO $content,
        ReviewPolicy $policy,
        array $plan,
        array $checks,
    ): void {
        $summary = $plan['enabled'] === true
            ? ImageReviewSummary::build($content->imageCount(), $plan['prepared'], $checks)
            : ImageReviewSummary::disabled($content->imageCount());

        $this->reviews->update($review, [
            'images_analyzed' => min(255, $summary->analyzed),
            'image_review' => $summary->toArray(),
        ]);
    }

    private function resolvedProviderName(array $settings): string
    {
        return $this->providers->make($this->providerName($settings))->name();
    }

    private function policyVersionKey(ReviewPolicy $policy): int
    {
        return max(0, (int) $policy->policyVersion);
    }

    private function persistProviderMetrics(ContentReview $review, ProviderReviewResponse $response): void
    {
        $this->reviews->update($review, [
            'provider' => $this->providers->make($this->providerName($this->modes->effectiveSettings($review->subject_type)))->name(),
            'model' => $response->model,
            'input_tokens' => $response->inputTokens,
            'output_tokens' => $response->outputTokens,
            'cost_micros' => $response->costMicros,
            'duration_ms' => $response->latencyMs,
        ]);
    }

    private function persistResult(
        ContentReview $review,
        ProviderReviewResponse $response,
        StructuredReviewResult $result,
        DeterministicCheckResult $checks,
        ReviewPolicy $policy,
    ): void {
        $settings = $this->modes->effectiveSettings($review->subject_type);

        $this->reviews->update($review, [
            'status' => ContentReviewStatus::Completed->value,
            'recommendation' => $result->recommendation->value,
            'confidence' => $result->confidence,
            'risk_level' => $result->riskLevel->value,
            'requires_human_review' => true,
            'summary_ar' => $result->summaryAr,
            'summary_en' => $result->summaryEn,
            'findings' => $result->findings,
            'violations' => $result->violations,
            'missing_information' => $result->missingInformation,
            'policy_checks' => $result->policyChecks,
            'categories' => $result->categories,
            'deterministic_findings' => $checks->findings,
            'provider' => $this->providers->make($this->providerName($settings))->name(),
            'model' => $response->model,
            'prompt_version' => $this->renderer->version($policy),
            'result_schema_version' => $policy->resultSchemaVersion,
            'policy_id' => $policy->policyId,
            'policy_version' => $policy->policyVersion,
            'input_tokens' => $response->inputTokens,
            'output_tokens' => $response->outputTokens,
            'cost_micros' => $response->costMicros,
            'duration_ms' => $response->latencyMs,
            'error_code' => null,
            'error_message' => null,
            'lease_owner' => null,
            'leased_until' => null,
            'completed_at' => Carbon::now(),
        ]);
    }

    private function handleProviderFailure(
        ContentReview $review,
        DeterministicCheckResult $checks,
        ContentReviewProviderException $exception,
    ): void {
        $code = $exception->errorCode();
        $attempt = (int) $review->attempt;
        $maxAttempts = (int) $review->max_attempts;

        if ($code->isRetryable() && $attempt < $maxAttempts) {
            $this->reviews->update($review, [
                'status' => ContentReviewStatus::Queued->value,
                'attempt' => $attempt + 1,
                'error_code' => $code->value,
                'error_message' => $this->redactor->redactString($exception->getMessage()),
                'deterministic_findings' => $checks->findings,
                'lease_owner' => null,
                'leased_until' => null,
                'queued_at' => Carbon::now(),
            ]);

            throw $exception;
        }

        $this->reviews->update($review, [
            'status' => ContentReviewStatus::Failed->value,
            'error_code' => $code->value,
            'error_message' => $this->redactor->redactString($exception->getMessage()),
            'deterministic_findings' => $checks->findings,
            'requires_human_review' => true,
            'lease_owner' => null,
            'leased_until' => null,
            'completed_at' => Carbon::now(),
        ]);

        $this->events->publish($review->refresh(), 'content_review.failed');
        $this->decide->executeWithoutResult($review->refresh(), $checks, $code);
    }

    private function guardFailure(
        ContentReview $review,
        DeterministicCheckResult $checks,
        ContentReviewErrorCode $code,
        string $reasonCode,
    ): void {
        $this->reviews->update($review, [
            'status' => ContentReviewStatus::Failed->value,
            'error_code' => $code->value,
            'reason_code' => $reasonCode,
            'deterministic_findings' => $checks->findings,
            'requires_human_review' => true,
            'lease_owner' => null,
            'leased_until' => null,
            'completed_at' => Carbon::now(),
        ]);

        $refreshed = $review->refresh();

        if ($code === ContentReviewErrorCode::CircuitOpen) {
            $this->events->publishOperational('content_review.circuit_open', [
                'review_id' => (string) $refreshed->public_id,
                'error_code' => $code->value,
            ]);
        }

        if ($code === ContentReviewErrorCode::BudgetExhausted) {
            $this->events->publishOperational('content_review.budget_exhausted', [
                'review_id' => (string) $refreshed->public_id,
                'error_code' => $code->value,
            ]);
        }

        $this->decide->executeWithoutResult($refreshed, $checks, $code);
    }

    private function terminate(ContentReview $review, ContentReviewErrorCode $code, string $reasonCode): void
    {
        $this->reviews->update($review, [
            'status' => ContentReviewStatus::Failed->value,
            'outcome' => ContentReviewOutcome::EscalatedToHuman->value,
            'reason_code' => $reasonCode,
            'error_code' => $code->value,
            'requires_human_review' => true,
            'lease_owner' => null,
            'leased_until' => null,
            'completed_at' => Carbon::now(),
            'decided_at' => Carbon::now(),
        ]);
    }

    private function cancel(ContentReview $review, ContentReviewErrorCode $code): void
    {
        $this->reviews->update($review, [
            'status' => ContentReviewStatus::Cancelled->value,
            'outcome' => ContentReviewOutcome::NoDecision->value,
            'reason_code' => 'subject_not_reviewable',
            'error_code' => $code->value,
            'requires_human_review' => true,
            'current_marker' => null,
            'lease_owner' => null,
            'leased_until' => null,
            'completed_at' => Carbon::now(),
            'decided_at' => Carbon::now(),
        ]);
    }

    private function stale(ContentReview $review): void
    {
        $this->reviews->update($review, [
            'status' => ContentReviewStatus::Superseded->value,
            'outcome' => ContentReviewOutcome::NoDecision->value,
            'reason_code' => 'content_changed',
            'error_code' => ContentReviewErrorCode::ContentChanged->value,
            'requires_human_review' => true,
            'current_marker' => null,
            'superseded_at' => Carbon::now(),
            'completed_at' => Carbon::now(),
            'decided_at' => Carbon::now(),
            'lease_owner' => null,
            'leased_until' => null,
        ]);

        $this->events->publish($review->refresh(), 'content_review.stale');
    }

    private function alertIfProviderJustBecameUnavailable(array $settings, ContentReviewErrorCode $code): void
    {
        if (! $this->breaker->isOpen() || ! $this->breaker->shouldAlert($settings)) {
            return;
        }

        $this->events->publishOperational('content_review.provider_unavailable', [
            'error_code' => $code->value,
        ]);
    }

    private function providerName(array $settings): ?string
    {
        $provider = $settings['provider'] ?? null;

        return is_string($provider) && $provider !== '' ? $provider : null;
    }

    private function model(array $settings): string
    {
        $defaults = (array) config('content_review.defaults');
        $model = $settings['model'] ?? config('content_review.model');

        return is_string($model) && $model !== ''
            ? $model
            : (string) ($defaults['model'] ?? 'claude-sonnet-5');
    }

    private function maxOutputTokens(array $settings): int
    {
        $defaults = (array) config('content_review.defaults');

        return max(256, (int) ($settings['max_output_tokens'] ?? $defaults['max_output_tokens'] ?? 2000));
    }

    private function timeoutSeconds(array $settings): int
    {
        $defaults = (array) config('content_review.defaults');

        return max(5, (int) ($settings['timeout_seconds'] ?? $defaults['timeout_seconds'] ?? 45));
    }

    private function leaseSeconds(array $settings): int
    {
        $configured = (int) config('content_review.sweeper.lease_seconds', 300);

        return max($this->timeoutSeconds($settings) + 30, $configured);
    }
}
