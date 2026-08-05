<?php

declare(strict_types=1);

namespace App\Domain\ContentReview\Exceptions;

use App\Domain\ContentReview\Enums\ContentReviewErrorCode;
use Illuminate\Support\Facades\Lang;

final class ContentReviewErrorCodeCatalog
{
    private ?array $messageToCode = null;

    public function codeFor(string $message): ?string
    {
        return $this->map()[$message] ?? null;
    }

    public function labelFor(ContentReviewErrorCode $code): string
    {
        return (string) __('content_review.errors.'.$code->value);
    }

    private function map(): array
    {
        if ($this->messageToCode !== null) {
            return $this->messageToCode;
        }

        $errors = Lang::get('content_review.errors');
        $map = [];

        if (is_array($errors)) {
            foreach ($errors as $key => $message) {
                if (! is_string($message)) {
                    continue;
                }

                $map[$message] ??= (string) $key;
            }
        }

        return $this->messageToCode = $map;
    }
}
