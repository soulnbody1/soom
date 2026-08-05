<?php

declare(strict_types=1);

namespace App\Services\ContentReview\Support;

use App\Domain\ContentReview\ValueObjects\ReviewPolicy;
use App\DTO\ContentReview\ReviewContentDTO;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class ContentReviewImageLoader
{
    public function load(ReviewContentDTO $content, ReviewPolicy $policy): array
    {
        if (! $policy->analyzeImages()) {
            return [];
        }

        $limit = min($policy->maxImages(), (int) config('content_review.limits.max_images', 4));
        $maxBytes = (int) config('content_review.limits.max_image_bytes', 2_000_000);
        $allowedMimes = (array) config('content_review.limits.allowed_image_mimes', []);

        $loaded = [];

        foreach ($content->imageSources as $source) {
            if (count($loaded) >= $limit) {
                break;
            }

            $mime = (string) ($source['mime_type'] ?? '');

            if ($allowedMimes !== [] && ! in_array($mime, $allowedMimes, true)) {
                continue;
            }

            if ((int) ($source['size_bytes'] ?? 0) > $maxBytes) {
                continue;
            }

            $bytes = $this->read((string) ($source['disk'] ?? ''), (string) ($source['path'] ?? ''));

            if ($bytes === null || $bytes === '' || strlen($bytes) > $maxBytes) {
                continue;
            }

            $loaded[] = [
                'mime' => $mime,
                'bytes' => $bytes,
                'sha256' => hash('sha256', $bytes),
                'sort_order' => (int) ($source['sort_order'] ?? 0),
            ];
        }

        return $loaded;
    }

    private function read(string $disk, string $path): ?string
    {
        if ($disk === '' || $path === '') {
            return null;
        }

        try {
            $filesystem = Storage::disk($disk);

            if (! $filesystem->exists($path)) {
                return null;
            }

            $contents = $filesystem->get($path);

            return is_string($contents) ? $contents : null;
        } catch (Throwable) {
            return null;
        }
    }
}
