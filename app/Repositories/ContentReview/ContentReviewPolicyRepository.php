<?php

declare(strict_types=1);

namespace App\Repositories\ContentReview;

use App\Domain\ContentReview\Enums\ReviewableSubjectType;
use App\Models\ContentReview\ContentReviewPolicy;
use Illuminate\Support\Collection;

final class ContentReviewPolicyRepository
{
    public function active(ReviewableSubjectType $type): ?ContentReviewPolicy
    {
        return ContentReviewPolicy::where('subject_type', $type->value)
            ->where('is_active', true)
            ->orderByDesc('version_number')
            ->first();
    }

    public function all(ReviewableSubjectType $type): Collection
    {
        return ContentReviewPolicy::with('creator:id,name')
            ->where('subject_type', $type->value)
            ->orderByDesc('version_number')
            ->get();
    }

    public function allWithUsage(ReviewableSubjectType $type): Collection
    {
        return ContentReviewPolicy::with('creator:id,name')
            ->withCount('reviews')
            ->where('subject_type', $type->value)
            ->orderByDesc('version_number')
            ->get();
    }

    public function findByPublicId(string $publicId): ?ContentReviewPolicy
    {
        return ContentReviewPolicy::with('creator:id,name')->where('public_id', $publicId)->first();
    }

    public function nextVersionNumber(ReviewableSubjectType $type): int
    {
        return ((int) ContentReviewPolicy::where('subject_type', $type->value)->max('version_number')) + 1;
    }

    public function create(array $attributes): ContentReviewPolicy
    {
        return ContentReviewPolicy::create($attributes);
    }

    public function deactivateAll(ReviewableSubjectType $type): void
    {
        ContentReviewPolicy::where('subject_type', $type->value)
            ->where('is_active', true)
            ->get()
            ->each(static function (ContentReviewPolicy $policy): void {
                $policy->forceFill(['is_active' => false])->save();
            });
    }
}
