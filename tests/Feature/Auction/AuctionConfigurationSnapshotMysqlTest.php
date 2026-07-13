<?php

declare(strict_types=1);

namespace Tests\Feature\Auction;

use App\Domain\Auction\Enums\AuctionStatus;
use App\Models\Auction\Auction;
use App\Models\Auction\AuctionConfigurationSnapshot;
use App\Models\Auction\AuctionConfigurationVersion;
use App\Models\Auction\AuctionDeposit;
use App\Models\Auction\AuctionTermsVersion;
use App\Models\Category;
use App\Models\Country;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Process;
use Tests\TestCase;

#[Group('mysql-concurrency')]
final class AuctionConfigurationSnapshotMysqlTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('MySQL-only auction configuration snapshot concurrency test.');
        }

        if (! str_ends_with((string) DB::connection()->getDatabaseName(), '_testing')) {
            $this->markTestSkipped('Refusing to run snapshot concurrency test outside a *_testing database.');
        }

        Artisan::call('migrate', ['--force' => true]);
    }

    public function test_parallel_approval_creates_one_snapshot_and_one_seller_deposit_obligation(): void
    {
        $auction = $this->auction();
        $admin = $this->user('admin');
        $workDir = storage_path('framework/testing/auction-snapshot-'.Str::ulid());
        mkdir($workDir, 0777, true);

        $worker = $workDir.DIRECTORY_SEPARATOR.'approve-auction-worker.php';
        $barrier = $workDir.DIRECTORY_SEPARATOR.'go';
        $firstResult = $workDir.DIRECTORY_SEPARATOR.'first-result.txt';
        $secondResult = $workDir.DIRECTORY_SEPARATOR.'second-result.txt';
        file_put_contents($worker, $this->workerScript());

        $first = $this->process($worker, $barrier, $firstResult, $auction->id, $admin->id);
        $second = $this->process($worker, $barrier, $secondResult, $auction->id, $admin->id);

        $first->start();
        $second->start();
        usleep(200_000);
        touch($barrier);

        $first->wait();
        $second->wait();

        $this->assertTrue($first->isSuccessful(), $first->getErrorOutput().file_get_contents($firstResult));
        $this->assertTrue($second->isSuccessful(), $second->getErrorOutput().file_get_contents($secondResult));
        $this->assertSame(1, AuctionConfigurationSnapshot::where('auction_id', $auction->id)->count());
        $this->assertSame(1, AuctionDeposit::where('auction_id', $auction->id)->where('type', 'seller')->count());
    }

    private function process(string $worker, string $barrier, string $result, int $auctionId, int $adminId): Process
    {
        return new Process(
            [PHP_BINARY, $worker, base_path(), (string) $auctionId, (string) $adminId, $barrier, $result],
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

[$script, $basePath, $auctionId, $adminId, $barrier, $result] = $argv;

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
    app(App\Services\Auction\Actions\ReviewAuctionAction::class)
        ->approve($auction, (int) $adminId, 'parallel approval');

    file_put_contents($result, 'ok');
    exit(0);
} catch (Throwable $exception) {
    file_put_contents($result, get_class($exception).': '.$exception->getMessage());
    exit(1);
}
PHP;
    }

    private function auction(): Auction
    {
        $version = AuctionConfigurationVersion::create([
            'version_number' => ((int) AuctionConfigurationVersion::max('version_number')) + 1,
            'is_active' => true,
            'published_at' => now()->subDay(),
            'configuration' => $this->configuration(),
        ]);
        $terms = AuctionTermsVersion::create([
            'version_number' => ((int) AuctionTermsVersion::max('version_number')) + 1,
            'title' => 'Terms',
            'body' => 'Snapshot MySQL terms.',
            'is_active' => true,
            'published_at' => now()->subDay(),
        ]);

        return Auction::create([
            'seller_id' => $this->user()->id,
            'category_id' => Category::create(['name' => 'snapshot-mysql-cat-'.Str::ulid(), 'display_order' => 0])->id,
            'country_id' => Country::create(['name' => 'snapshot-mysql-country-'.Str::ulid(), 'code' => strtoupper(substr((string) Str::ulid(), 0, 6))])->id,
            'terms_version_id' => $terms->id,
            'configuration_version_id' => $version->id,
            'currency_code' => 'JOD',
            'title' => 'Snapshot MySQL auction',
            'description' => 'Snapshot MySQL auction.',
            'status' => AuctionStatus::PendingReview,
            'starting_amount_minor' => 10_000,
            'reserve_amount_minor' => null,
            'minimum_bid_increment_minor' => 500,
            'seller_deposit_amount_minor' => 100,
            'bidder_deposit_amount_minor' => 10_000,
            'platform_fee_type' => 'percentage',
            'platform_fee_basis_points' => 500,
            'platform_fee_fixed_minor' => 0,
            'winner_payment_deadline_hours' => 48,
            'handover_deadline_hours' => 72,
            'starts_at' => now()->addDay(),
            'original_ends_at' => now()->addDays(2),
            'ends_at' => now()->addDays(2),
        ]);
    }

    private function configuration(): array
    {
        return [
            'seller_deposit_minor' => 100,
            'bidder_deposit_minor' => 10_000,
            'platform_fee_type' => 'percentage',
            'platform_fee_basis_points' => 500,
            'platform_fee_fixed_minor' => 0,
            'minimum_bid_increment_minor' => 500,
            'extension_window_seconds' => 300,
            'extension_duration_seconds' => 600,
            'maximum_extension_count' => 6,
            'winner_payment_deadline_hours' => 48,
            'handover_deadline_hours' => 72,
            'non_winner_deposit_policy' => 'hold_top_n_bidders_until_winner_payment',
            'non_winner_deposit_hold_count' => 1,
            'winner_default_deposit_policy' => ['disposition' => 'full_forfeit', 'forfeit_amount_minor' => 0],
            'seller_deposit_policy' => [
                'auction_rejected' => 'refund',
                'unsold' => 'refund',
                'completed' => 'refund',
                'seller_cancellation_before_start' => 'refund',
                'seller_cancellation_after_start' => 'manual_review',
                'admin_cancellation_platform_fault' => 'refund',
                'admin_cancellation_seller_fault' => 'forfeit',
                'admin_cancellation_neutral' => 'refund',
                'admin_cancellation_fraud_or_compliance' => 'manual_review',
                'system_cancellation_platform_fault' => 'refund',
                'system_cancellation_seller_fault' => 'forfeit',
                'system_cancellation_neutral' => 'refund',
                'winner_default' => 'keep_held',
                'seller_breach' => 'forfeit',
                'dispute_complete' => 'refund',
                'dispute_cancel' => 'manual_review',
                'dispute_resume_handover' => 'keep_held',
            ],
        ];
    }

    private function user(string $role = 'user'): User
    {
        $unique = strtolower((string) Str::ulid());

        return User::create([
            'name' => 'Snapshot MySQL User',
            'email' => "snapshot-mysql-{$unique}@example.test",
            'phone' => '+96277'.str_pad((string) (abs(crc32($unique)) % 10_000_000), 7, '0', STR_PAD_LEFT),
            'password' => Hash::make('password'),
            'role' => $role,
            'email_verified_at' => now(),
        ]);
    }
}
