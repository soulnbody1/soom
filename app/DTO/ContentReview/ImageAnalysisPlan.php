<?php

declare(strict_types=1);

namespace App\DTO\ContentReview;

final readonly class ImageAnalysisPlan extends BaseContentReviewDTO
{
    public function __construct(
        public bool $enabled,
        public array $prepared = [],
        public array $cached = [],
        public array $pending = [],
    ) {}

    public static function disabled(): self
    {
        return new self(false);
    }

    public function attachments(): array
    {
        return array_map(static fn (PreparedImage $image): array => [
            'ref' => $image->ref,
            'mime' => (string) $image->mime,
            'bytes' => (string) $image->bytes,
            'sha256' => (string) $image->sha256,
            'sort_order' => $image->sortOrder,
        ], $this->pending);
    }

    public function promptContext(): array
    {
        $failed = array_values(array_filter(
            $this->prepared,
            static fn (PreparedImage $image): bool => ! $image->isReady()
        ));

        return [
            'attached' => array_map(static fn (PreparedImage $image): string => $image->ref, $this->pending),
            'reused' => array_map(static fn (ImageCheckResult $check): array => [
                'ref' => $check->ref,
                'verdict' => $check->verdict->value,
                'risk_level' => $check->riskLevel?->value,
            ], array_values($this->cached)),
            'failed' => array_map(static fn (PreparedImage $image): array => [
                'ref' => $image->ref,
                'failure_code' => (string) $image->failureCode,
            ], $failed),
        ];
    }

    public function toArray(): array
    {
        return [
            'enabled' => $this->enabled,
            'prepared' => count($this->prepared),
            'cached' => count($this->cached),
            'pending' => count($this->pending),
        ];
    }
}
