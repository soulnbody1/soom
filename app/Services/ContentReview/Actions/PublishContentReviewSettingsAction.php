<?php

declare(strict_types=1);

namespace App\Services\ContentReview\Actions;

use App\Domain\ContentReview\Exceptions\ContentReviewException;
use App\Models\ContentReview\ContentReviewSetting;
use App\Repositories\ContentReview\ContentReviewSettingRepository;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

final class PublishContentReviewSettingsAction
{
    private const LOCK_PREFIX = 'content_review:publish:settings:';

    public function __construct(private readonly ContentReviewSettingRepository $settings) {}

    public function execute(string $scope, array $settings, ?int $creatorId): ContentReviewSetting
    {
        $lock = Cache::lock(self::LOCK_PREFIX.$scope, 10);

        if (! $lock->get()) {
            throw ContentReviewException::domain('version_conflict', [], 409);
        }

        try {
            $this->settings->deactivateAll($scope);

            return $this->settings->create([
                'scope' => $scope,
                'version_number' => $this->settings->nextVersionNumber($scope),
                'is_active' => true,
                'published_at' => Carbon::now(),
                'created_by' => $creatorId,
                'settings' => $settings,
            ]);
        } catch (QueryException) {
            throw ContentReviewException::domain('version_conflict', [], 409);
        } finally {
            $lock->release();
        }
    }
}
