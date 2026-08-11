<?php

declare(strict_types=1);

namespace App\Services\ContentReview\Actions;

use App\Domain\ContentReview\Enums\ReviewableSubjectType;
use App\Domain\ContentReview\Enums\ReviewMode;
use App\Domain\ContentReview\Enums\ReviewTrigger;
use App\Repositories\ContentReview\ContentReviewRepository;
use App\Services\ContentReview\Support\ReviewModeResolver;
use App\Services\ContentReview\Support\ReviewSubjectRegistry;

final class BackfillContentReviewsAction
{
    public function __construct(
        private readonly ReviewSubjectRegistry $registry,
        private readonly ContentReviewRepository $reviews,
        private readonly ReviewModeResolver $modes,
        private readonly RequestContentReviewAction $requests,
    ) {}

    /**
     * Never widens automation: created attempts are capped at shadow, so a historical
     * subject can be analyzed for observability but can never be auto decided.
     */
    public function execute(ReviewableSubjectType $type, int $limit, int $chunk, bool $execute): array
    {
        $result = [
            'subject_type' => $type->value,
            'dry_run' => ! $execute,
            'mode' => $this->modes->resolve($type)->value,
            'scanned' => 0,
            'eligible' => 0,
            'already_reviewed' => 0,
            'created' => 0,
            'skipped' => 0,
            'samples' => [],
        ];

        if (! $this->registry->supports($type)) {
            return $result;
        }

        $adapter = $this->registry->for($type);
        $afterId = 0;

        while ($result['eligible'] < $limit) {
            $batch = $adapter->reviewableSubjectIds($chunk, $afterId);

            if ($batch === []) {
                break;
            }

            $afterId = max($batch);
            $result['scanned'] += count($batch);
            $reviewed = $this->reviews->subjectIdsWithAnyReview($type, $batch);

            foreach ($batch as $subjectId) {
                if ($result['eligible'] >= $limit) {
                    break;
                }

                if (in_array($subjectId, $reviewed, true)) {
                    $result['already_reviewed']++;

                    continue;
                }

                $result['eligible']++;

                if (count($result['samples']) < 10) {
                    $result['samples'][] = $adapter->subjectReference($subjectId);
                }

                if (! $execute) {
                    continue;
                }

                $created = $this->requests->execute(
                    $type,
                    $subjectId,
                    ReviewTrigger::Backfill,
                    null,
                    ReviewMode::Shadow,
                );

                $created === null ? $result['skipped']++ : $result['created']++;
            }
        }

        return $result;
    }
}
