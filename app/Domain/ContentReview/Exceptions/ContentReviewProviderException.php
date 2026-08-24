<?php

declare(strict_types=1);

namespace App\Domain\ContentReview\Exceptions;

use App\Domain\ContentReview\Enums\ContentReviewErrorCode;
use App\DTO\ContentReview\ProviderCallMetrics;
use RuntimeException;
use Throwable;

final class ContentReviewProviderException extends RuntimeException
{
    private function __construct(
        private readonly ContentReviewErrorCode $errorCode,
        string $message,
        ?Throwable $previous = null,
        private readonly ?ProviderCallMetrics $metrics = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function of(ContentReviewErrorCode $code, string $message = '', ?Throwable $previous = null): self
    {
        return new self($code, $message === '' ? $code->value : $message, $previous);
    }

    public static function afterCall(ContentReviewErrorCode $code, ProviderCallMetrics $metrics, string $message = ''): self
    {
        return new self($code, $message === '' ? $code->value : $message, null, $metrics);
    }

    public function metrics(): ?ProviderCallMetrics
    {
        return $this->metrics;
    }

    public function errorCode(): ContentReviewErrorCode
    {
        return $this->errorCode;
    }

    public function isRetryable(): bool
    {
        return $this->errorCode->isRetryable();
    }
}
