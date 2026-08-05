<?php

declare(strict_types=1);

namespace App\Http\Resources\ContentReview;

use App\Models\ContentReview\ContentReviewDecision;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ContentReviewDecision
 */
final class ContentReviewDecisionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $relation = $this->relation_to_recommendation;

        return [
            'id' => $this->public_id,
            'decision' => $this->decision->value,
            'decision_label' => __('content_review.decisions.'.$this->decision->value),
            'decided_by_type' => $this->decided_by_type->value,
            'decided_by_type_label' => __('content_review.actor_types.'.$this->decided_by_type->value),
            'decided_by' => $this->relationLoaded('decidedBy') && $this->decidedBy !== null ? [
                'id' => $this->decidedBy->id,
                'name' => $this->decidedBy->name,
            ] : null,
            'relation_to_recommendation' => $relation?->value,
            'relation_label' => $relation === null ? null : __('content_review.relations.'.$relation->value),
            'ai_recommendation' => $this->ai_recommendation?->value,
            'ai_confidence' => $this->ai_confidence === null ? null : (int) $this->ai_confidence,
            'reason' => $this->reason,
            'decided_at' => $this->decided_at?->toIso8601String(),
        ];
    }
}
