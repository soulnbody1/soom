<?php

declare(strict_types=1);

namespace Tests\Feature\Auction;

use App\Domain\Auction\Enums\AuctionParticipantStatus;
use App\Domain\Auction\Enums\AuctionStatus;
use App\Domain\Auction\Enums\PaymentPurpose;
use App\Domain\Auction\Enums\PaymentSubmissionStatus;
use App\Domain\Auction\Enums\SettlementStatus;
use App\Domain\Auction\Exceptions\AuctionException;
use App\Models\Auction\Auction;
use App\Models\Auction\AuctionBid;
use App\Models\Auction\AuctionParticipant;
use App\Models\Auction\AuctionSettlement;
use App\Models\Auction\AuctionTermsVersion;
use App\Models\Auction\PaymentMethod;
use App\Models\Auction\PaymentSubmission;
use App\Models\Auction\PaymentTransaction;
use App\Models\Category;
use App\Models\Country;
use App\Models\User;
use App\Services\Auction\Actions\ReviewPaymentSubmissionAction;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('mysql-concurrency')]
final class PaymentStateDeadlineMysqlTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') !== 'mysql') {
            $this->markTestSkipped('MySQL-only payment stale settlement test.');
        }

        $database = (string) config('database.connections.mysql.database');
        $this->assertStringEndsWith('_testing', $database);
        $this->recreateTestingDatabase($database);

        DB::purge('mysql');
        DB::reconnect('mysql');

        Artisan::call('migrate', ['--force' => true]);
    }

    public function test_mysql_rejects_approval_for_stale_settlement_after_winner_changes(): void
    {
        [$auction, $winner, $oldBid] = $this->auctionWithBid(100_000, 1);
        $oldSettlement = $this->settlement($auction, $oldBid, 90_000);
        $submission = $this->submission($auction, $oldSettlement, $winner);

        [$newWinner, $newParticipant] = $this->participant($auction);
        $newBid = $this->bid($auction, $newParticipant, $newWinner, 95_000, 2);

        DB::transaction(function () use ($auction, $oldSettlement, $newBid): void {
            $oldSettlement->forceFill([
                'is_current' => false,
                'current_marker' => null,
                'superseded_at' => now(),
            ])->save();

            $auction->forceFill(['winning_bid_id' => $newBid->id])->save();
            $this->settlement($auction->refresh(), $newBid, 85_000, 2, $oldSettlement);
        });

        try {
            app(ReviewPaymentSubmissionAction::class)->approve($submission, $this->user('admin')->id, 'approve stale');
            $this->fail('Stale settlement approval should fail.');
        } catch (AuctionException $exception) {
            $this->assertSame(__('auction.errors.payment_target_not_current'), $exception->getMessage());
        }

        $this->assertSame(PaymentSubmissionStatus::PendingReview, $submission->refresh()->status);
        $this->assertSame(0, PaymentTransaction::where('payment_submission_id', $submission->id)->count());
        $this->assertSame(1, AuctionSettlement::where('auction_id', $auction->id)->where('current_marker', 1)->count());
    }

    private function auctionWithBid(int $amount, int $sequence): array
    {
        $seller = $this->user();
        $terms = AuctionTermsVersion::create([
            'version_number' => ((int) AuctionTermsVersion::max('version_number')) + 1,
            'title' => 'Terms',
            'body' => 'Auction terms.',
            'is_active' => true,
            'published_at' => now()->subDay(),
        ]);
        $category = Category::create(['name' => 'mysql-payment-state-cat-'.Str::ulid(), 'display_order' => 0]);
        $country = Country::create(['name' => 'mysql-payment-state-country-'.Str::ulid(), 'code' => strtoupper(substr((string) Str::ulid(), 0, 6))]);

        $auction = Auction::create([
            'seller_id' => $seller->id,
            'category_id' => $category->id,
            'country_id' => $country->id,
            'terms_version_id' => $terms->id,
            'currency_code' => 'JOD',
            'title' => 'MySQL payment state auction',
            'description' => 'MySQL payment state auction.',
            'status' => AuctionStatus::PaymentPending,
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
            'starts_at' => now()->subDays(2),
            'original_ends_at' => now()->subMinute(),
            'ends_at' => now()->subMinute(),
        ]);

        [$winner, $participant] = $this->participant($auction);
        $bid = $this->bid($auction, $participant, $winner, $amount, $sequence);
        $auction->forceFill(['winning_bid_id' => $bid->id])->save();

        return [$auction->refresh(), $winner, $bid];
    }

    private function participant(Auction $auction): array
    {
        $user = $this->user();
        $participant = AuctionParticipant::create([
            'auction_id' => $auction->id,
            'user_id' => $user->id,
            'status' => AuctionParticipantStatus::Qualified,
            'registered_at' => now()->subDay(),
            'qualified_at' => now()->subHour(),
        ]);

        return [$user, $participant];
    }

    private function bid(Auction $auction, AuctionParticipant $participant, User $user, int $amount, int $sequence): AuctionBid
    {
        return AuctionBid::create([
            'auction_id' => $auction->id,
            'participant_id' => $participant->id,
            'bidder_id' => $user->id,
            'amount_minor' => $amount,
            'currency_code' => 'JOD',
            'sequence_number' => $sequence,
            'idempotency_key' => 'mysql-payment-state-bid-'.Str::ulid(),
            'server_received_at' => now(),
            'accepted_at' => now(),
        ]);
    }

    private function settlement(
        Auction $auction,
        AuctionBid $bid,
        int $amountDue,
        int $sequence = 1,
        ?AuctionSettlement $previous = null
    ): AuctionSettlement {
        $winningAmount = (int) $bid->amount_minor;
        $depositApplied = $winningAmount - $amountDue;

        return AuctionSettlement::create([
            'auction_id' => $auction->id,
            'winning_bid_id' => $bid->id,
            'winner_id' => $bid->bidder_id,
            'sequence_number' => $sequence,
            'is_current' => true,
            'current_marker' => 1,
            'previous_settlement_id' => $previous?->id,
            'status' => SettlementStatus::PaymentPending,
            'winning_amount_minor' => $winningAmount,
            'deposit_applied_minor' => $depositApplied,
            'platform_fee_minor' => 2_500,
            'seller_net_amount_minor' => max(0, $winningAmount - 2_500),
            'amount_due_minor' => $amountDue,
            'amount_paid_minor' => 0,
            'remaining_amount_minor' => $amountDue,
            'currency_code' => 'JOD',
            'payment_due_at' => now()->addHour(),
        ]);
    }

    private function submission(Auction $auction, AuctionSettlement $settlement, User $winner): PaymentSubmission
    {
        return PaymentSubmission::create([
            'auction_id' => $auction->id,
            'settlement_id' => $settlement->id,
            'user_id' => $winner->id,
            'payment_method_id' => $this->paymentMethod()->id,
            'purpose' => PaymentPurpose::WinnerSettlement,
            'status' => PaymentSubmissionStatus::PendingReview,
            'amount_minor' => $settlement->remaining_amount_minor,
            'currency_code' => 'JOD',
            'receipt_disk' => 'spaces_private',
            'receipt_path' => 'mysql-stale-settlement.pdf',
            'receipt_mime_type' => 'application/pdf',
            'receipt_size_bytes' => 100,
            'idempotency_key' => 'mysql-stale-'.Str::ulid(),
            'submitted_at' => now(),
        ]);
    }

    private function paymentMethod(): PaymentMethod
    {
        return PaymentMethod::create([
            'name' => 'MySQL manual transfer',
            'code' => 'mysql-payment-state-'.Str::ulid(),
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
            'email' => "mysql-payment-state-{$unique}@example.test",
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
