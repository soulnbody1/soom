<?php

declare(strict_types=1);

namespace App\Services\Ad\ContentReview;

use App\Domain\ContentReview\Enums\ContentReviewDecisionType;
use App\Domain\ContentReview\Enums\ContentReviewOutcome;
use App\Domain\ContentReview\Enums\ReviewableSubjectType;
use App\DTO\ContentReview\AutomationContext;
use App\DTO\ContentReview\ReviewContentDTO;
use App\Models\Ad;
use App\Models\User;
use App\Services\Ad\Support\AdCacheVersion;
use App\Services\ContentReview\Contracts\ReviewSubjectAdapter;
use App\Services\ContentReview\Support\ContentSanitizer;

/** Permanent domain adapter between Ads and the Content Review bounded context. */
final readonly class AdReviewSubjectAdapter implements ReviewSubjectAdapter
{
    public function __construct(
        private ContentSanitizer $sanitizer,
        private AdCacheVersion $cache,
    ) {}

    public function type(): ReviewableSubjectType
    {
        return ReviewableSubjectType::Ad;
    }

    public function resolveSubjectId(string $reference): ?int
    {
        $reference = trim($reference);
        if ($reference === '') {
            return null;
        }

        $query = Ad::withTrashed();
        $id = $query->where('public_id', $reference)->value('id');
        if ($id === null && ctype_digit($reference)) {
            $id = Ad::withTrashed()->whereKey((int) $reference)->value('id');
        }

        return $id === null ? null : (int) $id;
    }

    public function subjectReference(int $subjectId): ?string
    {
        $value = Ad::withTrashed()->whereKey($subjectId)->value('public_id');

        return $value === null ? null : (string) $value;
    }

    public function isReviewable(int $subjectId): bool
    {
        return Ad::query()->whereKey($subjectId)->exists();
    }

    public function reviewableSubjectIds(int $limit, int $afterId): array
    {
        return Ad::query()->where('id', '>', $afterId)->orderBy('id')->limit(max(1, $limit))
            ->pluck('id')->map(static fn ($id): int => (int) $id)->all();
    }

    public function buildContent(int $subjectId): ?ReviewContentDTO
    {
        $ad = Ad::query()->with('images')->find($subjectId);
        if ($ad === null) {
            return null;
        }

        $images = $ad->images->values()->map(function ($image, int $index): array {
            $path = (string) $image->getRawOriginal('image_path');

            return [
                'sort_order' => $index,
                'mime_type' => $this->mimeFor($path),
                'size_bytes' => 0,
                'path_sha256' => hash('sha256', 'spaces|'.$path),
                'content_revision' => $image->updated_at?->toIso8601String(),
            ];
        })->all();

        $sources = $ad->images->values()->map(function ($image, int $index): array {
            $path = (string) $image->getRawOriginal('image_path');

            return [
                'sort_order' => $index,
                'mime_type' => $this->mimeFor($path),
                'size_bytes' => 0,
                'disk' => 'spaces',
                'path' => $path,
            ];
        })->all();

        return new ReviewContentDTO(
            ReviewableSubjectType::Ad,
            (int) $ad->id,
            [
                ['field' => 'title', 'locale' => 'ar', 'value' => $this->sanitizer->sanitize((string) $ad->title, 8000)],
                ['field' => 'description', 'locale' => 'ar', 'value' => $this->sanitizer->sanitize((string) $ad->description, 8000)],
            ],
            [
                'category_id' => (int) $ad->category_id,
                'country_id' => (int) $ad->country_id,
                'state_id' => (int) $ad->state_id,
                'city_id' => (int) $ad->city_id,
                'price' => (string) $ad->price,
                'currency_code' => (string) $ad->currency_code,
            ],
            $images,
            $sources,
        );
    }

    public function automationContext(int $subjectId): AutomationContext
    {
        return $this->isReviewable($subjectId)
            ? AutomationContext::eligible()
            : AutomationContext::ineligible(['subject_missing']);
    }

    public function applyDecision(int $subjectId, ContentReviewOutcome $outcome, string $reason): bool
    {
        $ad = Ad::query()->find($subjectId);
        if ($ad === null || ! $outcome->mutatesSubject()) {
            return false;
        }

        if ($outcome === ContentReviewOutcome::AutoRejected) {
            $ad->delete();
            $this->cache->bump();
        }

        return true;
    }

    public function applyHumanDecision(int $subjectId, ContentReviewDecisionType $decision, int $adminId, string $reason): bool
    {
        $ad = Ad::query()->find($subjectId);
        if ($ad === null || ! in_array($decision, [ContentReviewDecisionType::Approved, ContentReviewDecisionType::Rejected], true)) {
            return false;
        }

        if ($decision === ContentReviewDecisionType::Rejected) {
            $ad->delete();
            $this->cache->bump();
        }

        return true;
    }

    public function allowsHumanDecision(User $user, int $subjectId, ContentReviewDecisionType $decision): bool
    {
        return $user->role === 'admin' && $this->isReviewable($subjectId);
    }

    private function mimeFor(string $path): string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'png' => 'image/png',
            'webp' => 'image/webp',
            default => 'image/jpeg',
        };
    }
}
