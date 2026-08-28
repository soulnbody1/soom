<?php

declare(strict_types=1);

namespace App\Services\ContentReview\Support;

use App\Domain\ContentReview\Enums\ReviewableSubjectType;
use App\Domain\ContentReview\Enums\SubjectVerdict;
use App\DTO\ContentReview\ReviewContentDTO;
use App\DTO\ContentReview\VerifiedSubject;
use App\Models\ContentReview\ContentReview;

final class ReviewSubjectVerifier
{
    public function __construct(
        private readonly ReviewSubjectRegistry $registry,
        private readonly ContentHasher $hasher,
    ) {}

    public function verify(ContentReview $review): VerifiedSubject
    {
        return $this->verifyAgainst($review->subject_type, (int) $review->subject_id, (string) $review->content_hash);
    }

    public function verifyAgainst(ReviewableSubjectType $type, int $subjectId, string $contentHash): VerifiedSubject
    {
        if (! $this->registry->supports($type)) {
            return new VerifiedSubject(SubjectVerdict::NotSupported);
        }

        $adapter = $this->registry->for($type);

        if (! $adapter->isReviewable($subjectId)) {
            return new VerifiedSubject(SubjectVerdict::NotReviewable);
        }

        $content = $adapter->buildContent($subjectId);

        if ($content === null) {
            return new VerifiedSubject(SubjectVerdict::ContentUnavailable);
        }

        if ($this->hasher->hashContent($content) !== $contentHash) {
            return new VerifiedSubject(SubjectVerdict::ContentChanged, $content);
        }

        return new VerifiedSubject(SubjectVerdict::Ready, $content);
    }

    public function contentFor(ReviewableSubjectType $type, int $subjectId): ?ReviewContentDTO
    {
        return $this->registry->supports($type)
            ? $this->registry->for($type)->buildContent($subjectId)
            : null;
    }
}
