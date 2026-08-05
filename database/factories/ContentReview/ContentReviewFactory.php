<?php

declare(strict_types=1);

namespace Database\Factories\ContentReview;

use App\Domain\ContentReview\Enums\ContentReviewErrorCode;
use App\Domain\ContentReview\Enums\ContentReviewOutcome;
use App\Domain\ContentReview\Enums\ContentReviewStatus;
use App\Domain\ContentReview\Enums\ReviewableSubjectType;
use App\Domain\ContentReview\Enums\ReviewMode;
use App\Domain\ContentReview\Enums\ReviewRecommendation;
use App\Domain\ContentReview\Enums\ReviewRiskLevel;
use App\Domain\ContentReview\Enums\ReviewTrigger;
use App\Models\ContentReview\ContentReview;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

final class ContentReviewFactory extends Factory
{
    protected $model = ContentReview::class;

    public function definition(): array
    {
        return [
            'subject_type' => ReviewableSubjectType::Auction->value,
            'subject_id' => 1,
            'content_hash' => hash('sha256', (string) Str::ulid()),
            'trigger' => ReviewTrigger::SubmittedForReview->value,
            'mode' => ReviewMode::AiAssisted->value,
            'status' => ContentReviewStatus::Queued->value,
            'requires_human_review' => true,
            'attempt' => 1,
            'max_attempts' => 3,
            'result_schema_version' => 1,
            'current_marker' => 1,
            'queued_at' => now(),
        ];
    }

    public function completed(): self
    {
        return $this->state(fn (): array => [
            'status' => ContentReviewStatus::Completed->value,
            'outcome' => ContentReviewOutcome::AdvisoryOnly->value,
            'reason_code' => 'assisted_mode',
            'recommendation' => ReviewRecommendation::Approve->value,
            'confidence' => 92,
            'risk_level' => ReviewRiskLevel::Low->value,
            'requires_human_review' => false,
            'summary_ar' => 'ملخص المراجعة.',
            'summary_en' => 'Review summary.',
            'violations' => [],
            'findings' => [],
            'provider' => 'fake',
            'model' => 'fake-model',
            'prompt_version' => 'v1',
            'cost_micros' => 1200,
            'duration_ms' => 850,
            'started_at' => now()->subSeconds(2),
            'completed_at' => now(),
        ]);
    }

    public function failed(): self
    {
        return $this->state(fn (): array => [
            'status' => ContentReviewStatus::Failed->value,
            'outcome' => ContentReviewOutcome::EscalatedToHuman->value,
            'reason_code' => 'provider_failure',
            'error_code' => ContentReviewErrorCode::ProviderTimeout->value,
            'error_message' => 'The review service did not respond in time.',
            'requires_human_review' => true,
            'started_at' => now()->subSeconds(45),
            'completed_at' => now(),
        ]);
    }

    public function superseded(): self
    {
        return $this->state(fn (): array => [
            'status' => ContentReviewStatus::Superseded->value,
            'current_marker' => null,
            'superseded_at' => now(),
        ]);
    }
}
