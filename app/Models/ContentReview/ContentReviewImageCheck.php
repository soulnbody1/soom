<?php

declare(strict_types=1);

namespace App\Models\ContentReview;

use App\Domain\ContentReview\Enums\ImageCheckVerdict;
use App\Domain\ContentReview\Enums\ReviewRiskLevel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class ContentReviewImageCheck extends Model
{
    protected $table = 'content_review_image_checks';

    protected $fillable = [
        'image_sha256',
        'provider',
        'model',
        'policy_version',
        'result_schema_version',
        'verdict',
        'risk_level',
        'findings',
        'cost_micros',
        'analyzed_at',
    ];

    protected $casts = [
        'verdict' => ImageCheckVerdict::class,
        'risk_level' => ReviewRiskLevel::class,
        'findings' => 'array',
        'analyzed_at' => 'immutable_datetime',
    ];

    public function scopeForFingerprint(
        Builder $query,
        string $imageSha256,
        string $provider,
        string $model,
        int $policyVersion,
        int $resultSchemaVersion,
    ): Builder {
        return $query->where('image_sha256', $imageSha256)
            ->where('provider', $provider)
            ->where('model', $model)
            ->where('policy_version', $policyVersion)
            ->where('result_schema_version', $resultSchemaVersion);
    }
}
