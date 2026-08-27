<?php

declare(strict_types=1);

namespace App\Services\ContentReview\Support;

use App\Domain\ContentReview\Enums\ContentReviewErrorCode;
use App\Domain\ContentReview\Enums\ContentReviewOutcome;
use App\Domain\ContentReview\Enums\ContentReviewStatus;
use App\Domain\ContentReview\ValueObjects\ReviewPolicy;
use App\DTO\ContentReview\DeterministicCheckResult;
use App\DTO\ContentReview\ImageReviewSummary;
use App\DTO\ContentReview\ProviderCallMetrics;
use App\DTO\ContentReview\ProviderReviewResponse;
use App\DTO\ContentReview\StructuredReviewResult;
use App\Models\ContentReview\ContentReview;
use App\Repositories\ContentReview\ContentReviewRepository;
use Illuminate\Support\Carbon;

/**
 * Every transition a review row can make during processing, in one place.
 *
 * It writes and nothing else — no events, no logging, no decisions — so the order in which
 * those happen stays readable in the action that drives the pipeline. Its reason for existing
 * is that releasing the lease and stamping the terminal timestamps have to accompany each of
 * these transitions, and that was previously restated at every call site.
 */
final class ContentReviewStateWriter
{
    public function __construct(private readonly ContentReviewRepository $reviews) {}

    public function complete(
        ContentReview $review,
        ProviderReviewResponse $response,
        StructuredReviewResult $result,
        DeterministicCheckResult $checks,
        ReviewPolicy $policy,
        string $provider,
        string $promptVersion,
    ): ContentReview {
        return $this->reviews->update($review, $this->released([
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
            'provider' => $provider,
            'model' => $response->model,
            'prompt_version' => $promptVersion,
            'result_schema_version' => $policy->resultSchemaVersion,
            'policy_id' => $policy->policyId,
            'policy_version' => $policy->policyVersion,
            'input_tokens' => $response->inputTokens,
            'output_tokens' => $response->outputTokens,
            'cost_micros' => $response->costMicros,
            'duration_ms' => $response->latencyMs,
            'error_code' => null,
            'error_message' => null,
            'completed_at' => Carbon::now(),
        ]));
    }

    /**
     * The tokens a call already burned, recorded even when its output turned out unusable.
     */
    public function recordProviderMetrics(ContentReview $review, ProviderCallMetrics $metrics, string $provider): ContentReview
    {
        return $this->reviews->update($review, [
            'provider' => $provider,
            'model' => $metrics->model,
            'input_tokens' => $metrics->inputTokens,
            'output_tokens' => $metrics->outputTokens,
            'cost_micros' => $metrics->costMicros,
            'duration_ms' => $metrics->latencyMs,
        ]);
    }

    public function recordImageSummary(ContentReview $review, ImageReviewSummary $summary): ContentReview
    {
        return $this->reviews->update($review, [
            'images_analyzed' => min(255, $summary->analyzed),
            'image_cache_hits' => min(255, $summary->cacheHits),
            'image_review' => $summary->toArray(),
        ]);
    }

    /**
     * A retryable failure with attempts left: back to the queue, carrying the reason forward.
     */
    public function requeue(
        ContentReview $review,
        DeterministicCheckResult $checks,
        ContentReviewErrorCode $code,
        string $message,
    ): ContentReview {
        return $this->reviews->update($review, $this->released([
            'status' => ContentReviewStatus::Queued->value,
            'attempt' => (int) $review->attempt + 1,
            'error_code' => $code->value,
            'error_message' => $message,
            'deterministic_findings' => $checks->findings,
            'queued_at' => Carbon::now(),
        ]));
    }

    public function markFailed(
        ContentReview $review,
        DeterministicCheckResult $checks,
        ContentReviewErrorCode $code,
        ?string $message = null,
        ?string $reasonCode = null,
    ): ContentReview {
        $attributes = $this->released([
            'status' => ContentReviewStatus::Failed->value,
            'error_code' => $code->value,
            'deterministic_findings' => $checks->findings,
            'requires_human_review' => true,
            'completed_at' => Carbon::now(),
        ]);

        if ($message !== null) {
            $attributes['error_message'] = $message;
        }

        if ($reasonCode !== null) {
            $attributes['reason_code'] = $reasonCode;
        }

        return $this->reviews->update($review, $attributes);
    }

    /**
     * The review can never run: failed and handed to a human, with no further attempt.
     */
    public function terminate(ContentReview $review, ContentReviewErrorCode $code, string $reasonCode): ContentReview
    {
        return $this->reviews->update($review, $this->released([
            'status' => ContentReviewStatus::Failed->value,
            'outcome' => ContentReviewOutcome::EscalatedToHuman->value,
            'reason_code' => $reasonCode,
            'error_code' => $code->value,
            'requires_human_review' => true,
            'completed_at' => Carbon::now(),
            'decided_at' => Carbon::now(),
        ]));
    }

    public function cancel(ContentReview $review, ContentReviewErrorCode $code): ContentReview
    {
        return $this->reviews->update($review, $this->released([
            'status' => ContentReviewStatus::Cancelled->value,
            'outcome' => ContentReviewOutcome::NoDecision->value,
            'reason_code' => 'subject_not_reviewable',
            'error_code' => $code->value,
            'requires_human_review' => true,
            'current_marker' => null,
            'completed_at' => Carbon::now(),
            'decided_at' => Carbon::now(),
        ]));
    }

    /**
     * The content moved on while the review was in flight, so its answer is about something
     * that no longer exists.
     */
    public function markStale(ContentReview $review): ContentReview
    {
        return $this->reviews->update($review, $this->released([
            'status' => ContentReviewStatus::Superseded->value,
            'outcome' => ContentReviewOutcome::NoDecision->value,
            'reason_code' => 'content_changed',
            'error_code' => ContentReviewErrorCode::ContentChanged->value,
            'requires_human_review' => true,
            'current_marker' => null,
            'superseded_at' => Carbon::now(),
            'completed_at' => Carbon::now(),
            'decided_at' => Carbon::now(),
        ]));
    }

    /**
     * No concurrency slot was free. Nothing happened, so nothing is recorded beyond handing
     * the row back to the queue.
     */
    public function releaseToQueue(ContentReview $review): ContentReview
    {
        return $this->reviews->update($review, $this->released([
            'status' => ContentReviewStatus::Queued->value,
        ]));
    }

    /**
     * No transition out of processing may leave the lease behind, or the sweeper will keep
     * reclaiming a row that is already finished.
     */
    private function released(array $attributes): array
    {
        return array_replace($attributes, [
            'lease_owner' => null,
            'leased_until' => null,
        ]);
    }
}
