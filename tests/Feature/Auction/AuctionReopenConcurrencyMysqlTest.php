<?php

declare(strict_types=1);

namespace Tests\Feature\Auction;

use App\Domain\Auction\Enums\AuctionStatus;
use App\Models\Auction\Auction;
use App\Models\Auction\AuctionConfigurationVersion;
use App\Models\Auction\AuctionStatusHistory;
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
final class AuctionReopenConcurrencyMysqlTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('MySQL-only auction reopen concurrency test.');
        }

        if (! str_ends_with((string) DB::connection()->getDatabaseName(), '_testing')) {
            $this->markTestSkipped('Refusing to run reopen concurrency test outside a *_testing database.');
        }

        Artisan::call('migrate', ['--force' => true]);
    }

    public function test_parallel_reopen_performs_exactly_one_transition(): void
    {
        $auction = $this->auction();
        $workDir = storage_path('framework/testing/auction-reopen-'.Str::ulid());
        mkdir($workDir, 0777, true);

        $worker = $workDir.DIRECTORY_SEPARATOR.'reopen-auction-worker.php';
        $barrier = $workDir.DIRECTORY_SEPARATOR.'go';
        $firstResult = $workDir.DIRECTORY_SEPARATOR.'first-result.txt';
        $secondResult = $workDir.DIRECTORY_SEPARATOR.'second-result.txt';
        file_put_contents($worker, $this->workerScript());

        $first = $this->process($worker, $barrier, $firstResult, $auction->id, $auction->seller_id);
        $second = $this->process($worker, $barrier, $secondResult, $auction->id, $auction->seller_id);

        $first->start();
        $second->start();
        usleep(200_000);
        touch($barrier);

        $first->wait();
        $second->wait();

        $outcomes = [file_get_contents($firstResult), file_get_contents($secondResult)];
        sort($outcomes);

        $this->assertSame(AuctionStatus::Draft, $auction->refresh()->status);
        $this->assertSame(
            1,
            AuctionStatusHistory::where('auction_id', $auction->id)->where('to_status', 'draft')->count()
        );
        $this->assertStringContainsString('auction_not_reopenable', $outcomes[0]);
        $this->assertSame('ok', $outcomes[1]);
    }

    private function process(string $worker, string $barrier, string $result, int $auctionId, int $sellerId): Process
    {
        return new Process(
            [PHP_BINARY, $worker, base_path(), (string) $auctionId, (string) $sellerId, $barrier, $result],
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

[$script, $basePath, $auctionId, $sellerId, $barrier, $result] = $argv;

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
    app(App\Services\Auction\Actions\ReopenRejectedAuctionAction::class)
        ->execute($auction, (int) $sellerId);

    file_put_contents($result, 'ok');
    exit(0);
} catch (App\Domain\Auction\Exceptions\AuctionException $exception) {
    file_put_contents($result, (string) $exception->getErrorCode());
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
            'body' => 'Reopen MySQL terms.',
            'is_active' => true,
            'published_at' => now()->subDay(),
        ]);

        return Auction::create([
            'seller_id' => $this->user()->id,
            'category_id' => Category::create(['name' => 'reopen-mysql-cat-'.Str::ulid(), 'display_order' => 0])->id,
            'country_id' => Country::create(['name' => 'reopen-mysql-country-'.Str::ulid(), 'code' => strtoupper(substr((string) Str::ulid(), 0, 6))])->id,
            'terms_version_id' => $terms->id,
            'configuration_version_id' => $version->id,
            'currency_code' => 'JOD',
            'title' => 'Reopen MySQL auction',
            'description' => 'Reopen MySQL auction.',
            'status' => AuctionStatus::Rejected,
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
            'name' => 'Reopen MySQL User',
            'email' => "reopen-mysql-{$unique}@example.test",
            'phone' => '+96279'.str_pad((string) (abs(crc32($unique)) % 10_000_000), 7, '0', STR_PAD_LEFT),
            'password' => Hash::make('password'),
            'role' => $role,
            'email_verified_at' => now(),
        ]);
    }
}
