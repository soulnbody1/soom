<?php

declare(strict_types=1);

namespace App\Services\Ad\Support;

final class FulltextQuerySanitizer
{
    private const MIN_TOKEN_LENGTH = 3;

    public function toBooleanMode(string $keyword): ?string
    {
        $terms = preg_split('/[^\p{L}\p{N}_]+/u', $keyword, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $usable = array_filter(
            $terms,
            static fn (string $term): bool => mb_strlen($term) >= self::MIN_TOKEN_LENGTH
        );

        if ($usable === []) {
            return null;
        }

        return implode(' ', array_map(static fn (string $term): string => '+'.$term.'*', $usable));
    }

    public function toLikePrefix(string $keyword): string
    {
        return $this->escape($keyword).'%';
    }

    public function toLikeContains(string $keyword): string
    {
        return '%'.$this->escape($keyword).'%';
    }

    private function escape(string $keyword): string
    {
        return addcslashes(trim($keyword), '%_\\');
    }
}
