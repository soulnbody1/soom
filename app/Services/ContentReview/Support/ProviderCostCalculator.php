<?php

declare(strict_types=1);

namespace App\Services\ContentReview\Support;

final class ProviderCostCalculator
{
    private const TOKENS_PER_PRICING_UNIT = 1_000_000;

    public function version(): ?string
    {
        $version = config('content_review.pricing.version');

        return is_string($version) && $version !== '' ? $version : null;
    }

    public function knows(string $model): bool
    {
        return $this->rates($model) !== null;
    }

    public function costMicros(string $model, ?int $inputTokens, ?int $outputTokens): ?int
    {
        $rates = $this->rates($model);

        if ($rates === null || $inputTokens === null || $outputTokens === null) {
            return null;
        }

        if ($inputTokens < 0 || $outputTokens < 0) {
            return null;
        }

        return $this->apply($inputTokens, $rates['input']) + $this->apply($outputTokens, $rates['output']);
    }

    private function rates(string $model): ?array
    {
        $models = config('content_review.pricing.models');

        if (! is_array($models)) {
            return null;
        }

        $rates = $models[$model] ?? null;

        if (! is_array($rates) || ! isset($rates['input'], $rates['output'])) {
            return null;
        }

        if (! is_int($rates['input']) || ! is_int($rates['output'])) {
            return null;
        }

        if ($rates['input'] < 0 || $rates['output'] < 0) {
            return null;
        }

        return ['input' => $rates['input'], 'output' => $rates['output']];
    }

    private function apply(int $tokens, int $microsPerUnit): int
    {
        return intdiv(($tokens * $microsPerUnit) + intdiv(self::TOKENS_PER_PRICING_UNIT, 2), self::TOKENS_PER_PRICING_UNIT);
    }
}
