<?php

declare(strict_types=1);

namespace Tests\Feature\Auction;

use App\Domain\Auction\Enums\AuctionStatus;
use App\Domain\Auction\Enums\PaymentSubmissionStatus;
use App\Domain\Auction\Enums\SettlementStatus;
use App\Models\Auction\AuctionSettlement;
use App\Models\Auction\AuctionWinnerReassignment;
use App\Models\Auction\OutboxMessage;
use App\Services\Auction\Actions\FinalizeAuctionAction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Feature\Auction\Concerns\BuildsAuctionDeadlineFixtures;
use Tests\TestCase;

final class AuctionDeadlineJobsMysqlTest extends TestCase
{
    use BuildsAuctionDeadlineFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('MySQL-only concurrency/integrity test.');
        }

        Artisan::call('migrate', ['--force' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_parallel_auto_default_jobs_default_the_winner_once(): void
    {
        [$auction, $settlement] = $this->overdueSettlement();

        $results = $this->runParallel($this->autoDefaultWorkerScript(), [(string) $auction->id]);

        foreach ($results as $result) {
            $this->assertSame('ok', $result, $result);
        }

        $settlement->refresh();
        $this->assertSame(SettlementStatus::Defaulted, $settlement->status);
        $this->assertTrue((bool) $settlement->auto_defaulted);
        $this->assertSame(1, AuctionWinnerReassignment::where('auction_id', $auction->id)->count());
        $this->assertSame(1, AuctionSettlement::where('auction_id', $auction->id)->where('current_marker', 1)->count());
        $this->assertSame(1, OutboxMessage::where('aggregate_id', $auction->id)
            ->where('event_type', 'auction.winner_defaulted')
            ->count());
    }

    public function test_auto_default_job_racing_payment_approval_never_double_settles(): void
    {
        [$auction, $settlement, $winner] = $this->overdueSettlement();
        $submission = $this->pendingSettlementSubmission($auction, $settlement->id, $winner->id, (int) $settlement->amount_due_minor);
        $admin = $this->user('admin');

        $results = $this->runParallelPair(
            $this->autoDefaultWorkerScript(),
            [(string) $auction->id],
            $this->paymentApprovalWorkerScript(),
            [(string) $submission->id, (string) $admin->id],
        );

        $context = 'auto-default: '.$results[0].' | approval: '.$results[1];
        $settlement->refresh();
        $auction->refresh();
        $submission->refresh();

        $this->assertSame(1, AuctionSettlement::where('auction_id', $auction->id)->where('current_marker', 1)->count());

        if ($settlement->status === SettlementStatus::Defaulted) {
            $this->assertSame(0, (int) $settlement->amount_paid_minor, $context);
            $this->assertNotSame(AuctionStatus::HandoverPending, $auction->status, $context);

            return;
        }

        $this->assertSame(0, AuctionWinnerReassignment::where('auction_id', $auction->id)->count(), $context);
        $this->assertSame(0, OutboxMessage::where('aggregate_id', $auction->id)
            ->where('event_type', 'auction.winner_defaulted')
            ->count(), $context);

        if ($submission->status === PaymentSubmissionStatus::Approved) {
            $this->assertSame((int) $settlement->amount_due_minor, (int) $settlement->amount_paid_minor, $context);

            return;
        }

        $this->assertSame(SettlementStatus::PaymentPending, $settlement->status, $context);
        $this->assertSame(0, (int) $settlement->amount_paid_minor, $context);

        $this->reconcileByRerunningTheJob($auction, $submission);
    }

    private function reconcileByRerunningTheJob($auction, $submission): void
    {
        $submission->forceFill(['status' => PaymentSubmissionStatus::Rejected])->save();

        app(\App\Jobs\Auction\AutoDefaultOverdueWinnersJob::class)
            ->handle(app(\App\Services\Auction\Actions\MarkWinnerDefaultedAction::class));

        $current = AuctionSettlement::where('auction_id', $auction->id)
            ->where('current_marker', 1)
            ->first();

        $this->assertSame(
            1,
            AuctionSettlement::where('auction_id', $auction->id)->where('status', SettlementStatus::Defaulted->value)->count(),
            'the next scheduler tick must resolve a settlement both racers left untouched'
        );
        $this->assertNotNull($current);
        $this->assertNotSame(SettlementStatus::Defaulted, $current->status);
    }

    public function test_parallel_seller_deposit_expiry_cancels_once(): void
    {
        [$auction] = $this->auction(AuctionStatus::AwaitingSellerDeposit);
        $auction->forceFill(['seller_deposit_due_at' => Carbon::now()->subMinute()])->save();

        $results = $this->runParallel($this->sellerDepositExpiryWorkerScript(), [(string) $auction->id]);

        foreach ($results as $result) {
            $this->assertSame('ok', $result, $result);
        }

        $auction->refresh();
        $this->assertSame(AuctionStatus::Cancelled, $auction->status);
        $this->assertNull($auction->published_at);
        $this->assertSame(1, OutboxMessage::where('aggregate_id', $auction->id)
            ->where('event_type', 'auction.seller_deposit_expired')
            ->count());
    }

    public function test_parallel_payment_reminder_jobs_record_each_offset_once(): void
    {
        [$auction, $settlement] = $this->overdueSettlement(false);

        Carbon::setTestNow($settlement->payment_due_at->copy()->subHours(23));
        $frozenNow = Carbon::now()->toIso8601String();
        Carbon::setTestNow();

        $results = $this->runParallel($this->paymentReminderWorkerScript(), [(string) $auction->id, $frozenNow]);

        foreach ($results as $result) {
            $this->assertSame('ok', $result, $result);
        }

        $this->assertSame([24], array_values((array) $settlement->refresh()->payment_reminders_sent));
        $this->assertSame(1, OutboxMessage::where('aggregate_id', $auction->id)
            ->where('event_type', 'auction.winner_payment_reminder')
            ->count());
    }

    private function overdueSettlement(bool $pastGrace = true): array
    {
        [$auction] = $this->auction(AuctionStatus::Live);
        [$runnerUp, $runnerUpParticipant] = $this->qualifiedParticipant($auction, 10_000);
        [$winner, $winnerParticipant] = $this->qualifiedParticipant($auction, 10_000);
        $this->bid($auction, $runnerUpParticipant, $runnerUp, 90_000, 1);
        $this->bid($auction, $winnerParticipant, $winner, 100_000, 2);

        app(FinalizeAuctionAction::class)->execute($auction->refresh());

        $settlement = AuctionSettlement::where('auction_id', $auction->id)->where('is_current', true)->firstOrFail();

        if ($pastGrace) {
            $settlement->forceFill([
                'payment_due_at' => Carbon::now()->subDays(2),
                'payment_grace_ends_at' => Carbon::now()->subMinute(),
            ])->save();
        }

        return [$auction->refresh(), $settlement->refresh(), $winner, $runnerUp];
    }

    private function runParallel(string $script, array $arguments): array
    {
        [$first, $second] = $this->buildPair($script, $arguments, $script, $arguments);

        return $this->execute($first, $second);
    }

    private function runParallelPair(string $firstScript, array $firstArgs, string $secondScript, array $secondArgs): array
    {
        [$first, $second] = $this->buildPair($firstScript, $firstArgs, $secondScript, $secondArgs);

        return $this->execute($first, $second);
    }

    private function buildPair(string $firstScript, array $firstArgs, string $secondScript, array $secondArgs): array
    {
        $workDir = storage_path('framework/testing/auction-deadline-concurrency-'.Str::ulid());
        mkdir($workDir, 0777, true);

        $barrier = $workDir.DIRECTORY_SEPARATOR.'go';
        $firstWorker = $workDir.DIRECTORY_SEPARATOR.'first-worker.php';
        $secondWorker = $workDir.DIRECTORY_SEPARATOR.'second-worker.php';
        $firstResult = $workDir.DIRECTORY_SEPARATOR.'first-result.txt';
        $secondResult = $workDir.DIRECTORY_SEPARATOR.'second-result.txt';

        file_put_contents($firstWorker, $firstScript);
        file_put_contents($secondWorker, $secondScript);

        return [
            [$this->process($firstWorker, $firstArgs, $barrier, $firstResult), $firstResult, $barrier],
            [$this->process($secondWorker, $secondArgs, $barrier, $secondResult), $secondResult, $barrier],
        ];
    }

    private function execute(array $first, array $second): array
    {
        [$firstProcess, $firstResult, $barrier] = $first;
        [$secondProcess, $secondResult] = $second;

        $firstProcess->start();
        $secondProcess->start();
        usleep(200_000);
        touch($barrier);

        $firstProcess->wait();
        $secondProcess->wait();

        return [
            file_exists($firstResult) ? trim((string) file_get_contents($firstResult)) : $firstProcess->getErrorOutput(),
            file_exists($secondResult) ? trim((string) file_get_contents($secondResult)) : $secondProcess->getErrorOutput(),
        ];
    }

    private function process(string $worker, array $arguments, string $barrier, string $result): Process
    {
        return new Process(
            [PHP_BINARY, $worker, base_path(), ...$arguments, $barrier, $result],
            base_path(),
            [
                'APP_ENV' => 'testing',
                'DB_CONNECTION' => config('database.default'),
                'DB_HOST' => config('database.connections.mysql.host'),
                'DB_PORT' => (string) config('database.connections.mysql.port'),
                'DB_DATABASE' => config('database.connections.mysql.database'),
                'DB_USERNAME' => config('database.connections.mysql.username'),
                'DB_PASSWORD' => (string) config('database.connections.mysql.password'),
                'CACHE_STORE' => 'array',
                'SESSION_DRIVER' => 'array',
                'QUEUE_CONNECTION' => 'sync',
                'BROADCAST_CONNECTION' => 'null',
            ],
            null,
            60
        );
    }

    private function autoDefaultWorkerScript(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

[$script, $basePath, $auctionId, $barrier, $result] = $argv;

chdir($basePath);

require $basePath.'/vendor/autoload.php';
$app = require $basePath.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$deadline = microtime(true) + 10;
while (! file_exists($barrier) && microtime(true) < $deadline) {
    usleep(10_000);
}

try {
    app(App\Jobs\Auction\AutoDefaultOverdueWinnersJob::class)
        ->handle(app(App\Services\Auction\Actions\MarkWinnerDefaultedAction::class));

    file_put_contents($result, 'ok');
    exit(0);
} catch (Throwable $exception) {
    file_put_contents($result, get_class($exception).': '.$exception->getMessage());
    exit(0);
}
PHP;
    }

    private function sellerDepositExpiryWorkerScript(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

[$script, $basePath, $auctionId, $barrier, $result] = $argv;

chdir($basePath);

require $basePath.'/vendor/autoload.php';
$app = require $basePath.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$deadline = microtime(true) + 10;
while (! file_exists($barrier) && microtime(true) < $deadline) {
    usleep(10_000);
}

try {
    app(App\Jobs\Auction\ExpireSellerDepositDeadlinesJob::class)
        ->handle(app(App\Services\Auction\Actions\ExpireSellerDepositDeadlineAction::class));

    file_put_contents($result, 'ok');
    exit(0);
} catch (Throwable $exception) {
    file_put_contents($result, get_class($exception).': '.$exception->getMessage());
    exit(0);
}
PHP;
    }

    private function paymentReminderWorkerScript(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

[$script, $basePath, $auctionId, $frozenNow, $barrier, $result] = $argv;

chdir($basePath);

require $basePath.'/vendor/autoload.php';
$app = require $basePath.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

Illuminate\Support\Carbon::setTestNow(Illuminate\Support\Carbon::parse($frozenNow));

$deadline = microtime(true) + 10;
while (! file_exists($barrier) && microtime(true) < $deadline) {
    usleep(10_000);
}

try {
    app(App\Jobs\Auction\SendWinnerPaymentRemindersJob::class)
        ->handle(app(App\Services\Auction\Actions\SendWinnerPaymentRemindersAction::class));

    file_put_contents($result, 'ok');
    exit(0);
} catch (Throwable $exception) {
    file_put_contents($result, get_class($exception).': '.$exception->getMessage());
    exit(0);
}
PHP;
    }

    private function paymentApprovalWorkerScript(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

[$script, $basePath, $submissionId, $adminId, $barrier, $result] = $argv;

chdir($basePath);

require $basePath.'/vendor/autoload.php';
$app = require $basePath.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$deadline = microtime(true) + 10;
while (! file_exists($barrier) && microtime(true) < $deadline) {
    usleep(10_000);
}

try {
    $submission = App\Models\Auction\PaymentSubmission::findOrFail((int) $submissionId);
    app(App\Services\Auction\Actions\ReviewPaymentSubmissionAction::class)
        ->approve($submission, (int) $adminId, 'parallel approval');

    file_put_contents($result, 'ok');
    exit(0);
} catch (Throwable $exception) {
    file_put_contents($result, get_class($exception).': '.$exception->getMessage());
    exit(0);
}
PHP;
    }
}
