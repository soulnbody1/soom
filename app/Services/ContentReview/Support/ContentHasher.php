<?php

declare(strict_types=1);

namespace App\Services\ContentReview\Support;

use App\DTO\ContentReview\ReviewContentDTO;

final class ContentHasher
{
    public function hash(array $data): string
    {
        return hash('sha256', json_encode($this->canonical($data), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    public function hashContent(ReviewContentDTO $content): string
    {
        return $this->hash($content->toHashable());
    }

    private function canonical(array $data): array
    {
        ksort($data);

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->canonical($value);
            }
        }

        return $data;
    }
}
