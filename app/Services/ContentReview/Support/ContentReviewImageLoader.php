<?php

declare(strict_types=1);

namespace App\Services\ContentReview\Support;

use App\Domain\ContentReview\ValueObjects\ReviewPolicy;
use App\DTO\ContentReview\PreparedImage;
use App\DTO\ContentReview\ReviewContentDTO;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class ContentReviewImageLoader
{
    public function prepare(ReviewContentDTO $content, ReviewPolicy $policy): array
    {
        if (! $policy->analyzeImages()) {
            return [];
        }

        $prepared = [];
        $position = 0;

        foreach ($this->select($content, $policy) as $source) {
            $position++;
            $prepared[] = $this->prepareOne('img-'.$position, $position, $source, $policy);
        }

        return $prepared;
    }

    public function select(ReviewContentDTO $content, ReviewPolicy $policy): array
    {
        $limit = min($policy->maxImages(), (int) config('content_review.limits.max_images', 4));

        if ($limit <= 0) {
            return [];
        }

        $sources = [];

        foreach ($content->imageSources as $index => $source) {
            if (! is_array($source)) {
                continue;
            }

            $sources[] = ['index' => (int) $index, 'source' => $source];
        }

        usort($sources, static function (array $left, array $right): int {
            $order = (int) ($left['source']['sort_order'] ?? 0) <=> (int) ($right['source']['sort_order'] ?? 0);

            return $order !== 0 ? $order : $left['index'] <=> $right['index'];
        });

        return array_map(
            static fn (array $entry): array => $entry['source'],
            array_slice($sources, 0, $limit)
        );
    }

    private function prepareOne(string $ref, int $position, array $source, ReviewPolicy $policy): PreparedImage
    {
        $sortOrder = (int) ($source['sort_order'] ?? $position);
        $maxBytes = (int) config('content_review.limits.max_image_bytes', 2_000_000);
        $allowedMimes = (array) config('content_review.limits.allowed_image_mimes', []);
        $declaredMime = (string) ($source['mime_type'] ?? '');

        if ($allowedMimes !== [] && ! in_array($declaredMime, $allowedMimes, true)) {
            return PreparedImage::failed($ref, $sortOrder, 'unsupported_mime');
        }

        if ((int) ($source['size_bytes'] ?? 0) > $maxBytes) {
            return PreparedImage::failed($ref, $sortOrder, 'image_too_large');
        }

        $bytes = $this->read((string) ($source['disk'] ?? ''), (string) ($source['path'] ?? ''));

        if ($bytes === null || $bytes === '') {
            return PreparedImage::failed($ref, $sortOrder, 'image_unreadable');
        }

        if (strlen($bytes) > $maxBytes) {
            return PreparedImage::failed($ref, $sortOrder, 'image_too_large');
        }

        $detected = $this->detectMime($bytes);

        if ($detected === null) {
            return PreparedImage::failed($ref, $sortOrder, 'image_corrupt');
        }

        if ($allowedMimes !== [] && ! in_array($detected, $allowedMimes, true)) {
            return PreparedImage::failed($ref, $sortOrder, 'unsupported_mime');
        }

        if ($declaredMime !== '' && $detected !== $declaredMime) {
            return PreparedImage::failed($ref, $sortOrder, 'mime_mismatch');
        }

        $fingerprint = hash('sha256', $bytes);
        $downscaled = $this->downscale($bytes, $detected, $policy->imageMaxEdgePx());

        return PreparedImage::ready(
            $ref,
            $sortOrder,
            $fingerprint,
            $detected,
            $downscaled ?? $bytes,
            $downscaled !== null,
        );
    }

    private function detectMime(string $bytes): ?string
    {
        try {
            $info = @getimagesizefromstring($bytes);
        } catch (Throwable) {
            return null;
        }

        if (! is_array($info)) {
            return null;
        }

        $mime = (string) ($info['mime'] ?? '');

        return $mime === '' ? null : $mime;
    }

    private function downscale(string $bytes, string $mime, int $maxEdge): ?string
    {
        if (! function_exists('imagecreatefromstring') || ! function_exists('imagescale')) {
            return null;
        }

        try {
            $image = @imagecreatefromstring($bytes);
        } catch (Throwable) {
            return null;
        }

        if ($image === false) {
            return null;
        }

        $width = imagesx($image);
        $height = imagesy($image);

        if ($width < 1 || $height < 1 || max($width, $height) <= $maxEdge) {
            imagedestroy($image);

            return null;
        }

        $targetWidth = $width >= $height ? $maxEdge : max(1, intdiv($width * $maxEdge, $height));
        $scaled = @imagescale($image, $targetWidth);
        imagedestroy($image);

        if ($scaled === false) {
            return null;
        }

        $encoded = $this->encode($scaled, $mime);
        imagedestroy($scaled);

        return $encoded;
    }

    private function encode(mixed $image, string $mime): ?string
    {
        ob_start();

        $written = match ($mime) {
            'image/png' => function_exists('imagepng') && imagepng($image),
            'image/webp' => function_exists('imagewebp') && imagewebp($image),
            default => function_exists('imagejpeg') && imagejpeg($image, null, 82),
        };

        $encoded = (string) ob_get_clean();

        return $written && $encoded !== '' ? $encoded : null;
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
