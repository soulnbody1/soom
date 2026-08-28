<?php

declare(strict_types=1);

namespace App\Services\ContentReview\Support;

final class ProviderCostCalculator
{
    private const TOKENS_PER_PRICING_UNIT = 1_000_000;

    public function __construct(private readonly ProviderModelCatalog $catalog) {}

    public function version(): ?string
    {
        $version = config('content_review.pricing.version');

        return is_string($version) && $version !== '' ? $version : null;
    }

    public function costMicros(string $provider, string $model, ?int $inputTokens, ?int $outputTokens): ?int
    {
        $descriptor = $this->catalog->pricingDescriptor($provider, $model);

        if ($descriptor === null || $inputTokens === null || $outputTokens === null) {
            return null;
        }

        if ($inputTokens < 0 || $outputTokens < 0) {
            return null;
        }

        return $this->apply($inputTokens, $descriptor->inputMicros)
            + $this->apply($outputTokens, $descriptor->outputMicros);
    }

    private function apply(int $tokens, int $microsPerUnit): int
    {
        return intdiv(($tokens * $microsPerUnit) + intdiv(self::TOKENS_PER_PRICING_UNIT, 2), self::TOKENS_PER_PRICING_UNIT);
    }
}
