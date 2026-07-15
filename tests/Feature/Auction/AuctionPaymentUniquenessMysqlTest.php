<?php

declare(strict_types=1);

namespace Tests\Feature\Auction;

use App\Domain\Auction\Enums\AuctionDepositStatus;
use App\Domain\Auction\Enums\AuctionStatus;
use App\Domain\Auction\Enums\PaymentPurpose;
use App\Domain\Auction\Enums\PaymentSubmissionStatus;
use App\Models\Auction\Auction;
use App\Models\Auction\AuctionDeposit;
use App\Models\Auction\AuctionTermsVersion;
use App\Models\Auction\PaymentMethod;
use App\Models\Auction\PaymentSubmission;
use App\Models\Auction\PaymentTransaction;
use App\Models\Category;
use App\Models\Country;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Process;
use Tests\TestCase;

#[Group('mysql-concurrency')]
final class AuctionPaymentUniquenessMysqlTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') !== 'mysql') {
            $this->markTestSkipped('MySQL-only payment uniqueness test.');
        }

        $database = (string) config('database.connections.mysql.database');
        $this->assertStringEndsWith('_testing', $database);
        $this->recreateTestingDatabase($database);

        DB::purge('mysql');
        DB::reconnect('mysql');

        Artisan::call('migrate', ['--force' => true]);
    }

    public function test_auction_payment_parallel_approvals_for_two_deposit_submissions_create_one_success(): void
    {
        [$auction, $seller] = $this->auctionWithoutBids();
        $deposit = $this->sellerDeposit($auction, $seller);
        $firstSubmission = $this->depositSubmission($auction, $seller, $deposit, 'mysql-deposit-first');
        $secondSubmission = $this->depositSubmission($auction, $seller, $deposit, 'mysql-deposit-second');
        $admin = $this->user('admin');
        $workDir = storage_path('framework/testing/auction-payment-'.Str::ulid());
        mkdir($workDir, 0777, true);

        $worker = $workDir.DIRECTORY_SEPARATOR.'approve-payment-obligation-worker.php';
        $barrier = $workDir.DIRECTORY_SEPARATOR.'go';
        $firstResult = $workDir.DIRECTORY_SEPARATOR.'first-result.txt';
        $secondResult = $workDir.DIRECTORY_SEPARATOR.'second-result.txt';
        file_put_contents($worker, $this->approvalWorkerScript());

        $first = $this->approvalProcess($worker, $barrier, $firstResult, $firstSubmission->id, $admin->id);
        $second = $this->approvalProcess($worker, $barrier, $secondResult, $secondSubmission->id, $admin->id);

        $first->start();
        $second->start();
        usleep(200_000);
        touch($barrier);

        $first->wait();
        $second->wait();

        $this->assertTrue($first->isSuccessful(), $first->getErrorOutput().file_get_contents($firstResult));
        $this->assertTrue($second->isSuccessful(), $second->getErrorOutput().file_get_contents($secondResult));

        $results = [trim(file_get_contents($firstResult)), trim(file_get_contents($secondResult))];
        sort($results);

        $this->assertSame(['already_paid', 'approved'], $results);
        $this->assertSame(1, PaymentTransaction::where('successful_obligation_key', "deposit:{$deposit->id}")->count());
        $this->assertSame(1, PaymentSubmission::whereKey([$firstSubmission->id, $secondSubmission->id])->where('status', PaymentSubmissionStatus::Approved->value)->count());
        $this->assertSame(AuctionDepositStatus::Held, $deposit->refresh()->status);
    }

    private function auctionWithoutBids(): array
    {
        $seller = $this->user();
        $terms = AuctionTermsVersion::create([
            'version_number' => ((int) AuctionTermsVersion::max('version_number')) + 1,
            'title' => 'Terms',
            'body' => 'Auction terms.',
            'is_active' => true,
            'published_at' => Carbon::now()->subDay(),
        ]);
        $category = Category::create(['name' => 'mysql-payment-cat-'.Str::ulid(), 'display_order' => 0]);
        $country = Country::create(['name' => 'mysql-payment-country-'.Str::ulid(), 'code' => strtoupper(substr((string) Str::ulid(), 0, 6))]);

        $auction = Auction::create([
            'seller_id' => $seller->id,
            'category_id' => $category->id,
            'country_id' => $country->id,
            'terms_version_id' => $terms->id,
            'configuration_version_id' => $this->auctionConfigurationVersion()->id,
            'currency_code' => 'JOD',
            'title' => 'MySQL payment uniqueness auction',
            'description' => 'MySQL payment uniqueness auction.',
            'status' => AuctionStatus::AwaitingSellerDeposit,
            'starting_amount_minor' => 10_000,
            'reserve_amount_minor' => null,
            'minimum_bid_increment_minor' => 500,
            'seller_deposit_amount_minor' => 2_000,
            'bidder_deposit_amount_minor' => 1_000,
            'platform_fee_type' => 'percentage',
            'platform_fee_basis_points' => 250,
            'platform_fee_fixed_minor' => 0,
            'winner_payment_deadline_hours' => 48,
            'handover_deadline_hours' => 72,
            'starts_at' => Carbon::now()->subDays(2),
            'original_ends_at' => Carbon::now()->subMinute(),
            'ends_at' => Carbon::now()->subMinute(),
        ]);

        $this->snapshotApprovedAuction($auction, $seller->id);

        return [$auction, $seller];
    }

    private function sellerDeposit(Auction $auction, User $seller): AuctionDeposit
    {
        return AuctionDeposit::create([
            'auction_id' => $auction->id,
            'user_id' => $seller->id,
            'type' => 'seller',
            'status' => AuctionDepositStatus::PendingReview,
            'required_amount_minor' => $auction->seller_deposit_amount_minor,
            'held_amount_minor' => 0,
            'currency_code' => $auction->currency_code,
        ]);
    }

    private function depositSubmission(Auction $auction, User $seller, AuctionDeposit $deposit, string $idempotencyKey): PaymentSubmission
    {
        return PaymentSubmission::create([
            'auction_id' => $auction->id,
            'deposit_id' => $deposit->id,
            'user_id' => $seller->id,
            'payment_method_id' => $this->paymentMethod()->id,
            'purpose' => PaymentPurpose::SellerDeposit,
            'status' => PaymentSubmissionStatus::PendingReview,
            'amount_minor' => $auction->seller_deposit_amount_minor,
            'currency_code' => $auction->currency_code,
            'receipt_disk' => 'spaces_private',
            'receipt_path' => $idempotencyKey.'.pdf',
            'receipt_mime_type' => 'application/pdf',
            'receipt_size_bytes' => 100,
            'idempotency_key' => $idempotencyKey,
            'submitted_at' => Carbon::now(),
        ]);
    }

    private function paymentMethod(): PaymentMethod
    {
        return PaymentMethod::create([
            'name' => 'Manual bank transfer',
            'code' => 'mysql-manual-'.Str::ulid(),
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
            'email' => "auction-payment-mysql-{$unique}@example.test",
            'phone' => '+96276'.$phoneSuffix,
            'password' => Hash::make('password'),
            'role' => $role,
            'email_verified_at' => Carbon::now(),
        ]);
    }

    private function approvalProcess(string $worker, string $barrier, string $result, int $submissionId, int $adminId): Process
    {
        return new Process(
            [PHP_BINARY, $worker, base_path(), (string) $submissionId, (string) $adminId, $barrier, $result],
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

    private function approvalWorkerScript(): string
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
        ->approve($submission, (int) $adminId, 'parallel obligation approval');

    file_put_contents($result, 'approved');
    exit(0);
} catch (App\Domain\Auction\Exceptions\AuctionException $exception) {
    if ($exception->getMessage() === __('auction.errors.payment_obligation_already_paid')) {
        file_put_contents($result, 'already_paid');
        exit(0);
    }

    file_put_contents($result, get_class($exception).': '.$exception->getMessage());
    exit(1);
} catch (Throwable $exception) {
    file_put_contents($result, get_class($exception).': '.$exception->getMessage());
    exit(1);
}
PHP;
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
