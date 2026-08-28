<?php

declare(strict_types=1);

namespace App\Repositories\ContentReview;

use App\Domain\ContentReview\Enums\ContentReviewErrorCode;
use App\Domain\ContentReview\Enums\ContentReviewOutcome;
use App\Domain\ContentReview\Enums\ContentReviewStatus;
use App\Domain\ContentReview\Enums\DecisionActorType;
use App\Domain\ContentReview\Enums\DecisionRelation;
use App\DTO\ContentReview\MetricsRange;
use App\Models\ContentReview\ContentReview;
use App\Models\ContentReview\ContentReviewDecision;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

final class ContentReviewMetricsRepository
{
    private const PERCENTILE_COLUMNS = ['duration_ms', 'queue_delay_ms'];

    public function statusCounts(MetricsRange $range): array
    {
        return $this->groupedCounts($range, 'status');
    }

    public function outcomeCounts(MetricsRange $range): array
    {
        return $this->groupedCounts($range, 'outcome');
    }

    public function modeCounts(MetricsRange $range): array
    {
        return $this->groupedCounts($range, 'mode');
    }

    public function errorCodeCounts(MetricsRange $range): array
    {
        return $this->groupedCounts($range, 'error_code');
    }

    public function totals(MetricsRange $range): array
    {
        $completed = ContentReviewStatus::Completed->value;

        $row = $this->inRange($range)
            ->selectRaw('COUNT(*) as review_count')
            ->selectRaw('COALESCE(SUM(input_tokens), 0) as input_tokens')
            ->selectRaw('COALESCE(SUM(output_tokens), 0) as output_tokens')
            ->selectRaw('COALESCE(SUM(cost_micros), 0) as cost_micros')
            ->selectRaw('COALESCE(SUM(images_analyzed), 0) as images_analyzed')
            ->selectRaw('COALESCE(SUM(image_cache_hits), 0) as image_cache_hits')
            ->selectRaw('COALESCE(SUM(CASE WHEN attempt > 1 THEN 1 ELSE 0 END), 0) as retried_reviews')
            ->selectRaw('COALESCE(SUM(attempt - 1), 0) as extra_attempts')
            ->selectRaw('COALESCE(SUM(CASE WHEN status = ? AND cost_micros IS NULL THEN 1 ELSE 0 END), 0) as unpriced_reviews', [$completed])
            ->selectRaw('COALESCE(SUM(CASE WHEN status = ? AND cost_micros IS NOT NULL THEN 1 ELSE 0 END), 0) as priced_reviews', [$completed])
            ->selectRaw('COALESCE(AVG(duration_ms), 0) as average_duration_ms')
            ->selectRaw('COALESCE(AVG(queue_delay_ms), 0) as average_queue_delay_ms')
            ->first();

        return [
            'review_count' => (int) ($row->review_count ?? 0),
            'input_tokens' => (int) ($row->input_tokens ?? 0),
            'output_tokens' => (int) ($row->output_tokens ?? 0),
            'cost_micros' => (int) ($row->cost_micros ?? 0),
            'images_analyzed' => (int) ($row->images_analyzed ?? 0),
            'image_cache_hits' => (int) ($row->image_cache_hits ?? 0),
            'retried_reviews' => (int) ($row->retried_reviews ?? 0),
            'extra_attempts' => (int) ($row->extra_attempts ?? 0),
            'unpriced_reviews' => (int) ($row->unpriced_reviews ?? 0),
            'priced_reviews' => (int) ($row->priced_reviews ?? 0),
            'average_duration_ms' => (int) ($row->average_duration_ms ?? 0),
            'average_queue_delay_ms' => (int) ($row->average_queue_delay_ms ?? 0),
        ];
    }

    public function percentiles(MetricsRange $range, string $column): array
    {
        if (! in_array($column, self::PERCENTILE_COLUMNS, true)) {
            return ['sample' => 0, 'p50' => null, 'p95' => null];
        }

        $sample = $this->inRange($range)->whereNotNull($column)->count();

        if ($sample === 0) {
            return ['sample' => 0, 'p50' => null, 'p95' => null];
        }

        return [
            'sample' => $sample,
            'p50' => $this->percentileAt($range, $column, $sample, 50),
            'p95' => $this->percentileAt($range, $column, $sample, 95),
        ];
    }

    public function humanDecisionCounts(MetricsRange $range): array
    {
        $rows = ContentReviewDecision::query()
            ->where('decided_by_type', DecisionActorType::Admin->value)
            ->whereBetween('decided_at', [$range->from, $range->to])
            ->selectRaw('relation_to_recommendation as relation, COUNT(*) as aggregate')
            ->groupBy('relation_to_recommendation')
            ->pluck('aggregate', 'relation')
            ->all();

        $confirmed = (int) ($rows[DecisionRelation::Confirmed->value] ?? 0);
        $overridden = (int) ($rows[DecisionRelation::Overridden->value] ?? 0);

        return [
            'decisions' => array_sum(array_map('intval', $rows)),
            'confirmations' => $confirmed,
            'overrides' => $overridden,
            'without_recommendation' => array_sum(array_map('intval', $rows)) - $confirmed - $overridden,
        ];
    }

