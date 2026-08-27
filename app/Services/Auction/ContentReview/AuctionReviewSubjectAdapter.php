<?php

declare(strict_types=1);

namespace App\Services\Auction\ContentReview;

use App\Domain\Auction\Enums\AuctionStatus;
use App\Domain\ContentReview\Enums\ContentReviewDecisionType;
use App\Domain\ContentReview\Enums\ContentReviewOutcome;
use App\Domain\ContentReview\Enums\ReviewableSubjectType;
use App\DTO\ContentReview\AutomationContext;
use App\DTO\ContentReview\ReviewContentDTO;
use App\Models\Auction\Auction;
use App\Models\User;
use App\Services\Auction\Actions\ReviewAuctionAction;
use App\Services\ContentReview\Contracts\ReviewSubjectAdapter;
use App\Services\ContentReview\Support\ContentSanitizer;
use App\Services\ContentReview\Support\ReviewModeResolver;
use Illuminate\Support\Facades\Gate;

final class AuctionReviewSubjectAdapter implements ReviewSubjectAdapter
{
    private const MAX_TEXT_CHARS = 8000;

    public function __construct(
        private readonly ReviewAuctionAction $review,
        private readonly ContentSanitizer $sanitizer,
        private readonly ReviewModeResolver $modes,
    ) {}

    public function type(): ReviewableSubjectType
    {
        return ReviewableSubjectType::Auction;
    }

    public function resolveSubjectId(string $reference): ?int
    {
        $reference = trim($reference);

        if ($reference === '') {
            return null;
        }

        $id = Auction::where('public_id', $reference)->value('id');

        if ($id === null && ctype_digit($reference)) {
            $id = Auction::whereKey((int) $reference)->value('id');
        }

        return $id === null ? null : (int) $id;
    }

    public function subjectReference(int $subjectId): ?string
    {
        $publicId = Auction::whereKey($subjectId)->value('public_id');

        return $publicId === null ? null : (string) $publicId;
    }

    public function isReviewable(int $subjectId): bool
    {
        $status = Auction::whereKey($subjectId)->value('status');

        if ($status instanceof AuctionStatus) {
            return $status === AuctionStatus::PendingReview;
        }

        return $status !== null && AuctionStatus::tryFrom((string) $status) === AuctionStatus::PendingReview;
    }

