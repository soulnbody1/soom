<?php

declare(strict_types=1);

namespace App\Repositories\ContentReview;

use App\Domain\ContentReview\Enums\ImageCheckVerdict;
use App\Domain\ContentReview\Enums\ReviewRiskLevel;
use App\Models\ContentReview\ContentReviewImageCheck;
use Illuminate\Support\Carbon;

final class ContentReviewImageCheckRepository
{
    public function find(
        string $imageSha256,
        string $provider,
        string $model,
        int $policyVersion,
        int $resultSchemaVersion,
    ): ?ContentReviewImageCheck {
        if ($imageSha256 === '') {
            return null;
        }

        return ContentReviewImageCheck::query()
            ->forFingerprint($imageSha256, $provider, $model, $policyVersion, $resultSchemaVersion)
            ->first();
    }

    public function findMany(
        array $imageHashes,
        string $provider,
        string $model,
        int $policyVersion,
        int $resultSchemaVersion,
    ): array {
        $hashes = array_values(array_unique(array_filter(
            array_map(static fn ($hash): string => (string) $hash, $imageHashes),
            static fn (string $hash): bool => $hash !== '',
        )));

        if ($hashes === []) {
            return [];
        }

        $rows = ContentReviewImageCheck::query()
            ->whereIn('image_sha256', $hashes)
            ->where('provider', $provider)
            ->where('model', $model)
            ->where('policy_version', $policyVersion)
            ->where('result_schema_version', $resultSchemaVersion)
            ->get();

        $found = [];

        foreach ($rows as $row) {
            $found[(string) $row->image_sha256] = $row;
        }

        return $found;
    }

    public function remember(
        string $imageSha256,
        string $provider,
        string $model,
        int $policyVersion,
        int $resultSchemaVersion,
        ImageCheckVerdict $verdict,
        ?ReviewRiskLevel $riskLevel,
        array $findings,
        ?int $costMicros,
    ): ?ContentReviewImageCheck {
        if ($imageSha256 === '' || strlen($imageSha256) !== 64) {
            return null;
        }

        $now = Carbon::now();

        ContentReviewImageCheck::query()->insertOrIgnore([
            'image_sha256' => $imageSha256,
            'provider' => $provider,
            'model' => $model,
            'policy_version' => $policyVersion,
            'result_schema_version' => $resultSchemaVersion,
            'verdict' => $verdict->value,
            'risk_level' => $riskLevel?->value,
            'findings' => json_encode($findings, JSON_UNESCAPED_UNICODE),
            'cost_micros' => $costMicros,
            'analyzed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->find($imageSha256, $provider, $model, $policyVersion, $resultSchemaVersion);
    }

    public function countForFingerprint(string $imageSha256): int
    {
        return ContentReviewImageCheck::query()->where('image_sha256', $imageSha256)->count();
    }
}
