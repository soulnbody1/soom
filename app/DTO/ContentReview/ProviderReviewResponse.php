<?php

declare(strict_types=1);

namespace App\DTO\ContentReview;

final readonly class ProviderReviewResponse extends BaseContentReviewDTO
{
    public function __construct(
        public array $payload,
        public string $model,
        public ?int $inputTokens,
        public ?int $outputTokens,
        public ?int $costMicros,
        public int $latencyMs,
        public ?string $providerRequestId,
    ) {}

    public function toArray(): array
    {
        return [
            'model' => $this->model,
            'input_tokens' => $this->inputTokens,
            'output_tokens' => $this->outputTokens,
            'cost_micros' => $this->costMicros,
            'latency_ms' => $this->latencyMs,
        ];
    }
}
