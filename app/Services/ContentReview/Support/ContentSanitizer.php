<?php

declare(strict_types=1);

namespace App\Services\ContentReview\Support;

final class ContentSanitizer
{
    private const DELIMITER_PATTERNS = ['<<<', '>>>', '<<<FIELD:', '<<<END>>>'];

    private const INVISIBLE_CHARACTERS = [
        "\u{200B}", "\u{200C}", "\u{200D}", "\u{200E}", "\u{200F}",
        "\u{202A}", "\u{202B}", "\u{202C}", "\u{202D}", "\u{202E}",
        "\u{2066}", "\u{2067}", "\u{2068}", "\u{2069}", "\u{FEFF}",
    ];

    public function sanitize(string $value, int $maxChars): string
    {
        $value = $this->stripInvisible($value);
        $value = $this->stripControlCharacters($value);
        $value = $this->stripDelimiters($value);
        $value = $this->normalizeWhitespace($value);

        return $this->truncate($value, $maxChars);
    }

    public function stripDelimiters(string $value): string
    {
        return str_replace(self::DELIMITER_PATTERNS, ' ', $value);
    }

    public function truncate(string $value, int $maxChars): string
    {
        if ($maxChars <= 0) {
            return '';
        }

        return mb_substr($value, 0, $maxChars);
    }

    private function stripInvisible(string $value): string
    {
        return str_replace(self::INVISIBLE_CHARACTERS, '', $value);
    }

    private function stripControlCharacters(string $value): string
    {
        return (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value);
    }

    private function normalizeWhitespace(string $value): string
    {
        return trim((string) preg_replace('/[ \t]+/u', ' ', (string) preg_replace('/(\r\n|\r|\n){3,}/u', "\n\n", $value)));
    }
}
