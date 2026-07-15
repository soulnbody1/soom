<?php

declare(strict_types=1);

namespace Tests\Feature\Auction;

use App\Domain\Auction\Enums\AuctionDepositStatus;
use App\Domain\Auction\Enums\AuctionStatus;
use App\Domain\Auction\Enums\PaymentPurpose;
use App\Domain\Auction\Enums\PaymentSubmissionStatus;
use App\Domain\Auction\Enums\PaymentTransactionStatus;
use App\Domain\Auction\Enums\SettlementStatus;
use App\Models\Auction\Auction;
use App\Models\Auction\AuctionBid;
use App\Models\Auction\AuctionDeposit;
use App\Models\Auction\AuctionParticipant;
use App\Models\Auction\AuctionSettlement;
use App\Models\Auction\AuctionTermsVersion;
use App\Models\Auction\PaymentMethod;
use App\Models\Auction\PaymentSubmission;
use App\Models\Auction\PaymentTransaction;
use App\Models\Auction\RefundTransaction;
use App\Models\Category;
use App\Models\Country;
use App\Models\User;
use App\Services\Auction\Actions\RefundAuctionDepositAction;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Process;
use Tests\TestCase;

#[Group('mysql-concurrency')]
final class AppliedDepositRefundMysqlTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') !== 'mysql') {
            $this->markTestSkipped('MySQL-only applied deposit refund concurrency test.');
        }

        $database = (string) config('database.connections.mysql.database');
        $this->assertStringEndsWith('_testing', $database);
        $this->recreateTestingDatabase($database);

        DB::purge('mysql');
        DB::reconnect('mysql');

        Artisan::call('migrate', ['--force' => true]);
    }

    public function test_parallel_confirmation_refunds_applied_amount_once(): void
    {
        [$auction, $winner, $bid] = $this->auctionWithBid();
        $deposit = $this->deposit($auction, $winner, 10_000);
        $this->settlement($auction, $bid);
        $this->paymentForDeposit($auction, $deposit, $winner, 10_000);
        $refund = app(RefundAuctionDepositAction::class)->execute($deposit, 'parallel applied refund');

        $providerRefundId = 'mysql-applied-refund-'.Str::ulid();
        $workDir = storage_path('framework/testing/applied-refund-'.Str::ulid());
        mkdir($workDir, 0777, true);

        $worker = $workDir.DIRECTORY_SEPARATOR.'confirm-applied-refund-worker.php';
        $barrier = $workDir.DIRECTORY_SEPARATOR.'go';
        $firstResult = $workDir.DIRECTORY_SEPARATOR.'first-result.txt';
        $secondResult = $workDir.DIRECTORY_SEPARATOR.'second-result.txt';
        file_put_contents($worker, $this->workerScript());

        $first = $this->confirmProcess($worker, $barrier, $firstResult, $refund->id, $providerRefundId);
        $second = $this->confirmProcess($worker, $barrier, $secondResult, $refund->id, $providerRefundId);

        $first->start();
        $second->start();
        usleep(200_000);
        touch($barrier);

        $first->wait();
        $second->wait();

        $this->assertTrue($first->isSuccessful(), $first->getErrorOutput().file_get_contents($firstResult));
        $this->assertTrue($second->isSuccessful(), $second->getErrorOutput().file_get_contents($secondResult));
        $this->assertSame('ok', trim(file_get_contents($firstResult)));
        $this->assertSame('ok', trim(file_get_contents($secondResult)));

        $deposit->refresh();
        $this->assertSame(10_000, $deposit->refunded_amount_minor);
        $this->assertSame(0, $deposit->applied_amount_minor);
        $this->assertSame(0, $deposit->held_amount_minor);
        $this->assertSame(1, RefundTransaction::where('deposit_id', $deposit->id)->where('status', 'succeeded')->count());
        $this->assertSame($providerRefundId, $refund->refresh()->provider_refund_id);
    }

    private function confirmProcess(string $worker, string $barrier, string $result, int $refundId, string $providerRefundId): Process
    {
        return new Process(
            [PHP_BINARY, $worker, base_path(), (string) $refundId, $providerRefundId, $barrier, $result],
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

[$script, $basePath, $refundId, $providerRefundId, $barrier, $result] = $argv;

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
    $refund = App\Models\Auction\RefundTransaction::findOrFail((int) $refundId);
    app(App\Services\Auction\Actions\RefundAuctionDepositAction::class)
        ->confirmSucceeded($refund, $providerRefundId);

    file_put_contents($result, 'ok');
    exit(0);
} catch (Throwable $exception) {
    file_put_contents($result, get_class($exception).': '.$exception->getMessage());
    exit(1);
}
PHP;
    }

    private function auctionWithBid(): array
    {
        $seller = $this->user();
        $winner = $this->user();
        $terms = AuctionTermsVersion::create([
            'version_number' => ((int) AuctionTermsVersion::max('version_number')) + 1,
            'title' => 'Terms',
            'body' => 'Auction terms.',
            'is_active' => true,
            'published_at' => now()->subDay(),
        ]);
        $category = Category::create(['name' => 'mysql-applied-refund-cat-'.Str::ulid(), 'display_order' => 0]);
        $country = Country::create(['name' => 'mysql-applied-refund-country-'.Str::ulid(), 'code' => strtoupper(substr((string) Str::ulid(), 0, 6))]);

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
            'title' => 'MySQL applied refund auction',
            'description' => 'MySQL applied refund auction.',
            'status' => AuctionStatus::Cancelled,
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
            'original_ends_at' => now()->subHour(),
            'ends_at' => now()->subHour(),
        ]);

        $this->snapshotApprovedAuction($auction, $seller->id);

        $participant = AuctionParticipant::create([
            'auction_id' => $auction->id,
            'user_id' => $winner->id,
            'status' => 'qualified',
            'registered_at' => now()->subDays(2),
            'qualified_at' => now()->subDay(),
        ]);
        $bid = AuctionBid::create([
            'auction_id' => $auction->id,
            'participant_id' => $participant->id,
            'bidder_id' => $winner->id,
            'amount_minor' => 100_000,
            'currency_code' => 'JOD',
            'sequence_number' => 1,
            'idempotency_key' => 'mysql-applied-bid-'.Str::ulid(),
            'server_received_at' => now()->subHour(),
            'accepted_at' => now()->subHour(),
        ]);
        $auction->forceFill(['winning_bid_id' => $bid->id])->save();

        return [$auction->refresh(), $winner, $bid];
    }

    private function deposit(Auction $auction, User $user, int $amount): AuctionDeposit
    {
        return AuctionDeposit::create([
            'auction_id' => $auction->id,
            'user_id' => $user->id,
            'type' => 'bidder',
            'status' => AuctionDepositStatus::AppliedToSettlement,
            'required_amount_minor' => $amount,
            'held_amount_minor' => 0,
            'applied_amount_minor' => $amount,
            'currency_code' => 'JOD',
            'held_at' => now()->subHour(),
        ]);
    }

    private function settlement(Auction $auction, AuctionBid $bid): AuctionSettlement
    {
        return AuctionSettlement::create([
            'auction_id' => $auction->id,
            'winning_bid_id' => $bid->id,
            'winner_id' => $bid->bidder_id,
            'sequence_number' => 1,
            'is_current' => true,
            'current_marker' => 1,
            'status' => SettlementStatus::Cancelled,
            'winning_amount_minor' => 100_000,
            'deposit_applied_minor' => 10_000,
            'platform_fee_minor' => 2_500,
            'seller_net_amount_minor' => 97_500,
            'amount_due_minor' => 90_000,
            'amount_paid_minor' => 0,
            'remaining_amount_minor' => 90_000,
            'currency_code' => 'JOD',
            'payment_due_at' => now()->subDay(),
        ]);
    }

    private function paymentForDeposit(Auction $auction, AuctionDeposit $deposit, User $user, int $amount): PaymentTransaction
    {
        $submission = PaymentSubmission::create([
            'auction_id' => $auction->id,
            'deposit_id' => $deposit->id,
            'user_id' => $user->id,
            'payment_method_id' => $this->paymentMethod()->id,
            'purpose' => PaymentPurpose::BidderDeposit,
            'status' => PaymentSubmissionStatus::Approved,
            'amount_minor' => $amount,
            'currency_code' => 'JOD',
            'receipt_disk' => 'spaces_private',
            'receipt_path' => 'mysql-deposit.pdf',
            'receipt_mime_type' => 'application/pdf',
            'receipt_size_bytes' => 100,
            'idempotency_key' => 'mysql-deposit-'.Str::ulid(),
            'submitted_at' => now()->subHour(),
            'reviewed_at' => now()->subHour(),
        ]);

        return PaymentTransaction::create([
            'payment_submission_id' => $submission->id,
            'auction_id' => $auction->id,
            'user_id' => $user->id,
            'purpose' => PaymentPurpose::BidderDeposit,
            'status' => PaymentTransactionStatus::Succeeded,
            'amount_minor' => $amount,
            'currency_code' => 'JOD',
            'provider' => 'manual',
            'provider_transaction_id' => 'mysql-provider-'.Str::ulid(),
            'idempotency_key' => 'mysql-payment-'.Str::ulid(),
            'successful_obligation_key' => "deposit:{$deposit->id}",
            'processed_at' => now()->subHour(),
        ]);
    }

    private function paymentMethod(): PaymentMethod
    {
        return PaymentMethod::create([
            'name' => 'MySQL manual transfer',
            'code' => 'mysql-applied-refund-'.Str::ulid(),
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
            'email' => "mysql-applied-refund-{$unique}@example.test",
            'phone' => '+96274'.$phoneSuffix,
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
