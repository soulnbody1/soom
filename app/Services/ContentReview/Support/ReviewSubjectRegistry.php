<?php

declare(strict_types=1);

namespace App\Services\ContentReview\Support;

use App\Domain\ContentReview\Enums\ReviewableSubjectType;
use App\Domain\ContentReview\Exceptions\ContentReviewException;
use App\Services\ContentReview\Contracts\ReviewSubjectAdapter;

final class ReviewSubjectRegistry
{
    private array $adapters = [];

    public function register(ReviewSubjectAdapter $adapter): void
    {
        $this->adapters[$adapter->type()->value] = $adapter;
    }

    public function for(ReviewableSubjectType $type): ReviewSubjectAdapter
    {
        $adapter = $this->adapters[$type->value] ?? null;

        if ($adapter === null) {
            throw ContentReviewException::domain('subject_type_not_supported');
        }

        return $adapter;
    }

    public function supports(ReviewableSubjectType $type): bool
    {
        return isset($this->adapters[$type->value]);
    }

    public function registered(): array
    {
        return array_keys($this->adapters);
    }
}
