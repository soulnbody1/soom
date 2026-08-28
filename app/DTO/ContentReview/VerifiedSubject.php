<?php

declare(strict_types=1);

namespace App\DTO\ContentReview;

use App\Domain\ContentReview\Enums\ContentReviewErrorCode;
use App\Domain\ContentReview\Enums\SubjectVerdict;

final readonly class VerifiedSubject extends BaseContentReviewDTO
{
    public function __construct(
        public SubjectVerdict $verdict,
        public ?ReviewContentDTO $content = null,
    ) {}

    public function isReady(): bool
    {
        return $this->verdict->isReady();
    }

    public function errorCode(): ?ContentReviewErrorCode
    {
        return $this->verdict->errorCode();
    }

    public function toArray(): array
    {
        return ['verdict' => $this->verdict->value];
    }
}
