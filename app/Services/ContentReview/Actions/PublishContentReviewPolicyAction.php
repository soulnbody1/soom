<?php

declare(strict_types=1);

namespace App\Services\ContentReview\Actions;

use App\Domain\ContentReview\Enums\ReviewableSubjectType;
use App\Domain\ContentReview\Exceptions\ContentReviewException;
use App\Models\ContentReview\ContentReviewPolicy;
use App\Repositories\ContentReview\ContentReviewPolicyRepository;
use App\Services\Market\MarketCacheKey;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

final class PublishContentReviewPolicyAction
{
    private const LOCK_PREFIX = 'content_review:publish:policy:';

    public function __construct(
        private readonly ContentReviewPolicyRepository $policies,
        private readonly MarketCacheKey $cacheKeys,
    ) {}

    public function execute(
        ReviewableSubjectType $type,
        string $name,
        array $policy,
        string $promptVersion,
        int $resultSchemaVersion,
        ?int $creatorId,
    ): ContentReviewPolicy {
        $lock = Cache::lock($this->cacheKeys->market(self::LOCK_PREFIX, $type->value), 10);

        if (! $lock->get()) {
            throw ContentReviewException::domain('version_conflict', [], 409);
        }

        try {
            $this->policies->deactivateAll($type);

            return $this->policies->create([
                'subject_type' => $type->value,
                'version_number' => $this->policies->nextVersionNumber($type),
                'name' => $name,
                'prompt_version' => $promptVersion,
                'result_schema_version' => $resultSchemaVersion,
                'is_active' => true,
                'published_at' => Carbon::now(),
                'created_by' => $creatorId,
                'policy' => $policy,
            ]);
        } catch (QueryException) {
            throw ContentReviewException::domain('version_conflict', [], 409);
        } finally {
            $lock->release();
        }
    }
}
