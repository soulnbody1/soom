<?php

declare(strict_types=1);

namespace App\Services\ContentReview\Support;

use App\Domain\ContentReview\ValueObjects\ReviewPolicy;
use App\Domain\ContentReview\ValueObjects\ReviewSettings;
use App\DTO\ContentReview\ImageAnalysisPlan;
use App\DTO\ContentReview\ImageReviewSummary;
use App\DTO\ContentReview\ProviderModelDescriptor;
use App\DTO\ContentReview\ReviewContentDTO;

/**
 * Decides which of a subject's images need a fresh verdict, and files the verdicts that come
 * back. The screening cache key — provider, model, policy version, schema version — is spelled
 * out once here rather than at each call site, because a lookup and its matching write have to
 * agree on it or the cache silently stops hitting.
 */
final class ImageAnalysisPlanner
{
    public function __construct(
        private readonly ContentReviewImageLoader $images,
        private readonly ContentReviewImageScreener $screener,
    ) {}

    public function plan(
        ReviewContentDTO $content,
        ReviewPolicy $policy,
        ReviewSettings $settings,
        string $provider,
        string $model,
        ?ProviderModelDescriptor $descriptor,
    ): ImageAnalysisPlan {
        $supportsImages = $descriptor === null || $descriptor->supportsImages;

        if (! $supportsImages || ! $settings->analyzeImages() || ! $policy->analyzeImages()) {
            return ImageAnalysisPlan::disabled();
        }

        $prepared = $this->images->prepare($content, $policy);
        $cached = $this->screener->cached($prepared, $provider, $model, $this->policyVersion($policy), $policy->resultSchemaVersion);

        return new ImageAnalysisPlan(
            true,
            $prepared,
            $cached,
            $this->screener->pending($prepared, $cached),
        );
    }

    /**
     * @return array<string, \App\DTO\ContentReview\ImageCheckResult>
     */
    public function record(ImageAnalysisPlan $plan, array $reported, ReviewPolicy $policy, string $provider, string $model): array
    {
        return $this->screener->record(
            $plan->prepared,
            $plan->cached,
            $reported,
            $provider,
            $model,
            $this->policyVersion($policy),
            $policy->resultSchemaVersion,
        );
    }

    public function summarize(ImageAnalysisPlan $plan, int $imageCount, array $checks): ImageReviewSummary
    {
        return $plan->enabled
            ? ImageReviewSummary::build($imageCount, $plan->prepared, $checks)
            : ImageReviewSummary::disabled($imageCount);
    }

    private function policyVersion(ReviewPolicy $policy): int
    {
        return max(0, (int) $policy->policyVersion);
    }
}
