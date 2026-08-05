<?php

declare(strict_types=1);

namespace App\Models\ContentReview;

use App\Domain\ContentReview\Enums\ContentReviewDecisionType;
use App\Domain\ContentReview\Enums\DecisionActorType;
use App\Domain\ContentReview\Enums\DecisionRelation;
use App\Domain\ContentReview\Enums\ReviewableSubjectType;
use App\Domain\ContentReview\Enums\ReviewRecommendation;
use App\Models\ContentReview\Concerns\HasPublicId;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ContentReviewDecision extends Model
{
    use HasPublicId;

    public const UPDATED_AT = null;

    protected $table = 'content_review_decisions';

    protected $fillable = [
        'public_id',
        'review_id',
        'subject_type',
        'subject_id',
        'decision',
        'decided_by_type',
        'decided_by_id',
        'relation_to_recommendation',
        'ai_recommendation',
        'ai_confidence',
        'reason',
        'decided_at',
        'created_at',
    ];

    protected $casts = [
        'subject_type' => ReviewableSubjectType::class,
        'decision' => ContentReviewDecisionType::class,
        'decided_by_type' => DecisionActorType::class,
        'relation_to_recommendation' => DecisionRelation::class,
        'ai_recommendation' => ReviewRecommendation::class,
        'decided_at' => 'immutable_datetime',
        'created_at' => 'immutable_datetime',
    ];

    public function review(): BelongsTo
    {
        return $this->belongsTo(ContentReview::class, 'review_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_id');
    }

    public function labelKey(): string
    {
        return implode('|', [
            $this->decided_by_type->value,
            $this->decision->value,
            $this->relation_to_recommendation?->value ?? 'none',
        ]);
    }
}
