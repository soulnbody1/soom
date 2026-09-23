<?php

declare(strict_types=1);

namespace App\Console\Commands\ContentReview;

use App\Domain\ContentReview\Exceptions\ContentReviewException;
use App\Services\ContentReview\Actions\BackfillContentReviewsAction;
use App\Services\ContentReview\Support\ContentReviewLogContext;
use App\Services\ContentReview\Support\ReviewSubjectResolver;
use App\Services\Market\MarketCommandRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

final class BackfillContentReviews extends Command
{
    protected $signature = 'content-review:backfill
        {--execute : Create shadow reviews instead of only reporting what is eligible}
        {--market= : Required ISO market code}
        {--subject-type= : The reviewable subject type, defaults to the only registered one}
        {--limit= : Maximum number of subjects to enqueue}
        {--chunk= : Number of subjects read per database round trip}';

    protected $description = 'Report, and optionally enqueue, shadow content reviews for content that was waiting before the feature shipped.';

    public function handle(
        BackfillContentReviewsAction $backfill,
        ReviewSubjectResolver $subjects,
        ContentReviewLogContext $logContext,
        MarketCommandRunner $markets,
    ): int {
        $market = $this->stringOption('market');
        if ($market === null) {
            $this->error('The --market option is required.');

            return self::FAILURE;
        }

        try {
            return $markets->in($market, fn (): int => $this->handleMarket($backfill, $subjects, $logContext));
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    private function handleMarket(
        BackfillContentReviewsAction $backfill,
        ReviewSubjectResolver $subjects,
        ContentReviewLogContext $logContext,
    ): int {
        $execute = (bool) $this->option('execute');

        if ($execute && config('content_review.enabled') !== true) {
            $this->error('Content review is switched off. Enable it before running the backfill with --execute.');

            return self::FAILURE;
        }

        try {
            $type = $subjects->typeOrDefault($this->stringOption('subject-type'));
        } catch (ContentReviewException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $result = $backfill->execute($type, $this->limit(), $this->chunk(), $execute);

        $this->render($result, $execute);

        Log::info('content_review.backfill', $logContext->operational([
            'dry_run' => $result['dry_run'],
            'scanned' => $result['scanned'],
            'created' => $result['created'],
            'skipped' => $result['skipped'],
        ]));

        return self::SUCCESS;
    }

    private function render(array $result, bool $execute): void
    {
        $this->line($execute ? 'Backfill executed.' : 'Backfill dry run — nothing was changed.');

        $this->table(['metric', 'value'], [
            ['subject type', $result['subject_type']],
            ['resolved mode', $result['mode']],
            ['scanned', (string) $result['scanned']],
            ['eligible', (string) $result['eligible']],
            ['already reviewed', (string) $result['already_reviewed']],
            ['created', (string) $result['created']],
            ['skipped', (string) $result['skipped']],
        ]);

        if ($result['samples'] !== []) {
            $this->line('Sample: '.implode(', ', array_filter($result['samples'])));
        }

        if (! $execute) {
            $this->line('Re-run with --execute to create shadow reviews. Automatic decisions are never applied to backfilled content.');
        }
    }

    private function limit(): int
    {
        $configured = (int) config('content_review.backfill.default_limit', 50);
        $max = max(1, (int) config('content_review.backfill.max_limit', 500));
        $requested = (int) ($this->stringOption('limit') ?? $configured);

        return max(1, min($max, $requested));
    }

    private function chunk(): int
    {
        $configured = (int) config('content_review.backfill.chunk', 100);

        return max(1, min(1000, (int) ($this->stringOption('chunk') ?? $configured)));
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }
}
