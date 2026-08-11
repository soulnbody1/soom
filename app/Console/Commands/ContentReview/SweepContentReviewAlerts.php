<?php

declare(strict_types=1);

namespace App\Console\Commands\ContentReview;

use App\Domain\ContentReview\Exceptions\ContentReviewException;
use App\Services\ContentReview\Support\ContentReviewAlertMonitor;
use App\Services\ContentReview\Support\ContentReviewLogContext;
use App\Services\ContentReview\Support\ReviewSubjectResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

final class SweepContentReviewAlerts extends Command
{
    protected $signature = 'content-review:sweep-alerts {--subject-type= : The reviewable subject type to evaluate}';

    protected $description = 'Evaluate the operational alert conditions and publish an admin alert when one turns on or clears.';

    public function handle(
        ContentReviewAlertMonitor $monitor,
        ReviewSubjectResolver $subjects,
        ContentReviewLogContext $logContext,
    ): int {
        try {
            $type = $subjects->typeOrDefault($this->stringOption('subject-type'));
        } catch (ContentReviewException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $result = $monitor->sweep($type);

        foreach ($result['alerted'] as $code) {
            Log::warning('content_review.alert', $logContext->operational([
                'alert_code' => $code,
                'alert_state' => 'active',
            ]));
        }

        foreach ($result['recovered'] as $code) {
            Log::info('content_review.alert', $logContext->operational([
                'alert_code' => $code,
                'alert_state' => 'recovered',
            ]));
        }

        $this->info(sprintf(
            'Alerts raised: %d, recovered: %d.',
            count($result['alerted']),
            count($result['recovered'])
        ));

        return self::SUCCESS;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
