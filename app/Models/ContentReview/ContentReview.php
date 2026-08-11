<?php

declare(strict_types=1);

namespace App\Models\ContentReview;

use App\Domain\ContentReview\Enums\ContentReviewErrorCode;
use App\Domain\ContentReview\Enums\ContentReviewOutcome;
use App\Domain\ContentReview\Enums\ContentReviewStatus;
use App\Domain\ContentReview\Enums\ReviewableSubjectType;
use App\Domain\ContentReview\Enums\ReviewMode;
use App\Domain\ContentReview\Enums\ReviewRecommendation;
use App\Domain\ContentReview\Enums\ReviewRiskLevel;
use App\Domain\ContentReview\Enums\ReviewTrigger;
use App\Models\ContentReview\Concerns\HasPublicId;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class ContentReview extends Model
{
    use HasFactory;
    use HasPublicId;

    protected $table = 'content_reviews';

    protected $fillable = [
        'public_id',
        'subject_type',
        'subject_id',
        'content_hash',
        'trigger',
        'mode',
        'status',
        'outcome',
        'reason_code',
        'recommendation',
        'confidence',
        'risk_level',
        'requires_human_review',
        'summary_ar',
        'summary_en',
        'findings',
        'violations',
        'missing_information',
        'policy_checks',
        'categories',
        'deterministic_findings',
        'provider',
        'model',
        'prompt_version',
        'result_schema_version',
        'policy_id',
        'policy_version',
        'settings_version',
        'attempt',
        'max_attempts',
        'error_code',
        'error_message',
        'input_tokens',
        'output_tokens',
        'cost_micros',
        'duration_ms',
        'queue_delay_ms',
        'image_count',
        'images_analyzed',
        'image_cache_hits',
        'image_review',
        'requested_by',
        'lease_owner',
        'leased_until',
        'current_marker',
        'queued_at',
        'started_at',
        'completed_at',
        'decided_at',
        'superseded_at',
    ];

    protected $casts = [
        'subject_type' => ReviewableSubjectType::class,
        'trigger' => ReviewTrigger::class,
        'mode' => ReviewMode::class,
        'status' => ContentReviewStatus::class,
        'outcome' => ContentReviewOutcome::class,
        'recommendation' => ReviewRecommendation::class,
        'risk_level' => ReviewRiskLevel::class,
        'error_code' => ContentReviewErrorCode::class,
        'requires_human_review' => 'boolean',
        'findings' => 'array',
        'violations' => 'array',
        'missing_information' => 'array',
        'policy_checks' => 'array',
        'categories' => 'array',
        'deterministic_findings' => 'array',
        'image_review' => 'array',
        'leased_until' => 'immutable_datetime',
        'queued_at' => 'immutable_datetime',
        'started_at' => 'immutable_datetime',
        'completed_at' => 'immutable_datetime',
        'decided_at' => 'immutable_datetime',
        'superseded_at' => 'immutable_datetime',
    ];

    public function decisions(): HasMany
    {
        return $this->hasMany(ContentReviewDecision::class, 'review_id')->orderBy('decided_at');
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(ContentReviewPolicy::class, 'policy_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('current_marker', 1);
    }

    public function scopeForSubject(Builder $query, ReviewableSubjectType $type, int $subjectId): Builder
    {
        return $query->where('subject_type', $type->value)->where('subject_id', $subjectId);
    }

    public function isPending(): bool
    {
        return $this->status->isPending();
    }

    public function isDecided(): bool
    {
        return $this->decided_at !== null;
    }

    public function hasResult(): bool
    {
        return $this->status === ContentReviewStatus::Completed && $this->recommendation !== null;
    }
}
