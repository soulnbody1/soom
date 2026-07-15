<?php

declare(strict_types=1);

namespace Tests\Feature\Auction;

use App\Domain\Auction\Enums\AuctionDepositStatus;
use App\Domain\Auction\Enums\AuctionStatus;
use App\Domain\Auction\Enums\PaymentPurpose;
use App\Domain\Auction\Enums\PaymentSubmissionStatus;
use App\Domain\Auction\Enums\PaymentTransactionStatus;
use App\Models\Auction\Auction;
use App\Models\Auction\AuctionActivityLog;
use App\Models\Auction\AuctionDeposit;
use App\Models\Auction\AuctionTermsVersion;
use App\Models\Auction\PaymentMethod;
use App\Models\Auction\PaymentSubmission;
use App\Models\Auction\PaymentTransaction;
use App\Models\Auction\RefundTransaction;
use App\Models\Category;
use App\Models\Country;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Process;
use Tests\TestCase;

#[Group('mysql-concurrency')]
final class AuctionCancellationMysqlTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') !== 'mysql') {
            $this->markTestSkipped('MySQL-only auction cancellation concurrency test.');
        }

        $database = (string) config('database.connections.mysql.database');
        $this->assertStringEndsWith('_testing', $database);
        $this->recreateTestingDatabase($database);

        DB::purge('mysql');
        DB::reconnect('mysql');

        Artisan::call('migrate', ['--force' => true]);
    }

    public function test_parallel_seller_and_admin_cancellation_create_one_financial_plan(): void
    {
        [$auction, $seller] = $this->auction();
        $deposit = $this->sellerDeposit($auction, $seller, 10_000);
        $payment = $this->paymentForSellerDeposit($auction, $deposit, $seller, 10_000);
        $admin = $this->user('admin');
        $workDir = storage_path('framework/testing/auction-cancellation-'.Str::ulid());
        mkdir($workDir, 0777, true);

        $worker = $workDir.DIRECTORY_SEPARATOR.'cancel-auction-worker.php';
        $barrier = $workDir.DIRECTORY_SEPARATOR.'go';
        $sellerResult = $workDir.DIRECTORY_SEPARATOR.'seller-result.txt';
        $adminResult = $workDir.DIRECTORY_SEPARATOR.'admin-result.txt';
        file_put_contents($worker, $this->workerScript());

        $sellerProcess = $this->cancelProcess($worker, $barrier, $sellerResult, $auction->id, $seller->id, 'user', 'seller changed mind');
        $adminProcess = $this->cancelProcess($worker, $barrier, $adminResult, $auction->id, $admin->id, 'admin', 'platform_fault: duplicate listing');

        $sellerProcess->start();
        $adminProcess->start();
        usleep(200_000);
        touch($barrier);

        $sellerProcess->wait();
        $adminProcess->wait();

        $this->assertTrue($sellerProcess->isSuccessful(), $sellerProcess->getErrorOutput().file_get_contents($sellerResult));
        $this->assertTrue($adminProcess->isSuccessful(), $adminProcess->getErrorOutput().file_get_contents($adminResult));
        $this->assertSame('ok', trim(file_get_contents($sellerResult)));
        $this->assertSame('ok', trim(file_get_contents($adminResult)));

        $this->assertSame(AuctionStatus::Cancelled, $auction->refresh()->status);
        $this->assertSame(1, RefundTransaction::where('auction_id', $auction->id)->count());
        $this->assertSame(1, RefundTransaction::where('payment_transaction_id', $payment->id)->count());
        $this->assertSame(1, AuctionActivityLog::where('auction_id', $auction->id)->where('event_type', 'auction.cancellation_started')->count());
        $this->assertSame(1, AuctionActivityLog::where('auction_id', $auction->id)->where('event_type', 'auction.cancellation_financial_plan_created')->count());
        $this->assertSame(PaymentTransactionStatus::Succeeded, $payment->refresh()->status);
    }

    private function cancelProcess(
        string $worker,
        string $barrier,
        string $result,
        int $auctionId,
        int $actorId,
        string $actorType,
        string $reason,
    ): Process {
        return new Process(
            [PHP_BINARY, $worker, base_path(), (string) $auctionId, (string) $actorId, $actorType, $reason, $barrier, $result],
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
            ],
            null,
            20
        );
    }

    private function workerScript(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

[$script, $basePath, $auctionId, $actorId, $actorType, $reason, $barrier, $result] = $argv;

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
    $auction = App\Models\Auction\Auction::findOrFail((int) $auctionId);
    app(App\Services\Auction\Actions\CancelAuctionAction::class)
        ->execute($auction, (int) $actorId, $actorType, $reason);

    file_put_contents($result, 'ok');
    exit(0);
} catch (Throwable $exception) {
    file_put_contents($result, get_class($exception).': '.$exception->getMessage());
    exit(1);
}
PHP;
    }

    private function auction(): array
    {
        $seller = $this->user();
        $terms = AuctionTermsVersion::create([
            'version_number' => ((int) AuctionTermsVersion::max('version_number')) + 1,
            'title' => 'Terms',
            'body' => 'Auction terms.',
            'is_active' => true,
            'published_at' => now()->subDay(),
        ]);
        $category = Category::create(['name' => 'mysql-auction-cancel-cat-'.Str::ulid(), 'display_order' => 0]);
        $country = Country::create(['name' => 'mysql-auction-cancel-country-'.Str::ulid(), 'code' => strtoupper(substr((string) Str::ulid(), 0, 6))]);

        $auction = Auction::create([
            'seller_id' => $seller->id,
            'category_id' => $category->id,
            'country_id' => $country->id,
            'terms_version_id' => $terms->id,
            'configuration_version_id' => $this->auctionConfigurationVersion([
                'seller_deposit_minor' => 10_000,
                'bidder_deposit_minor' => 10_000,
            ])->id,
            'currency_code' => 'JOD',
            'title' => 'MySQL auction cancellation',
            'description' => 'MySQL auction cancellation.',
            'status' => AuctionStatus::Scheduled,
            'starting_amount_minor' => 10_000,
            'reserve_amount_minor' => null,
            'minimum_bid_increment_minor' => 500,
            'seller_deposit_amount_minor' => 10_000,
            'bidder_deposit_amount_minor' => 10_000,
            'platform_fee_type' => 'percentage',
            'platform_fee_basis_points' => 250,
            'platform_fee_fixed_minor' => 0,
            'winner_payment_deadline_hours' => 48,
            'handover_deadline_hours' => 72,
            'starts_at' => now()->subDays(2),
            'original_ends_at' => now()->addHour(),
            'ends_at' => now()->addHour(),
        ]);

        $this->snapshotApprovedAuction($auction, $seller->id);

        return [$auction, $seller];
    }

    private function sellerDeposit(Auction $auction, User $seller, int $amount): AuctionDeposit
    {
        return AuctionDeposit::create([
            'auction_id' => $auction->id,
            'user_id' => $seller->id,
            'type' => 'seller',
            'status' => AuctionDepositStatus::Held,
            'required_amount_minor' => $amount,
            'held_amount_minor' => $amount,
            'currency_code' => 'JOD',
            'held_at' => now()->subHour(),
        ]);
    }

    private function paymentForSellerDeposit(Auction $auction, AuctionDeposit $deposit, User $seller, int $amount): PaymentTransaction
    {
        $submission = PaymentSubmission::create([
            'auction_id' => $auction->id,
            'deposit_id' => $deposit->id,
            'user_id' => $seller->id,
            'payment_method_id' => $this->paymentMethod()->id,
            'purpose' => PaymentPurpose::SellerDeposit,
            'status' => PaymentSubmissionStatus::Approved,
            'amount_minor' => $amount,
            'currency_code' => 'JOD',
            'receipt_disk' => 'spaces_private',
            'receipt_path' => 'mysql-auction-cancel.pdf',
            'receipt_mime_type' => 'application/pdf',
            'receipt_size_bytes' => 100,
            'idempotency_key' => 'mysql-auction-cancel-'.Str::ulid(),
            'submitted_at' => now()->subHour(),
            'reviewed_at' => now()->subHour(),
        ]);

        return PaymentTransaction::create([
            'payment_submission_id' => $submission->id,
            'auction_id' => $auction->id,
            'user_id' => $seller->id,
            'purpose' => PaymentPurpose::SellerDeposit,
            'status' => PaymentTransactionStatus::Succeeded,
            'amount_minor' => $amount,
            'currency_code' => 'JOD',
            'provider' => 'manual',
            'provider_transaction_id' => 'mysql-auction-cancel-provider-'.Str::ulid(),
            'idempotency_key' => 'mysql-auction-cancel-payment-'.Str::ulid(),
            'successful_obligation_key' => "deposit:{$deposit->id}",
            'processed_at' => now()->subHour(),
        ]);
    }

    private function paymentMethod(): PaymentMethod
    {
        return PaymentMethod::create([
            'name' => 'MySQL auction cancellation method',
            'code' => 'mysql-auction-cancel-'.Str::ulid(),
            'instructions' => 'Upload receipt.',
            'requires_manual_review' => true,
            'is_active' => true,
        ]);
    }

    private function user(string $role = 'user'): User
    {
        $unique = strtolower((string) Str::ulid());
        $phoneSuffix = str_pad((string) (abs(crc32($unique)) % 10_000_000), 7, '0', STR_PAD_LEFT);

        return User::create([
            'name' => fake()->name(),
            'email' => "mysql-auction-cancel-{$unique}@example.test",
            'phone' => '+96273'.$phoneSuffix,
            'password' => Hash::make('password'),
            'role' => $role,
            'email_verified_at' => now(),
        ]);
    }

    private function recreateTestingDatabase(string $database): void
    {
        $this->assertStringEndsWith('_testing', $database);

        $pdo = new PDO(
            $this->dsn(null),
            (string) config('database.connections.mysql.username'),
            (string) config('database.connections.mysql.password'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        $pdo->exec("DROP DATABASE IF EXISTS `{$database}`");
        $pdo->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    }

    private function dsn(?string $database): string
    {
        $host = (string) config('database.connections.mysql.host');
        $port = (string) config('database.connections.mysql.port');
        $databasePart = $database ? "dbname={$database};" : '';

        return "mysql:host={$host};port={$port};{$databasePart}charset=utf8mb4";
    }
}
