<?php

declare(strict_types=1);

namespace App\Services\ContentReview\Support;

use App\Domain\ContentReview\Enums\ReviewableSubjectType;
use App\Domain\ContentReview\Exceptions\ContentReviewException;

final class ReviewSubjectResolver
{
    public function __construct(private readonly ReviewSubjectRegistry $registry) {}

    public function typeOrDefault(?string $value): ReviewableSubjectType
    {
        return $value === null || $value === '' ? $this->default() : $this->type($value);
    }

    public function default(): ReviewableSubjectType
    {
        foreach (ReviewableSubjectType::cases() as $type) {
            if ($this->registry->supports($type)) {
                return $type;
            }
        }

        throw ContentReviewException::domain('subject_type_not_supported', [], 404);
    }

    public function type(string $value): ReviewableSubjectType
    {
        $type = ReviewableSubjectType::tryFrom($value);

        if ($type === null || ! $this->registry->supports($type)) {
            throw ContentReviewException::domain('subject_type_not_supported', [], 404);
        }

        return $type;
    }

    public function id(ReviewableSubjectType $type, string $reference): int
    {
        $subjectId = $this->registry->for($type)->resolveSubjectId($reference);

        if ($subjectId === null) {
            throw ContentReviewException::domain('subject_not_found', [], 404);
        }

        return $subjectId;
    }
}
