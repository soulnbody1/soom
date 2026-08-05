<?php

declare(strict_types=1);

namespace App\Services\ContentReview\Actions;

use App\Domain\ContentReview\Enums\ReviewableSubjectType;
use App\Repositories\ContentReview\ContentReviewRepository;

final class SupersedeContentReviewAction
{
    public function __construct(private readonly ContentReviewRepository $reviews) {}

    public function execute(ReviewableSubjectType $type, int $subjectId): int
    {
        return $this->reviews->supersedeActive($type, $subjectId);
    }
}
