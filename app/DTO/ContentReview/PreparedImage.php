<?php

declare(strict_types=1);

namespace App\DTO\ContentReview;

final readonly class PreparedImage extends BaseContentReviewDTO
{
    public function __construct(
        public string $ref,
        public int $sortOrder,
        public ?string $sha256,
        public ?string $mime,
        public ?string $bytes,
        public ?int $sizeBytes,
        public bool $downscaled,
        public ?string $failureCode,
    ) {}

    public static function ready(
        string $ref,
        int $sortOrder,
        string $sha256,
        string $mime,
        string $bytes,
        bool $downscaled,
    ): self {
        return new self($ref, $sortOrder, $sha256, $mime, $bytes, strlen($bytes), $downscaled, null);
    }

    public static function failed(string $ref, int $sortOrder, string $failureCode): self
    {
        return new self($ref, $sortOrder, null, null, null, null, false, $failureCode);
    }

    public function isReady(): bool
    {
        return $this->failureCode === null && $this->sha256 !== null && $this->bytes !== null;
    }

    public function toArray(): array
    {
        return [
            'ref' => $this->ref,
            'sort_order' => $this->sortOrder,
            'mime' => $this->mime,
            'size_bytes' => $this->sizeBytes,
            'downscaled' => $this->downscaled,
            'failure_code' => $this->failureCode,
        ];
    }
}
