<?php

declare(strict_types=1);

namespace App\Services\ContentReview\Support;

use App\Domain\ContentReview\Enums\ReviewableSubjectType;
use App\Domain\ContentReview\Enums\SubjectVerdict;
use App\DTO\ContentReview\ReviewContentDTO;
use App\DTO\ContentReview\VerifiedSubject;
use App\Models\ContentReview\ContentReview;

/**
 * The one place that answers "is this review still about what it says it is about".
 *
 * Three callers need that answer — the pipeline before it spends anything, the automation
 * check before it lets a decision apply itself, and the admin path before it records a human
 * decision. They used to ask it separately, in the same three steps, and a change to any one of
 * them would have let a decision be applied against content the other two considered stale.
 */
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

    /**
     * The content a review was built from, regardless of whether it still matches. Used where
     * the caller only needs the current text, not a judgement about it.
     */
    public function contentFor(ReviewableSubjectType $type, int $subjectId): ?ReviewContentDTO
    {
        return $this->registry->supports($type)
            ? $this->registry->for($type)->buildContent($subjectId)
            : null;
    }
}
