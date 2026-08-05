<?php

declare(strict_types=1);

namespace App\Repositories\ContentReview;

use App\Models\ContentReview\ContentReviewSetting;
use Illuminate\Support\Collection;

final class ContentReviewSettingRepository
{
    public function active(string $scope): ?ContentReviewSetting
    {
        return ContentReviewSetting::where('scope', $scope)
            ->where('is_active', true)
            ->orderByDesc('version_number')
            ->first();
    }

    public function all(string $scope): Collection
    {
        return ContentReviewSetting::with('creator:id,name')
            ->where('scope', $scope)
            ->orderByDesc('version_number')
            ->get();
    }

    public function nextVersionNumber(string $scope): int
    {
        return ((int) ContentReviewSetting::where('scope', $scope)->max('version_number')) + 1;
    }

    public function create(array $attributes): ContentReviewSetting
    {
        return ContentReviewSetting::create($attributes);
    }

    public function deactivateAll(string $scope): void
    {
        ContentReviewSetting::where('scope', $scope)
            ->where('is_active', true)
            ->get()
            ->each(static function (ContentReviewSetting $setting): void {
                $setting->forceFill(['is_active' => false])->save();
            });
    }
}
