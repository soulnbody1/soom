<?php

declare(strict_types=1);

namespace App\Services\ContentReview\Support;

use App\Domain\ContentReview\Enums\ReviewableSubjectType;
use App\Domain\ContentReview\Enums\ReviewMode;
use App\Domain\ContentReview\ValueObjects\ReviewSettings;
use App\Models\ContentReview\ContentReviewSetting;
use App\Repositories\ContentReview\ContentReviewSettingRepository;

final class ReviewModeResolver
{
    private const GLOBAL_SCOPE = 'global';

    public function __construct(private readonly ContentReviewSettingRepository $settings) {}

    public function resolve(ReviewableSubjectType $type): ReviewMode
    {
        if (config('content_review.enabled') !== true) {
            return ReviewMode::Manual;
        }

        $scoped = $this->settings->active($type->value);

        if ($scoped !== null) {
            return $this->modeFrom($scoped);
        }

        $global = $this->settings->active(self::GLOBAL_SCOPE);

        if ($global !== null) {
            return $this->modeFrom($global);
        }

        return ReviewMode::tryFrom((string) config('content_review.default_mode', 'manual')) ?? ReviewMode::Manual;
    }

    public function activeSettings(ReviewableSubjectType $type): ?ContentReviewSetting
    {
        return $this->settings->active($type->value) ?? $this->settings->active(self::GLOBAL_SCOPE);
    }

    public function effectiveSettings(ReviewableSubjectType $type): ReviewSettings
    {
        $active = $this->activeSettings($type);

        return ReviewSettings::merge(
            (array) config('content_review.defaults'),
            $active === null ? [] : (array) $active->settings
        );
    }

    private function modeFrom(ContentReviewSetting $setting): ReviewMode
    {
        if (! $setting->isEnabled()) {
            return ReviewMode::Manual;
        }

        return $setting->mode() ?? ReviewMode::Manual;
    }
}
