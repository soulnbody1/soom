<?php

declare(strict_types=1);

namespace App\Jobs\ContentReview;

use App\Contracts\Market\RunsInMarket;
use App\Domain\ContentReview\Enums\ContentReviewErrorCode;
use App\Domain\ContentReview\Enums\ContentReviewStatus;
use App\DTO\ContentReview\DeterministicCheckResult;
use App\Jobs\Concerns\HasMarketJobContext;
use App\Repositories\ContentReview\ContentReviewRepository;
use App\Services\ContentReview\Actions\DecideContentReviewAction;
use App\Services\ContentReview\Actions\ProcessContentReviewAction;
use App\Services\ContentReview\Support\ContentReviewConcurrencyLimiter;
use App\Services\ContentReview\Support\ContentReviewWorkerHeartbeat;
use App\Services\ContentReview\Support\ErrorMessageRedactor;
use App\Services\ContentReview\Support\ProviderOutcomeRecorder;
use App\Support\Market\MarketContext;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Throwable;

final class ProcessContentReviewJob implements RunsInMarket, ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use HasMarketJobContext;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $uniqueFor = 900;

    public int $tries;

    public int $timeout;

    public function __construct(
        public readonly string $publicId,
        public readonly int $marketId,
        int $tries = 3,
        int $timeoutSeconds = 45,
        private readonly array $backoffSeconds = [60, 300, 900],
    ) {
        $this->tries = max(1, $tries);
        $this->timeout = max(20, $timeoutSeconds + 15);
        $this->onQueue((string) config('content_review.queue', 'content-review'));
    }

    public function marketId(): int
    {
        return $this->marketId;
    }

    public function uniqueId(): string
    {
        return $this->publicId;
    }

    public function backoff(): array
    {
        return array_map(fn (int $seconds): int => $this->jitter($seconds), $this->backoffSeconds);
    }

    public function retryUntil(): DateTimeInterface
    {
        return Carbon::now()->addMinutes(30);
    }

    public function handle(
        ProcessContentReviewAction $action,
        ContentReviewConcurrencyLimiter $limiter,
        ContentReviewWorkerHeartbeat $heartbeat,
        ProviderOutcomeRecorder $providerOutcome,
    ): void {
        $heartbeat->record();

        $delay = match ($action->execute($this->publicId)) {
            ProcessContentReviewAction::RESULT_NO_SLOT => $limiter->releaseDelaySeconds(),
            ProcessContentReviewAction::RESULT_DEFERRED => $providerOutcome->secondsUntilAvailable(),
            default => null,
        };

        if ($delay !== null) {
            $this->release($delay);
        }
    }

    public function failed(?Throwable $exception): void
    {
        app(MarketContext::class)->runInMarket($this->marketId, function () use ($exception): void {
            $reviews = app(ContentReviewRepository::class);
            $review = $reviews->findByPublicId($this->publicId);

            if ($review === null || $review->status->isTerminal() || $review->decided_at !== null) {
                return;
            }

            $code = $review->error_code ?? ContentReviewErrorCode::UnknownError;

            $reviews->update($review, [
                'status' => ContentReviewStatus::Failed->value,
                'error_code' => $code->value,
                'error_message' => $exception === null
                    ? $review->error_message
                    : app(ErrorMessageRedactor::class)->redact($exception),
                'requires_human_review' => true,
                'lease_owner' => null,
                'leased_until' => null,
                'completed_at' => Carbon::now(),
            ]);

            app(DecideContentReviewAction::class)->executeWithoutResult(
                $review->refresh(),
                new DeterministicCheckResult(is_array($review->deterministic_findings) ? $review->deterministic_findings : [], false),
                $code,
            );
        });
    }

    private function jitter(int $seconds): int
    {
        $spread = intdiv($seconds, 5);

        if ($spread <= 0) {
            return max(1, $seconds);
        }

        return max(1, $seconds + random_int(-$spread, $spread));
    }
}
