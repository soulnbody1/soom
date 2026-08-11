<?php

declare(strict_types=1);

namespace Tests\Feature\Auction;

use App\Domain\Auction\Enums\AuctionStatus;
use App\Domain\ContentReview\Enums\ContentReviewOutcome;
use App\Domain\ContentReview\Enums\ContentReviewStatus;
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
use App\Models\ContentReview\ContentReviewImageCheck;
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
final class ContentReviewAutomationConcurrencyMysqlTest extends TestCase
{
    use BuildsContentReviewFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('MySQL-only automation concurrency test.');
        }

        if (! str_ends_with((string) DB::connection()->getDatabaseName(), '_testing')) {
            $this->markTestSkipped('Refusing to run automation concurrency test outside a *_testing database.');
        }

        Artisan::call('migrate', ['--force' => true]);
        config()->set('content_review.enabled', true);
        Queue::fake();
    }

    public function test_two_automatic_workers_on_one_review_apply_one_decision(): void
    {
        [$review, $auction] = $this->automaticFixture();

        $outcomes = $this->race(
            ['ai', (string) $review->public_id, '0', 'approve'],
            ['ai', (string) $review->public_id, '0', 'approve'],
            $auction,
        );

        $this->assertNoWorkerCrashed($outcomes);
        $this->assertSame(AuctionStatus::AwaitingSellerDeposit, $auction->refresh()->status);
        $this->assertSingleDomainDecision($auction, $review);
    }

    public function test_an_automatic_approval_racing_an_admin_rejection_applies_once(): void
    {
        [$review, $auction] = $this->automaticFixture();

        $outcomes = $this->race(
            ['ai', (string) $review->public_id, '0', 'approve'],
            ['human', (string) $review->public_id, (string) $this->overrider()->id, 'reject'],
            $auction,
        );

        $this->assertNoWorkerCrashed($outcomes);
        $this->assertContains($auction->refresh()->status, [
            AuctionStatus::AwaitingSellerDeposit,
            AuctionStatus::Rejected,
        ]);
        $this->assertSingleDomainDecision($auction, $review);
    }

    public function test_an_automatic_rejection_racing_an_admin_approval_applies_once(): void
    {
        [$review, $auction] = $this->automaticFixture();

        $outcomes = $this->race(
            ['ai', (string) $review->public_id, '0', 'reject'],
            ['human', (string) $review->public_id, (string) $this->overrider()->id, 'approve'],
            $auction,
        );

        $this->assertNoWorkerCrashed($outcomes);
        $this->assertContains($auction->refresh()->status, [
            AuctionStatus::AwaitingSellerDeposit,
            AuctionStatus::Rejected,
        ]);
        $this->assertSingleDomainDecision($auction, $review);
    }

    public function test_a_stale_automatic_result_racing_a_content_change_applies_nothing(): void
    {
        [$review, $auction] = $this->automaticFixture();

        Auction::whereKey((int) $auction->id)->update(['title' => 'A different title decided by the seller']);

        $outcomes = $this->race(
            ['ai', (string) $review->public_id, '0', 'approve'],
            ['ai', (string) $review->public_id, '0', 'approve'],
            $auction,
        );

        $this->assertNoWorkerCrashed($outcomes);
        $this->assertSame(AuctionStatus::PendingReview, $auction->refresh()->status);
        $this->assertSame(ContentReviewOutcome::NoDecision, $review->refresh()->outcome);
        $this->assertNull($review->refresh()->current_marker);
        $this->assertSame(0, ContentReviewDecision::where('review_id', $review->id)
            ->whereIn('decision', ['approved', 'rejected'])
            ->count());
        $this->assertSame(0, AuctionConfigurationSnapshot::where('auction_id', $auction->id)->count());
    }

    public function test_two_processes_caching_the_same_image_create_one_row(): void
    {
        [, $auction] = $this->automaticFixture();
        $fingerprint = hash('sha256', 'concurrent-image-'.Str::ulid());

        $outcomes = $this->race(
            ['cache', $fingerprint, '0', 'clean'],
            ['cache', $fingerprint, '0', 'clean'],
            $auction,
        );

        $this->assertNoWorkerCrashed($outcomes);
        $this->assertSame(1, ContentReviewImageCheck::where('image_sha256', $fingerprint)->count());
    }

    private function assertNoWorkerCrashed(array $outcomes): void
    {
        foreach ($outcomes as $outcome) {
            $this->assertStringStartsNotWith('failed:', $outcome, implode(' | ', $outcomes));
        }
    }

    private function assertSingleDomainDecision(Auction $auction, ContentReview $review): void
    {
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
        $this->assertLessThanOrEqual(1, AuctionConfigurationSnapshot::where('auction_id', $auction->id)->count());
        $this->assertLessThanOrEqual(
            1,
            AuctionDeposit::where('auction_id', $auction->id)->where('type', 'seller')->count()
        );
    }

    /**
     * @return array<int, string>
     */
    private function race(array $first, array $second, Auction $auction): array
    {
        $workDir = storage_path('framework/testing/content-review-automation-'.Str::ulid());
        mkdir($workDir, 0777, true);

        $worker = $workDir.DIRECTORY_SEPARATOR.'automation-worker.php';
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

[$script, $basePath, $auctionId, $role, $key, $actorId, $decision, $barrier, $result] = $argv;

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
    if ($role === 'cache') {
        app(App\Repositories\ContentReview\ContentReviewImageCheckRepository::class)->remember(
            $key,
            'fake',
            'claude-sonnet-5',
            1,
            1,
            App\Domain\ContentReview\Enums\ImageCheckVerdict::from($decision),
            App\Domain\ContentReview\Enums\ReviewRiskLevel::Low,
            [],
            null,
        );

        file_put_contents($result, 'ok');
        exit(0);
    }

    $action = app(App\Services\ContentReview\Actions\ApplyContentReviewDecisionAction::class);

    if ($role === 'human') {
        $action->applyHumanDecision(
            App\Domain\ContentReview\Enums\ReviewableSubjectType::Auction,
            (int) $auctionId,
            $decision === 'approve'
                ? App\Domain\ContentReview\Enums\ContentReviewDecisionType::Approved
                : App\Domain\ContentReview\Enums\ContentReviewDecisionType::Rejected,
            App\Models\User::findOrFail((int) $actorId),
            'Decided by an employee.',
            $key,
        );
    } else {
        $review = App\Models\ContentReview\ContentReview::where('public_id', $key)->firstOrFail();

        $action->execute(
            $review,
            new App\DTO\ContentReview\ReviewDecisionDTO(
                $decision === 'approve'
                    ? App\Domain\ContentReview\Enums\ContentReviewOutcome::AutoApproved
                    : App\Domain\ContentReview\Enums\ContentReviewOutcome::AutoRejected,
                $decision === 'approve'
                    ? App\Domain\ContentReview\Enums\ReviewRecommendation::Approve
                    : App\Domain\ContentReview\Enums\ReviewRecommendation::Reject,
                96,
                $decision === 'approve' ? 'high_confidence_clean' : 'high_confidence_critical_violation',
            ),
            'Decided automatically.',
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

    /**
     * @return array{0: ContentReview, 1: Auction}
     */
    private function automaticFixture(): array
    {
        $auction = $this->reviewedAuction(ReviewMode::AiAutomatic);

        $content = app(ReviewSubjectRegistry::class)
            ->for(ReviewableSubjectType::Auction)
            ->buildContent((int) $auction->id);

        $review = $this->activeReview($auction);

        $review->forceFill([
            'content_hash' => app(ContentHasher::class)->hashContent($content),
            'mode' => ReviewMode::AiAutomatic->value,
            'status' => ContentReviewStatus::Completed->value,
            'outcome' => null,
            'reason_code' => null,
            'recommendation' => ReviewRecommendation::Approve->value,
            'confidence' => 96,
            'risk_level' => ReviewRiskLevel::Low->value,
            'requires_human_review' => false,
            'summary_ar' => 'الإعلان مطابق لسياسة المحتوى.',
            'current_marker' => 1,
            'superseded_at' => null,
            'error_code' => null,
            'lease_owner' => null,
            'leased_until' => null,
            'started_at' => now(),
            'completed_at' => now(),
            'decided_at' => null,
        ])->save();

        return [$review->refresh(), $auction->refresh()];
    }

    private function overrider(): User
    {
        return $this->auctionReviewer(['content_review.view', 'content_review.override']);
    }
}