    public function queueSnapshot(): array
    {
        $now = Carbon::now();

        $row = ContentReview::query()
            ->whereIn('status', [ContentReviewStatus::Queued->value, ContentReviewStatus::Running->value])
            ->selectRaw('COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) as queued', [ContentReviewStatus::Queued->value])
            ->selectRaw('COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) as running', [ContentReviewStatus::Running->value])
            ->selectRaw('COALESCE(SUM(CASE WHEN status = ? AND leased_until IS NOT NULL AND leased_until <= ? THEN 1 ELSE 0 END), 0) as stale_leases', [ContentReviewStatus::Running->value, $now])
            ->selectRaw('MIN(CASE WHEN status = ? THEN queued_at ELSE NULL END) as oldest_queued_at', [ContentReviewStatus::Queued->value])
            ->first();

        $oldest = $row->oldest_queued_at ?? null;
        $oldestAt = $oldest === null ? null : Carbon::parse($oldest);

        return [
            'queued' => (int) ($row->queued ?? 0),
            'running' => (int) ($row->running ?? 0),
            'stale_leases' => (int) ($row->stale_leases ?? 0),
            'oldest_queued_at' => $oldestAt?->toIso8601String(),
            'oldest_queued_age_seconds' => $oldestAt === null ? null : max(0, (int) $now->diffInSeconds($oldestAt, true)),
        ];
    }

    public function escalationBacklog(): int
    {
        return ContentReview::query()
            ->where('outcome', ContentReviewOutcome::EscalatedToHuman->value)
            ->whereDoesntHave('decisions', function (Builder $query): void {
                $query->where('decided_by_type', DecisionActorType::Admin->value);
            })
            ->count();
    }

    public function providerAttemptsSince(Carbon $since): array
    {
        $row = ContentReview::query()
            ->where('created_at', '>=', $since)
            ->selectRaw('COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) as completed', [ContentReviewStatus::Completed->value])
            ->selectRaw('COALESCE(SUM(CASE WHEN error_code = ? THEN 1 ELSE 0 END), 0) as invalid_output', [ContentReviewErrorCode::InvalidStructuredOutput->value])
            ->first();

        $completed = (int) ($row->completed ?? 0);
        $invalid = (int) ($row->invalid_output ?? 0);

        return [
            'sample' => $completed + $invalid,
            'completed' => $completed,
            'invalid_output' => $invalid,
        ];
    }

    public function permanentProviderFailuresSince(Carbon $since): int
    {
        return ContentReview::query()
            ->where('status', ContentReviewStatus::Failed->value)
            ->where('completed_at', '>=', $since)
            ->whereIn('error_code', $this->providerErrorCodes())
            ->count();
    }

    public function unpricedSince(Carbon $since): int
    {
        return ContentReview::query()
            ->whereNotNull('completed_at')
            ->where('completed_at', '>=', $since)
            ->where('status', ContentReviewStatus::Completed->value)
            ->whereNull('cost_micros')
            ->count();
    }

    public function staleQueuedCount(Carbon $before): int
    {
        return ContentReview::query()
            ->where('status', ContentReviewStatus::Queued->value)
            ->where('queued_at', '<=', $before)
            ->count();
    }

    public function providerErrorCodes(): array
    {
        return [
            ContentReviewErrorCode::ProviderTimeout->value,
            ContentReviewErrorCode::ProviderRateLimited->value,
            ContentReviewErrorCode::ProviderUnavailable->value,
            ContentReviewErrorCode::ProviderAuthFailed->value,
            ContentReviewErrorCode::InvalidStructuredOutput->value,
            ContentReviewErrorCode::UnknownError->value,
        ];
    }

    private function percentileAt(MetricsRange $range, string $column, int $sample, int $percent): ?int
    {
        $offset = intdiv(($sample - 1) * $percent, 100);

        $value = $this->inRange($range)
            ->whereNotNull($column)
            ->orderBy($column)
            ->skip($offset)
            ->take(1)
            ->value($column);

        return $value === null ? null : (int) $value;
    }

    private function groupedCounts(MetricsRange $range, string $column): array
    {
        return $this->inRange($range)
            ->selectRaw($column.' as bucket, COUNT(*) as aggregate')
            ->groupBy($column)
            ->pluck('aggregate', 'bucket')
            ->map(static fn ($value): int => (int) $value)
            ->all();
    }

    private function inRange(MetricsRange $range): Builder
    {
        return ContentReview::query()->whereBetween('created_at', [$range->from, $range->to]);
    }
}
