<?php

declare(strict_types=1);

namespace Tests\Feature\Auction;

use App\Domain\Auction\Enums\AuctionStatus;
use App\Domain\ContentReview\Enums\ContentReviewOutcome;
use App\Domain\ContentReview\Enums\ContentReviewStatus;
use App\Domain\ContentReview\Enums\DecisionActorType;
use App\Domain\ContentReview\Enums\ReviewableSubjectType;
use App\Domain\ContentReview\Enums\ReviewMode;
use App\Domain\ContentReview\Enums\ReviewRecommendation;
use App\Domain\ContentReview\Enums\ReviewRiskLevel;
use App\Models\Auction\Auction;
use App\Models\Auction\AuctionConfigurationSnapshot;
use App\Models\Auction\AuctionDeposit;
use App\Models\Auction\AuctionStatusHistory;
use App\Models\ContentReview\ContentReview;
use App\Models\ContentReview\ContentReviewDecision;
use App\Models\User;
use App\Services\ContentReview\Support\ContentHasher;
use App\Services\ContentReview\Support\ReviewSubjectRegistry;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Process;
use Tests\Feature\ContentReview\Concerns\BuildsContentReviewFixtures;
use Tests\TestCase;

#[Group('mysql-concurrency')]
final class ContentReviewDecisionConcurrencyMysqlTest extends TestCase
{
    use BuildsContentReviewFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('MySQL-only content review decision concurrency test.');
        }

        if (! str_ends_with((string) DB::connection()->getDatabaseName(), '_testing')) {
            $this->markTestSkipped('Refusing to run decision concurrency test outside a *_testing database.');
        }

        Artisan::call('migrate', ['--force' => true]);
        config()->set('content_review.enabled', true);
        Queue::fake();
    }

    public function test_two_admins_confirming_at_once_produce_one_decision(): void
    {
        [$review, $auction] = $this->assistedFixture();

        $outcomes = $this->race(
            ['human', (string) $review->public_id, (string) $this->overrider()->id, 'approve', 'Confirming.'],
            ['human', (string) $review->public_id, (string) $this->overrider()->id, 'approve', 'Confirming too.'],
            $review,
            $auction,
        );

        $this->assertSame(AuctionStatus::AwaitingSellerDeposit, $auction->refresh()->status);
        $this->assertExactlyOneWinner($outcomes);
        $this->assertSingleOutcome($auction, $review);
    }

    public function test_a_confirmation_and_an_override_at_once_produce_one_decision(): void
    {
        [$review, $auction] = $this->assistedFixture();

        $outcomes = $this->race(
            ['human', (string) $review->public_id, (string) $this->overrider()->id, 'approve', 'Confirming.'],
            ['human', (string) $review->public_id, (string) $this->overrider()->id, 'reject', 'Overriding.'],
            $review,
            $auction,
        );

        $this->assertExactlyOneWinner($outcomes);
        $this->assertContains($auction->refresh()->status, [
            AuctionStatus::AwaitingSellerDeposit,
            AuctionStatus::Rejected,
        ]);
        $this->assertSingleOutcome($auction, $review);
    }

    public function test_two_overrides_at_once_produce_one_decision(): void
    {
        [$review, $auction] = $this->assistedFixture();

        $outcomes = $this->race(
            ['human', (string) $review->public_id, (string) $this->overrider()->id, 'reject', 'Overriding.'],
            ['human', (string) $review->public_id, (string) $this->overrider()->id, 'reject', 'Overriding too.'],
            $review,
            $auction,
        );

        $this->assertSame(AuctionStatus::Rejected, $auction->refresh()->status);
        $this->assertExactlyOneWinner($outcomes);
        $this->assertSingleOutcome($auction, $review);
    }

    public function test_an_admin_decision_racing_a_late_automated_result_wins_once(): void
    {
        [$review, $auction] = $this->assistedFixture(ReviewMode::AiAutomatic);

        $outcomes = $this->race(
            ['human', (string) $review->public_id, (string) $this->overrider()->id, 'reject', 'Deciding now.'],
            ['ai', (string) $review->public_id, '0', 'approve', 'Automated.'],
            $review,
            $auction,
        );

        foreach ($outcomes as $outcome) {
            $this->assertStringStartsNotWith('failed:', $outcome, implode(' | ', $outcomes));
        }

        $this->assertSame(
            1,
            AuctionStatusHistory::where('auction_id', $auction->id)
                ->where('from_status', AuctionStatus::PendingReview->value)
                ->count()
        );
        $this->assertLessThanOrEqual(
            1,
            ContentReviewDecision::where('review_id', $review->id)
                ->whereIn('decision', ['approved', 'rejected'])
                ->count()
        );
    }

    private function assertExactlyOneWinner(array $outcomes): void
    {
        $this->assertSame(1, count(array_filter($outcomes, static fn (string $o): bool => $o === 'ok')), implode(' | ', $outcomes));

        foreach ($outcomes as $outcome) {
            if ($outcome === 'ok') {
                continue;
            }

            $this->assertContains($outcome, [
                'review_already_decided',
                'review_superseded',
                'subject_not_reviewable',
                'invalid_transition',
            ], implode(' | ', $outcomes));
        }
    }

    private function assertSingleOutcome(Auction $auction, ContentReview $review): void
    {
        $this->assertSame(
            1,
            AuctionStatusHistory::where('auction_id', $auction->id)
                ->where('from_status', AuctionStatus::PendingReview->value)
                ->count()
        );
        $this->assertSame(
            1,
            ContentReviewDecision::where('review_id', $review->id)
                ->where('decided_by_type', DecisionActorType::Admin->value)
                ->count()
        );
        $this->assertLessThanOrEqual(1, AuctionConfigurationSnapshot::where('auction_id', $auction->id)->count());
        $this->assertLessThanOrEqual(
            1,
            AuctionDeposit::where('auction_id', $auction->id)->where('type', 'seller')->count()
        );
    }

    private function race(array $first, array $second, ContentReview $review, Auction $auction): array
    {
        $workDir = storage_path('framework/testing/content-review-decision-'.Str::ulid());
        mkdir($workDir, 0777, true);

        $worker = $workDir.DIRECTORY_SEPARATOR.'decision-worker.php';
        $barrier = $workDir.DIRECTORY_SEPARATOR.'go';
        file_put_contents($worker, $this->workerScript());

        $firstResult = $workDir.DIRECTORY_SEPARATOR.'first.txt';
        $secondResult = $workDir.DIRECTORY_SEPARATOR.'second.txt';

        $processes = [
            $this->process($worker, $barrier, $firstResult, $first, (int) $auction->id),
            $this->process($worker, $barrier, $secondResult, $second, (int) $auction->id),
        ];

        foreach ($processes as $process) {
            $process->start();
        }

        usleep(300_000);
        touch($barrier);

        foreach ($processes as $process) {
            $process->wait();
        }

        $outcomes = [(string) file_get_contents($firstResult), (string) file_get_contents($secondResult)];
        sort($outcomes);

        return $outcomes;
    }

    private function process(string $worker, string $barrier, string $result, array $arguments, int $auctionId): Process
    {
        return new Process(
            array_merge([PHP_BINARY, $worker, base_path(), (string) $auctionId], $arguments, [$barrier, $result]),
            base_path(),
            [
                'APP_ENV' => 'testing',
                'DB_CONNECTION' => config('database.default'),
                'DB_HOST' => config('database.connections.mysql.host'),
                'DB_PORT' => (string) config('database.connections.mysql.port'),
                'DB_DATABASE' => config('database.connections.mysql.database'),
                'DB_USERNAME' => config('database.connections.mysql.username'),
                'DB_PASSWORD' => (string) config('database.connections.mysql.password'),
                'CONTENT_REVIEW_ENABLED' => 'true',
                'CONTENT_REVIEW_PROVIDER' => 'fake',
                'CACHE_STORE' => 'array',
                'SESSION_DRIVER' => 'array',
                'QUEUE_CONNECTION' => 'sync',
            ],
            null,
            30
        );
    }

    private function workerScript(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

[$script, $basePath, $auctionId, $role, $reviewId, $actorId, $decision, $reason, $barrier, $result] = $argv;

chdir($basePath);

require $basePath.'/vendor/autoload.php';
$app = require $basePath.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$deadline = microtime(true) + 15;
while (! file_exists($barrier) && microtime(true) < $deadline) {
    usleep(10_000);
}

try {
    $action = app(App\Services\ContentReview\Actions\ApplyContentReviewDecisionAction::class);

    if ($role === 'human') {
        $action->applyHumanDecision(
            App\Domain\ContentReview\Enums\ReviewableSubjectType::Auction,
            (int) $auctionId,
            $decision === 'approve'
                ? App\Domain\ContentReview\Enums\ContentReviewDecisionType::Approved
                : App\Domain\ContentReview\Enums\ContentReviewDecisionType::Rejected,
            App\Models\User::findOrFail((int) $actorId),
            $reason,
            $reviewId,
        );
    } else {
        $review = App\Models\ContentReview\ContentReview::where('public_id', $reviewId)->firstOrFail();

        $action->execute(
            $review,
            new App\DTO\ContentReview\ReviewDecisionDTO(
                $decision === 'approve'
                    ? App\Domain\ContentReview\Enums\ContentReviewOutcome::AutoApproved
                    : App\Domain\ContentReview\Enums\ContentReviewOutcome::AutoRejected,
                App\Domain\ContentReview\Enums\ReviewRecommendation::Approve,
                96,
                'high_confidence_clean',
            ),
            $reason,
        );
    }

    file_put_contents($result, 'ok');
    exit(0);
} catch (App\Domain\ContentReview\Exceptions\ContentReviewException $exception) {
    file_put_contents($result, (string) $exception->getErrorCode());
    exit(0);
} catch (App\Domain\Auction\Exceptions\AuctionException $exception) {
    file_put_contents($result, (string) $exception->getErrorCode());
    exit(0);
} catch (Throwable $exception) {
    file_put_contents($result, 'failed: '.get_class($exception).': '.$exception->getMessage());
    exit(1);
}
PHP;
    }

    private function assistedFixture(ReviewMode $mode = ReviewMode::AiAssisted): array
    {
        $this->publishPolicy();
        $this->publishSettings($mode);
        $auction = $this->submitForReview();

        $content = app(ReviewSubjectRegistry::class)
            ->for(ReviewableSubjectType::Auction)
            ->buildContent((int) $auction->id);

        $review = ContentReview::where('subject_id', (int) $auction->id)
            ->where('subject_type', ReviewableSubjectType::Auction->value)
            ->latest('id')
            ->firstOrFail();

        $review->forceFill([
            'content_hash' => app(ContentHasher::class)->hashContent($content),
            'mode' => $mode->value,
            'status' => ContentReviewStatus::Completed->value,
            'outcome' => ContentReviewOutcome::AdvisoryOnly->value,
            'reason_code' => 'assisted_mode',
            'recommendation' => ReviewRecommendation::Approve->value,
            'confidence' => 96,
            'risk_level' => ReviewRiskLevel::Low->value,
            'requires_human_review' => true,
            'summary_ar' => 'الإعلان مطابق لسياسة المحتوى.',
            'current_marker' => 1,
            'superseded_at' => null,
            'error_code' => null,
            'lease_owner' => null,
            'leased_until' => null,
            'started_at' => now(),
            'completed_at' => now(),
            'decided_at' => now(),
        ])->save();

        return [$review->refresh(), $auction->refresh()];
    }

    private function overrider(): User
    {
        return $this->auctionReviewer();
    }
}
