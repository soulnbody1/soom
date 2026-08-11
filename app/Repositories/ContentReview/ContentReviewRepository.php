<?php

declare(strict_types=1);

namespace App\Repositories\ContentReview;

use App\Domain\ContentReview\Enums\ContentReviewStatus;
use App\Domain\ContentReview\Enums\ReviewableSubjectType;
use App\Models\ContentReview\ContentReview;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

final class ContentReviewRepository
{
    public function create(array $attributes): ContentReview
    {
        return ContentReview::create($attributes);
    }

    public function findByPublicId(string $publicId): ?ContentReview
    {
        return ContentReview::where('public_id', $publicId)->first();
    }

    public function activeForSubject(ReviewableSubjectType $type, int $subjectId): ?ContentReview
    {
        return ContentReview::forSubject($type, $subjectId)->active()->first();
    }

    public function historyForSubject(ReviewableSubjectType $type, int $subjectId, int $perPage): LengthAwarePaginator
    {
        return ContentReview::forSubject($type, $subjectId)
            ->with('decisions.decidedBy:id,name')
            ->latest('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * A unique index covers (subject_type, subject_id, content_hash, attempt), so a
     * re-run of unchanged content has to continue the attempt sequence rather than
     * restart it.
     */
    public function nextAttemptForContent(ReviewableSubjectType $type, int $subjectId, string $contentHash): int
    {
        $highest = ContentReview::forSubject($type, $subjectId)
            ->where('content_hash', $contentHash)
            ->max('attempt');

        return ((int) $highest) + 1;
    }

    public function deactivate(ContentReview $review): ContentReview
    {
        return $this->update($review, ['current_marker' => null]);
    }

    public function deactivateAllForSubject(ReviewableSubjectType $type, int $subjectId): int
    {
        return ContentReview::forSubject($type, $subjectId)->active()->update(['current_marker' => null]);
    }

    public function supersedeActive(ReviewableSubjectType $type, int $subjectId): int
    {
        return ContentReview::forSubject($type, $subjectId)
            ->active()
            ->update([
                'current_marker' => null,
                'superseded_at' => Carbon::now(),
                'status' => ContentReviewStatus::Superseded->value,
            ]);
    }

    public function lockForProcessing(int $id): ?ContentReview
    {
        return ContentReview::whereKey($id)->lockForUpdate()->first();
    }

    public function lockByPublicId(string $publicId): ?ContentReview
    {
        return ContentReview::where('public_id', $publicId)->lockForUpdate()->first();
    }

    public function lockActiveForSubject(ReviewableSubjectType $type, int $subjectId): ?ContentReview
    {
        return ContentReview::forSubject($type, $subjectId)->active()->lockForUpdate()->first();
    }

    public function leaseForProcessing(ContentReview $review, string $worker, int $leaseSeconds): bool
    {
        $now = Carbon::now();

        $attributes = [
            'status' => ContentReviewStatus::Running->value,
            'lease_owner' => $worker,
            'leased_until' => $now->copy()->addSeconds($leaseSeconds),
            'started_at' => $review->started_at ?? $now,
            'updated_at' => $now,
        ];

        if ($review->started_at === null && $review->queued_at !== null) {
            $attributes['queue_delay_ms'] = max(0, (int) $review->queued_at->diffInMilliseconds($now, true));
        }

        $claimed = ContentReview::whereKey($review->id)
            ->whereIn('status', [ContentReviewStatus::Queued->value, ContentReviewStatus::Running->value])
            ->where(function ($query) use ($now): void {
                $query->whereNull('leased_until')->orWhere('leased_until', '<=', $now);
            })
            ->update($attributes);

        return $claimed === 1;
    }

    public function dueForDispatch(int $limit, int $requeueAfterSeconds): Collection
    {
        $now = Carbon::now();
        $staleQueued = $now->copy()->subSeconds($requeueAfterSeconds);

        return ContentReview::query()
            ->where(function ($query) use ($staleQueued, $now): void {
                $query->where(function ($inner) use ($staleQueued): void {
                    $inner->where('status', ContentReviewStatus::Queued->value)
                        ->where('queued_at', '<=', $staleQueued);
                })->orWhere(function ($inner) use ($now): void {
                    $inner->where('status', ContentReviewStatus::Running->value)
                        ->whereNotNull('leased_until')
                        ->where('leased_until', '<=', $now);
                });
            })
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    public function update(ContentReview $review, array $attributes): ContentReview
    {
        $review->forceFill($attributes)->save();

        return $review;
    }

    public function countByStatus(ContentReviewStatus $status): int
    {
        return ContentReview::where('status', $status->value)->count();
    }

    public function oldestQueuedAt(): ?Carbon
    {
        $value = ContentReview::where('status', ContentReviewStatus::Queued->value)->min('queued_at');

        return $value === null ? null : Carbon::parse($value);
    }

    public function expiredLeases(Carbon $now, int $limit): Collection
    {
        return ContentReview::where('status', ContentReviewStatus::Running->value)
            ->whereNotNull('leased_until')
            ->where('leased_until', '<=', $now)
            ->orderBy('id')
            ->limit(max(1, $limit))
            ->get();
    }

    public function terminalRowsStillMarkedActive(int $limit): Collection
    {
        return ContentReview::whereIn('status', [
            ContentReviewStatus::Cancelled->value,
            ContentReviewStatus::Superseded->value,
        ])
            ->whereNotNull('current_marker')
            ->orderBy('id')
            ->limit(max(1, $limit))
            ->get();
    }

    public function completedWithoutApplicationStateCount(Carbon $before): int
    {
        return ContentReview::where('status', ContentReviewStatus::Completed->value)
            ->whereNull('outcome')
            ->whereNull('decided_at')
            ->where('completed_at', '<=', $before)
            ->count();
    }

    public function subjectsWithMultipleActiveReviews(): int
    {
        return ContentReview::query()
            ->active()
            ->selectRaw('subject_type, subject_id')
            ->groupBy('subject_type', 'subject_id')
            ->havingRaw('COUNT(*) > 1')
            ->get()
            ->count();
    }

    public function totalCostMicrosSince(Carbon $since): int
    {
        return (int) ContentReview::where('created_at', '>=', $since)->sum('cost_micros');
    }

    /**
     * @param  array<int, int>  $subjectIds
     * @return array<int, int>
     */
    public function subjectIdsWithAnyReview(ReviewableSubjectType $type, array $subjectIds): array
    {
        if ($subjectIds === []) {
            return [];
        }

        return ContentReview::where('subject_type', $type->value)
            ->whereIn('subject_id', $subjectIds)
            ->distinct()
            ->pluck('subject_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    public function pendingForSubject(ReviewableSubjectType $type, int $subjectId): Collection
    {
        return ContentReview::forSubject($type, $subjectId)
            ->whereIn('status', [ContentReviewStatus::Queued->value, ContentReviewStatus::Running->value])
            ->get();
    }
}
