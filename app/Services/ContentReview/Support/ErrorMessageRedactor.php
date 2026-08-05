<?php

declare(strict_types=1);

namespace App\Services\ContentReview\Support;

use Throwable;

final class ErrorMessageRedactor
{
    private const MAX_LENGTH = 500;

    private const PATTERNS = [
        '/\b(bearer|basic)\s+\S+/i',
        '/\b(sk|pk)-[A-Za-z0-9_\-]{8,}/',
        '/\b(api[-_]?key|key|token|secret|password|authorization|auth)[-_a-z0-9]*\s*[:=]\s*(\S+\s*)+/i',
        '/https?:\/\/\S+/i',
        '/#\d+\s+\/\S+/',
        '/[A-Za-z]:\\\\[^\s]+/',
        '/\/[a-z0-9_\-\/\.]{12,}\.php/i',
    ];

    public function redact(Throwable $exception): string
    {
        return $this->redactString($exception->getMessage());
    }

    public function redactString(string $message): string
    {
        foreach (self::PATTERNS as $pattern) {
            $message = (string) preg_replace($pattern, '[redacted]', $message);
        }

        $message = trim((string) preg_replace('/\s+/u', ' ', $message));

        return mb_substr($message, 0, self::MAX_LENGTH);
    }
}
