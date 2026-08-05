<?php

declare(strict_types=1);

namespace App\Services\ContentReview\Support;

use App\Domain\ContentReview\Enums\ReviewableSubjectType;
use App\Domain\ContentReview\Exceptions\ContentReviewException;
use App\Domain\ContentReview\ValueObjects\ReviewPolicy;
use App\Models\ContentReview\ContentReviewPolicy;
use App\Repositories\ContentReview\ContentReviewPolicyRepository;

final class ReviewPolicyResolver
{
    private array $cache = [];

    public function __construct(private readonly ContentReviewPolicyRepository $policies) {}

    public function activeFor(ReviewableSubjectType $type): ReviewPolicy
    {
        $record = $this->activeRecord($type);

        if ($record === null) {
            throw ContentReviewException::domain('policy_missing');
        }

        return $record->toValueObject();
    }

    public function activeRecord(ReviewableSubjectType $type): ?ContentReviewPolicy
    {
        return $this->cache[$type->value] ??= $this->policies->active($type);
    }

    public function forget(): void
    {
        $this->cache = [];
    }
}
