<?php

declare(strict_types=1);

namespace App\Domain\ContentReview\Enums;

enum SubjectVerdict: string
{
    case Ready = 'ready';
    case NotSupported = 'subject_type_not_supported';
    case NotReviewable = 'subject_not_reviewable';
    case ContentUnavailable = 'content_unavailable';
    case ContentChanged = 'content_changed';

    public function isReady(): bool
    {
        return $this === self::Ready;
    }

    public function errorCode(): ?ContentReviewErrorCode
    {
        return match ($this) {
            self::Ready => null,
            self::NotSupported, self::NotReviewable => ContentReviewErrorCode::SubjectNotReviewable,
            self::ContentUnavailable => ContentReviewErrorCode::ContentUnavailable,
            self::ContentChanged => ContentReviewErrorCode::ContentChanged,
        };
    }
}