    public function reviewableSubjectIds(int $limit, int $afterId): array
    {
        return Auction::query()
            ->where('status', AuctionStatus::PendingReview->value)
            ->where('id', '>', $afterId)
            ->orderBy('id')
            ->limit(max(1, $limit))
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    public function buildContent(int $subjectId): ?ReviewContentDTO
    {
        $auction = Auction::query()->with('media')->find($subjectId);

        if ($auction === null) {
            return null;
        }

        return new ReviewContentDTO(
            ReviewableSubjectType::Auction,
            (int) $auction->id,
            $this->textBlocks($auction),
            $this->structuredFacts($auction),
            $this->images($auction),
            $this->imageSources($auction),
        );
    }

    public function automationContext(int $subjectId): AutomationContext
    {
        $auction = Auction::query()->with('media')->find($subjectId);

        if ($auction === null) {
            return AutomationContext::ineligible(['subject_missing']);
        }

        $automation = $this->automationSettings();
        $reasons = [];

        $allowed = $this->intList($automation['allowed_category_ids'] ?? []);

        if ($allowed === [] || ! in_array((int) $auction->category_id, $allowed, true)) {
            $reasons[] = 'category_not_allowed';
        }

        $cap = (int) ($automation['max_starting_amount_minor'] ?? 0);

        if ($cap <= 0 || (int) $auction->starting_amount_minor > $cap) {
            $reasons[] = 'value_above_automation_cap';
        }

        if (($automation['require_images'] ?? true) === true && $auction->media->count() === 0) {
            $reasons[] = 'required_images_missing';
        }

        if ($auction->terms_version_id === null) {
            $reasons[] = 'terms_version_missing';
        }

        if ($auction->configuration_version_id === null) {
            $reasons[] = 'configuration_version_missing';
        }

        return $reasons === [] ? AutomationContext::eligible() : AutomationContext::ineligible($reasons);
    }

    public function applyDecision(int $subjectId, ContentReviewOutcome $outcome, string $reason): bool
    {
        if (! $outcome->mutatesSubject()) {
            return false;
        }

        if (! $this->isReviewable($subjectId)) {
            return false;
        }

        if ($outcome === ContentReviewOutcome::AutoApproved) {
            $this->review->approveLocked($subjectId, null, $reason, 'ai');

            return true;
        }

        $this->review->rejectLocked($subjectId, null, $reason, 'ai');

        return true;
    }

    public function applyHumanDecision(
        int $subjectId,
        ContentReviewDecisionType $decision,
        int $adminId,
        string $reason,
    ): bool {
        if (! $this->isReviewable($subjectId)) {
            return false;
        }

        if ($decision === ContentReviewDecisionType::Approved) {
            $this->review->approveLocked($subjectId, $adminId, $reason, 'admin');

            return true;
        }

        if ($decision === ContentReviewDecisionType::Rejected) {
            $this->review->rejectLocked($subjectId, $adminId, $reason, 'admin');

            return true;
        }

        return false;
    }

    public function allowsHumanDecision(User $user, int $subjectId, ContentReviewDecisionType $decision): bool
    {
        $auction = Auction::query()->find($subjectId);

        if ($auction === null) {
            return false;
        }

        $ability = $decision === ContentReviewDecisionType::Approved ? 'approve' : 'review';

        return Gate::forUser($user)->allows($ability, $auction);
    }

    private function textBlocks(Auction $auction): array
    {
        return [
            [
                'field' => 'title',
                'locale' => 'ar',
                'value' => $this->sanitizer->sanitize((string) $auction->title, self::MAX_TEXT_CHARS),
            ],
            [
                'field' => 'description',
                'locale' => 'ar',
                'value' => $this->sanitizer->sanitize((string) $auction->description, self::MAX_TEXT_CHARS),
            ],
        ];
    }

    private function structuredFacts(Auction $auction): array
    {
        return [
            'category_id' => (int) $auction->category_id,
            'country_id' => $auction->country_id === null ? null : (int) $auction->country_id,
            'state_id' => $auction->state_id === null ? null : (int) $auction->state_id,
            'city_id' => $auction->city_id === null ? null : (int) $auction->city_id,
            'latitude' => $auction->latitude === null ? null : (string) $auction->latitude,
            'longitude' => $auction->longitude === null ? null : (string) $auction->longitude,
            'currency_code' => (string) $auction->currency_code,
            'starting_amount_minor' => (int) $auction->starting_amount_minor,
            'reserve_amount_minor' => $auction->reserve_amount_minor === null ? null : (int) $auction->reserve_amount_minor,
            'minimum_bid_increment_minor' => (int) $auction->minimum_bid_increment_minor,
            'starts_at' => $auction->starts_at?->toIso8601String(),
            'ends_at' => $auction->ends_at?->toIso8601String(),
            'terms_version_id' => $auction->terms_version_id === null ? null : (int) $auction->terms_version_id,
        ];
    }

    private function images(Auction $auction): array
    {
        return $auction->media
            ->map(fn ($medium): array => [
                'sort_order' => (int) $medium->sort_order,
                'mime_type' => (string) $medium->mime_type,
                'size_bytes' => (int) $medium->size_bytes,
                'path_sha256' => hash('sha256', (string) $medium->disk.'|'.(string) $medium->path),
                'content_revision' => $medium->updated_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    private function imageSources(Auction $auction): array
    {
        return $auction->media
            ->map(fn ($medium): array => [
                'sort_order' => (int) $medium->sort_order,
                'mime_type' => (string) $medium->mime_type,
                'size_bytes' => (int) $medium->size_bytes,
                'disk' => (string) $medium->disk,
                'path' => (string) $medium->path,
            ])
            ->values()
            ->all();
    }

    private function automationSettings(): array
    {
        $automation = $this->modes->effectiveSettings(ReviewableSubjectType::Auction)->settings['automation'] ?? [];

        return is_array($automation) ? $automation : [];
    }

    private function intList(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        return array_values(array_map(static fn ($value): int => (int) $value, $values));
    }
}
