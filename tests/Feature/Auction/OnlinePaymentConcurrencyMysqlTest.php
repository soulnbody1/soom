<?php

declare(strict_types=1);

namespace Tests\Feature\Auction;

use App\Domain\Auction\Enums\AuctionDepositStatus;
use App\Domain\Auction\Enums\AuctionStatus;
use App\Domain\Auction\Enums\PaymentPurpose;
use App\Domain\Auction\Enums\PaymentTransactionStatus;
use App\Models\Auction\AuctionDeposit;
use App\Models\Auction\PaymentProviderEvent;
use App\Models\Auction\PaymentTransaction;
use App\Services\Auction\Actions\CreatePaymentIntentAction;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Process;
use Tests\Feature\Auction\Concerns\BuildsOnlinePaymentFixtures;
use Tests\TestCase;

#[Group('mysql-concurrency')]
final class OnlinePaymentConcurrencyMysqlTest extends TestCase
{
    use BuildsOnlinePaymentFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') !== 'mysql') {
            $this->markTestSkipped('MySQL-only online payment concurrency test.');
        }

        $database = (string) config('database.connections.mysql.database');
        $this->assertStringEndsWith('_testing', $database);
        $this->recreateTestingDatabase($database);

        DB::purge('mysql');
        DB::reconnect('mysql');

        Artisan::call('migrate', ['--force' => true]);
        $this->enableFakeProvider();
    }

    public function test_parallel_webhooks_for_the_same_payment_apply_the_obligation_once(): void
    {
        [$auction] = $this->paymentAuction(AuctionStatus::Live);
        [$bidder] = $this->registeredBidder($auction);
        $provider = $this->enableFakeProvider();

        $transaction = app(CreatePaymentIntentAction::class)
            ->execute($auction, $bidder->id, PaymentPurpose::BidderDeposit, $this->onlinePaymentMethod()->public_id);

        $reference = (string) $transaction->provider_transaction_id;
        $provider->markSucceeded($reference);

        $results = $this->runParallel($this->webhookWorkerScript(), [
            [$reference, 'evt-parallel-a'],
            [$reference, 'evt-parallel-b'],
        ]);

        $this->assertSame(['ok', 'ok'], $results, implode(' | ', $results));

        $deposit = AuctionDeposit::where('auction_id', $auction->id)->where('user_id', $bidder->id)->firstOrFail();
        $this->assertSame(AuctionDepositStatus::Held, $deposit->status);
        $this->assertSame(1_000, (int) $deposit->held_amount_minor);
        $this->assertSame(1, PaymentTransaction::where('successful_obligation_key', "deposit:{$deposit->id}")->count());
        $this->assertSame(2, PaymentProviderEvent::where('provider', 'fake')->count());
        $this->assertSame(PaymentTransactionStatus::Succeeded, $transaction->refresh()->status);
    }

    public function test_parallel_intent_creation_never_opens_two_checkouts_for_one_obligation(): void
    {
        [$auction] = $this->paymentAuction(AuctionStatus::Live);
        [$bidder] = $this->registeredBidder($auction);
        $method = $this->onlinePaymentMethod();

        $results = $this->runParallel($this->intentWorkerScript(), [
            [(string) $auction->public_id, (string) $bidder->id, (string) $method->public_id],
            [(string) $auction->public_id, (string) $bidder->id, (string) $method->public_id],
        ]);

        foreach ($results as $result) {
            $this->assertContains($result, ['ok', 'conflict'], implode(' | ', $results));
        }

        $this->assertLessThanOrEqual(
            1,
            PaymentTransaction::where('auction_id', $auction->id)
                ->where('status', PaymentTransactionStatus::Pending->value)
                ->whereNotNull('provider_transaction_id')
                ->count()
        );
    }

    private function runParallel(string $script, array $arguments): array
    {
        $workDir = storage_path('framework/testing/online-payment-'.Str::ulid());
        mkdir($workDir, 0777, true);

        $worker = $workDir.DIRECTORY_SEPARATOR.'worker.php';
        $barrier = $workDir.DIRECTORY_SEPARATOR.'go';
        file_put_contents($worker, $script);

        $processes = [];
        $resultFiles = [];

        foreach ($arguments as $index => $argumentSet) {
            $resultFiles[$index] = $workDir.DIRECTORY_SEPARATOR."result-{$index}.txt";
            $processes[$index] = new Process(
                array_merge([PHP_BINARY, $worker, base_path(), $barrier, $resultFiles[$index]], $argumentSet),
                base_path(),
                [
                    'APP_ENV' => 'testing',
                    'DB_CONNECTION' => 'mysql',
                    'DB_HOST' => (string) config('database.connections.mysql.host'),
                    'DB_PORT' => (string) config('database.connections.mysql.port'),
                    'DB_DATABASE' => (string) config('database.connections.mysql.database'),
                    'DB_USERNAME' => (string) config('database.connections.mysql.username'),
                    'DB_PASSWORD' => (string) config('database.connections.mysql.password'),
                    'CACHE_STORE' => 'array',
                    'SESSION_DRIVER' => 'array',
                    'QUEUE_CONNECTION' => 'sync',
                    'AUCTION_PAYMENTS_ALLOW_FAKE_PROVIDER' => 'true',
                    'AUCTION_PAYMENTS_FAKE_WEBHOOK_SECRET' => 'test-webhook-secret',
                ],
                null,
                30
            );
            $processes[$index]->start();
        }

        usleep(250_000);
        touch($barrier);

        $results = [];

        foreach ($processes as $index => $process) {
            $process->wait();
            $results[$index] = trim((string) @file_get_contents($resultFiles[$index]));

            if (! $process->isSuccessful() && $results[$index] === '') {
                $results[$index] = 'process_error: '.$process->getErrorOutput();
            }
        }

        return $results;
    }

    private function webhookWorkerScript(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

[$script, $basePath, $barrier, $result, $reference, $eventId] = $argv;

chdir($basePath);

require $basePath.'/vendor/autoload.php';
$app = require $basePath.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$transaction = App\Models\Auction\PaymentTransaction::where('provider_transaction_id', $reference)->firstOrFail();

$provider = app(App\Services\Auction\Payments\Providers\FakePaymentProvider::class);
$provider->markSucceeded($reference, (int) $transaction->amount_minor, (string) $transaction->currency_code);

$payload = [
    'event_id' => $eventId,
    'event_type' => 'payment.succeeded',
    'provider_transaction_id' => $reference,
];

$request = Illuminate\Http\Request::create(
    '/api/webhooks/payments/fake',
    'POST',
    [],
    [],
    [],
    ['CONTENT_TYPE' => 'application/json', 'HTTP_X_FAKE_SIGNATURE' => hash_hmac('sha256', json_encode($payload), 'test-webhook-secret')],
    json_encode($payload)
);

$deadline = microtime(true) + 10;
while (! file_exists($barrier) && microtime(true) < $deadline) {
    usleep(10_000);
}

try {
    app(App\Services\Auction\Actions\HandlePaymentWebhookAction::class)->execute('fake', $request);
    file_put_contents($result, 'ok');
    exit(0);
} catch (Throwable $exception) {
    file_put_contents($result, get_class($exception).': '.$exception->getMessage());
    exit(1);
}
PHP;
    }

    private function intentWorkerScript(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

[$script, $basePath, $barrier, $result, $auctionPublicId, $userId, $methodPublicId] = $argv;

chdir($basePath);

require $basePath.'/vendor/autoload.php';
$app = require $basePath.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$auction = App\Models\Auction\Auction::where('public_id', $auctionPublicId)->firstOrFail();

$deadline = microtime(true) + 10;
while (! file_exists($barrier) && microtime(true) < $deadline) {
    usleep(10_000);
}

try {
    app(App\Services\Auction\Actions\CreatePaymentIntentAction::class)->execute(
        $auction,
        (int) $userId,
        App\Domain\Auction\Enums\PaymentPurpose::BidderDeposit,
        $methodPublicId
    );

    file_put_contents($result, 'ok');
    exit(0);
} catch (Throwable $exception) {
    file_put_contents($result, 'conflict');
    exit(0);
}
PHP;
    }

    private function recreateTestingDatabase(string $database): void
    {
        $this->assertStringEndsWith('_testing', $database);

        $pdo = new PDO(
            $this->dsn(),
            (string) config('database.connections.mysql.username'),
            (string) config('database.connections.mysql.password'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        $pdo->exec("DROP DATABASE IF EXISTS `{$database}`");
        $pdo->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    }

    private function dsn(): string
    {
        $host = (string) config('database.connections.mysql.host');
        $port = (string) config('database.connections.mysql.port');

        return "mysql:host={$host};port={$port};charset=utf8mb4";
    }
}
