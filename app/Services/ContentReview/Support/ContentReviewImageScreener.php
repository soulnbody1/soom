<?php

declare(strict_types=1);

namespace App\Services\ContentReview\Support;

use App\Domain\ContentReview\Enums\ImageCheckVerdict;
use App\Domain\ContentReview\Enums\ReviewRiskLevel;
use App\DTO\ContentReview\ImageCheckResult;
use App\DTO\ContentReview\PreparedImage;
use App\Repositories\ContentReview\ContentReviewImageCheckRepository;

final class ContentReviewImageScreener
{
    public function __construct(private readonly ContentReviewImageCheckRepository $checks) {}

    public function cached(
        array $prepared,
        string $provider,
        string $model,
        int $policyVersion,
        int $resultSchemaVersion,
    ): array {
        $fingerprints = [];

        foreach ($prepared as $image) {
            if ($image instanceof PreparedImage && $image->isReady()) {
                $fingerprints[] = (string) $image->sha256;
            }
        }

        if ($fingerprints === []) {
            return [];
        }

        $rows = $this->checks->findMany($fingerprints, $provider, $model, $policyVersion, $resultSchemaVersion);
        $hits = [];

        foreach ($prepared as $image) {
            if (! $image instanceof PreparedImage || ! $image->isReady()) {
                continue;
            }

            $row = $rows[(string) $image->sha256] ?? null;

            if ($row === null) {
                continue;
            }

            $hits[$image->ref] = new ImageCheckResult(
                $image->ref,
                (string) $image->sha256,
                $row->verdict,
                $row->risk_level,
                is_array($row->findings) ? $row->findings : [],
                true,
            );
        }

        return $hits;
    }

    public function pending(array $prepared, array $cached): array
    {
        $pending = [];

        foreach ($prepared as $image) {
            if (! $image instanceof PreparedImage || ! $image->isReady()) {
                continue;
            }

            if (! array_key_exists($image->ref, $cached)) {
                $pending[] = $image;
            }
        }

        return $pending;
    }

    public function record(
        array $prepared,
        array $cached,
        array $reported,
        string $provider,
        string $model,
        int $policyVersion,
        int $resultSchemaVersion,
    ): array {
        $merged = $cached;

        foreach ($this->pending($prepared, $cached) as $image) {
            $entry = $reported[$image->ref] ?? null;

            if (! is_array($entry)) {
                continue;
            }

            $verdict = ImageCheckVerdict::tryFrom((string) ($entry['verdict'] ?? ''));

            if ($verdict === null) {
                continue;
            }

            $riskLevel = ReviewRiskLevel::tryFrom((string) ($entry['risk_level'] ?? ''));
            $findings = is_array($entry['findings'] ?? null)
                ? array_values(array_map(static fn ($note): string => (string) $note, $entry['findings']))
                : [];

            $this->checks->remember(
                (string) $image->sha256,
                $provider,
                $model,
                $policyVersion,
                $resultSchemaVersion,
                $verdict,
                $riskLevel,
                $findings,
                null,
            );

            $merged[$image->ref] = new ImageCheckResult(
                $image->ref,
                (string) $image->sha256,
                $verdict,
                $riskLevel,
                $findings,
                false,
            );
        }

        return $merged;
    }
}
