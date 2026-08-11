<?php

declare(strict_types=1);

namespace App\Services\ContentReview\Contracts;

use App\Domain\ContentReview\Enums\ContentReviewDecisionType;
use App\Domain\ContentReview\Enums\ContentReviewOutcome;
use App\Domain\ContentReview\Enums\ReviewableSubjectType;
use App\DTO\ContentReview\AutomationContext;
use App\DTO\ContentReview\ReviewContentDTO;
use App\Models\User;

interface ReviewSubjectAdapter
{
    public function type(): ReviewableSubjectType;

    public function resolveSubjectId(string $reference): ?int;

    public function subjectReference(int $subjectId): ?string;

    public function isReviewable(int $subjectId): bool;

    /**
     * @return array<int, int>
     */
    public function reviewableSubjectIds(int $limit, int $afterId): array;

    public function buildContent(int $subjectId): ?ReviewContentDTO;

    public function automationContext(int $subjectId): AutomationContext;

    public function applyDecision(int $subjectId, ContentReviewOutcome $outcome, string $reason): bool;

    public function applyHumanDecision(
        int $subjectId,
        ContentReviewDecisionType $decision,
        int $adminId,
        string $reason,
    ): bool;

    public function allowsHumanDecision(User $user, int $subjectId, ContentReviewDecisionType $decision): bool;
}
