<?php

declare(strict_types=1);

namespace App\Domain\ContentReview\Exceptions;

use RuntimeException;

class ContentReviewException extends RuntimeException
{
    protected int $statusCode = 422;

    protected ?string $errorCode = null;

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }

    public static function domain(string $key, array $replace = [], int $status = 422): self
    {
        $exception = new self(__('content_review.errors.'.$key, $replace));
        $exception->errorCode = $key;
        $exception->statusCode = $status;

        return $exception;
    }
}
