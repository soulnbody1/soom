<?php

declare(strict_types=1);

namespace App\Http\Resources\ContentReview;

use App\Models\ContentReview\ContentReviewPolicy;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ContentReviewPolicy
 */
final class ContentReviewPolicyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'subject_type' => $this->subject_type->value,
            'subject_type_label' => __('content_review.subject_types.'.$this->subject_type->value),
            'version_number' => (int) $this->version_number,
            'name' => $this->name,
            'prompt_version' => $this->prompt_version,
            'result_schema_version' => (int) $this->result_schema_version,
            'is_active' => (bool) $this->is_active,
            'is_in_use' => $this->whenCounted('reviews', fn (): bool => $this->reviews_count > 0),
            'published_at' => $this->published_at?->toIso8601String(),
            'created_by' => $this->relationLoaded('creator') && $this->creator !== null ? [
                'id' => $this->creator->id,
                'name' => $this->creator->name,
            ] : null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
