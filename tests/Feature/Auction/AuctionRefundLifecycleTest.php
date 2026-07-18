<?php

declare(strict_types=1);

namespace Tests\Feature\Auction;

use App\Domain\Auction\Enums\AuctionDepositStatus;
use App\Domain\Auction\Enums\AuctionStatus;
use App\Domain\Auction\Enums\PaymentPurpose;
use App\Domain\Auction\Enums\PaymentSubmissionStatus;
use App\Domain\Auction\Enums\PaymentTransactionStatus;
use App\Domain\Auction\Enums\RefundTransactionStatus;
use App\Domain\Auction\Exceptions\AuctionException;
use App\DTO\Auction\RefundProcessingResult;
use App\Models\Auction\Auction;
use App\Models\Auction\AuctionActivityLog;
use App\Models\Auction\AuctionDeposit;
use App\Models\Auction\AuctionParticipant;
use App\Models\Auction\AuctionTermsVersion;
use App\Models\Auction\OutboxMessage;
use App\Models\Auction\PaymentMethod;
use App\Models\Auction\PaymentSubmission;
use App\Models\Auction\PaymentTransaction;
use App\Models\Auction\RefundTransaction;
use App\Models\Category;
use App\Models\Country;
use App\Models\User;
use App\Services\Auction\Actions\CancelAuctionRefundAction;
use App\Services\Auction\Actions\ConfirmAuctionRefundManuallyAction;
use App\Services\Auction\Actions\ProcessAuctionRefundAction;
use App\Services\Auction\Actions\RefundAuctionDepositAction;
use App\Services\Auction\Refunds\AuctionRefundProcessorInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AuctionRefundLifecycleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate', ['--force' => true]);
    }

    public function test_pending_refund_is_claimed_as_processing_with_attempt_and_lease(): void
    {
        [$refund] = $this->plannedRefund();
        $this->bindProcessor(new InspectingRefundProcessor(function (RefundTransaction $refund): void {
            $fresh = $refund->refresh();
            $this->assertSame(RefundTransactionStatus::Processing, $fresh->status);
            $this->assertSame(1, $fresh->attempt_count);
            $this->assertNotNull($fresh->processing_token);
            $this->assertTrue($fresh->lease_expires_at->isFuture());
        }, RefundProcessingResult::manualReviewRequired('manual', 'manual review required')));

        app(ProcessAuctionRefundAction::class)->execute($refund);

        $this->assertSame(RefundTransactionStatus::ManualReview, $refund->refresh()->status);
    }

    public function test_provider_success_completes_refund_once_with_audit_and_outbox(): void
    {
        [$refund, $deposit, $payment] = $this->plannedRefund();
        $this->bindProcessor(new StaticRefundProcessor(RefundProcessingResult::succeeded('provider-ref-1', ['ok' => true])));

        $processed = app(ProcessAuctionRefundAction::class)->execute($refund);
        app(RefundAuctionDepositAction::class)->confirmSucceeded($processed->refresh(), 'provider-ref-1');

        $deposit->refresh();
        $this->assertSame(RefundTransactionStatus::Succeeded, $processed->refresh()->status);
        $this->assertSame('provider-ref-1', $processed->provider_refund_id);
        $this->assertSame(10_000, $deposit->refunded_amount_minor);
        $this->assertSame(0, $deposit->held_amount_minor);
        $this->assertSame(PaymentTransactionStatus::Reversed, $payment->refresh()->status);
        $this->assertSame(1, AuctionActivityLog::where('auction_id', $processed->auction_id)->where('event_type', 'auction.refund_succeeded')->count());
        $this->assertSame(1, OutboxMessage::where('aggregate_id', $processed->auction_id)->where('event_type', 'auction.refund_succeeded')->count());
    }

    public function test_retryable_failure_records_backoff_without_financial_changes(): void
    {
        config(['auction.refunds.backoff_seconds' => [10, 20, 30], 'auction.refunds.max_attempts' => 5]);
        [$refund, $deposit, $payment] = $this->plannedRefund();
        $this->bindProcessor(new StaticRefundProcessor(RefundProcessingResult::retryableFailure('timeout', 'provider timeout')));

        app(ProcessAuctionRefundAction::class)->execute($refund);

        $refund->refresh();
        $this->assertSame(RefundTransactionStatus::Failed, $refund->status);
        $this->assertSame(1, $refund->attempt_count);
        $this->assertTrue($refund->next_retry_at->betweenIncluded(now()->addSeconds(8), now()->addSeconds(12)));
        $this->assertSame(0, $deposit->refresh()->refunded_amount_minor);
        $this->assertSame(PaymentTransactionStatus::Succeeded, $payment->refresh()->status);
    }

    public function test_max_attempts_moves_retryable_failure_to_manual_review(): void
    {
        config(['auction.refunds.max_attempts' => 2]);
        [$refund] = $this->plannedRefund();
        $refund->forceFill(['attempt_count' => 1])->save();
        $this->bindProcessor(new StaticRefundProcessor(RefundProcessingResult::retryableFailure('timeout', 'provider timeout')));

        app(ProcessAuctionRefundAction::class)->execute($refund);

        $this->assertSame(RefundTransactionStatus::ManualReview, $refund->refresh()->status);
        $this->assertNull($refund->next_retry_at);
    }

    public function test_non_retryable_failure_moves_to_manual_review(): void
    {
        [$refund] = $this->plannedRefund();
        $this->bindProcessor(new StaticRefundProcessor(RefundProcessingResult::nonRetryableFailure('unsupported', 'refund not supported')));

        app(ProcessAuctionRefundAction::class)->execute($refund);

        $this->assertSame(RefundTransactionStatus::ManualReview, $refund->refresh()->status);
        $this->assertSame(1, OutboxMessage::where('aggregate_id', $refund->auction_id)->where('event_type', 'auction.refund_manual_review')->count());
    }

    public function test_expired_lease_can_be_reclaimed_and_old_token_cannot_complete(): void
    {
        [$refund] = $this->plannedRefund();
        $refund->forceFill([
            'status' => RefundTransactionStatus::Processing,
            'attempt_count' => 1,
            'processing_token' => 'old-token',
            'lease_expires_at' => Carbon::now()->subMinute(),
        ])->save();
        $this->bindProcessor(new TokenReplacingRefundProcessor('newer-token'));

        try {
            app(ProcessAuctionRefundAction::class)->execute($refund);
            $this->fail('Expected stale processing token to be rejected.');
        } catch (AuctionException $exception) {
            $this->assertSame(__('auction.errors.refund_processing_token_mismatch'), $exception->getMessage());
        }

        $this->assertSame('newer-token', $refund->refresh()->processing_token);
    }

    public function test_manual_confirmation_requires_reference_and_reason(): void
    {
        [$refund] = $this->plannedRefund();
        $refund->forceFill(['status' => RefundTransactionStatus::ManualReview])->save();
        $admin = $this->user('admin');

        $this->expectException(AuctionException::class);
        $this->expectExceptionMessage(__('auction.errors.refund_manual_confirmation_reason_required'));
        app(ConfirmAuctionRefundManuallyAction::class)->execute($refund, $admin, '', '');
    }

    public function test_manual_confirmation_is_idempotent(): void
    {
        [$refund, $deposit] = $this->plannedRefund();
        $refund->forceFill(['status' => RefundTransactionStatus::ManualReview])->save();
        $admin = $this->user('admin');

        $completed = app(ConfirmAuctionRefundManuallyAction::class)->execute($refund->refresh(), $admin, 'manual-ref-1', 'bank transfer completed');
        app(ConfirmAuctionRefundManuallyAction::class)->execute($completed->refresh(), $admin, 'manual-ref-1', 'bank transfer completed');

        $this->assertSame(RefundTransactionStatus::Succeeded, $completed->refresh()->status);
        $this->assertSame($admin->id, $completed->manual_confirmed_by);
        $this->assertSame(10_000, $deposit->refresh()->refunded_amount_minor);
    }

    public function test_manual_confirmation_rejects_admin_without_dedicated_permission(): void
    {
        config(['auction.admin_permissions' => []]);
        [$refund] = $this->plannedRefund();
        $refund->forceFill(['status' => RefundTransactionStatus::ManualReview])->save();

        $this->expectException(AuctionException::class);
        $this->expectExceptionMessage(__('auction.errors.refund_manual_confirmation_unauthorized'));

        app(ConfirmAuctionRefundManuallyAction::class)->execute($refund, $this->user('admin'), 'manual-ref-denied', 'paid externally');
    }

    public function test_cancel_refund_allows_pending_failed_manual_review_and_rejects_terminal_or_processing(): void
    {
        [$pending] = $this->plannedRefund(idSuffix: 'pending');
        [$failed] = $this->plannedRefund(idSuffix: 'failed');
        [$manual] = $this->plannedRefund(idSuffix: 'manual');
        [$processing] = $this->plannedRefund(idSuffix: 'processing');
        $admin = $this->user('admin');

        $failed->forceFill(['status' => RefundTransactionStatus::Failed])->save();
        $manual->forceFill(['status' => RefundTransactionStatus::ManualReview])->save();
        $processing->forceFill([
            'status' => RefundTransactionStatus::Processing,
            'processing_token' => 'processing-token',
            'lease_expires_at' => now()->addMinute(),
        ])->save();

        foreach ([$pending, $failed, $manual] as $refund) {
            $cancelled = app(CancelAuctionRefundAction::class)->execute($refund, $admin, 'duplicate refund plan');
            $this->assertSame(RefundTransactionStatus::Cancelled, $cancelled->status);
        }

        $this->expectException(AuctionException::class);
        $this->expectExceptionMessage(__('auction.errors.refund_cancellation_not_allowed'));
        app(CancelAuctionRefundAction::class)->execute($processing, $admin, 'cancel active processing');
    }

    public function test_duplicate_provider_reference_is_rejected(): void
    {
        [$first] = $this->plannedRefund(idSuffix: 'first');
        [$second] = $this->plannedRefund(idSuffix: 'second');
        $admin = $this->user('admin');
        $first->forceFill(['status' => RefundTransactionStatus::ManualReview])->save();
        $second->forceFill(['status' => RefundTransactionStatus::ManualReview])->save();

        app(ConfirmAuctionRefundManuallyAction::class)->execute($first, $admin, 'same-provider-ref', 'paid externally');

        $this->expectException(AuctionException::class);
        $this->expectExceptionMessage(__('auction.errors.duplicate_provider_refund'));
        app(ConfirmAuctionRefundManuallyAction::class)->execute($second, $admin, 'same-provider-ref', 'paid externally');
    }

    public function test_admin_refund_index_lists_safe_fields_and_denies_unpermitted_admin(): void
    {
        [$refund] = $this->plannedRefund();
        $admin = $this->user('admin');

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/auctions/refunds?status=pending&per_page=10')
            ->assertOk()
            ->assertJsonFragment(['public_id' => $refund->public_id]);

        $this->assertStringNotContainsString('provider_response', $response->getContent());

        config(['auction.admin_permissions' => []]);

        $this->actingAs($this->user('admin'), 'sanctum')
            ->getJson('/api/admin/auctions/refunds')
            ->assertForbidden();
    }

    public function test_manual_refund_confirm_endpoint_is_idempotent(): void
    {
        [$refund, $deposit] = $this->plannedRefund();
        $refund->forceFill(['status' => RefundTransactionStatus::ManualReview])->save();
        $admin = $this->user('admin');
        $payload = [
            'confirmation_reference' => 'manual-api-ref-1',
            'reason' => 'bank transfer completed',
        ];

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/auctions/refunds/{$refund->public_id}/confirm", $payload)
            ->assertOk()
            ->assertJsonPath('data.status', RefundTransactionStatus::Succeeded->value);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/auctions/refunds/{$refund->public_id}/confirm", $payload)
            ->assertOk()
            ->assertJsonPath('data.status', RefundTransactionStatus::Succeeded->value);

        $this->assertSame(10_000, $deposit->refresh()->refunded_amount_minor);
        $this->assertSame(0, $deposit->held_amount_minor);
    }

    public function test_cancel_refund_endpoint_restores_deposit_and_allows_new_refund(): void
    {
        [$refund, $deposit] = $this->plannedRefund();
        $admin = $this->user('admin');

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/auctions/refunds/{$refund->public_id}/cancel", [
                'reason' => 'duplicate refund plan',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', RefundTransactionStatus::Cancelled->value);

        $this->assertSame(AuctionDepositStatus::Held, $deposit->refresh()->status);

        $newRefund = app(RefundAuctionDepositAction::class)->execute($deposit->refresh(), 'retry after cancellation');

        $this->assertNotSame($refund->id, $newRefund->id);
        $this->assertSame(RefundTransactionStatus::Pending, $newRefund->status);
        $this->assertSame(AuctionDepositStatus::RefundPending, $deposit->refresh()->status);
    }

    public function test_cancel_succeeded_refund_endpoint_is_rejected(): void
    {
        [$refund] = $this->plannedRefund();
        $refund->forceFill(['status' => RefundTransactionStatus::ManualReview])->save();
        $admin = $this->user('admin');

        app(ConfirmAuctionRefundManuallyAction::class)->execute($refund, $admin, 'manual-api-ref-success', 'paid externally');

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/auctions/refunds/{$refund->public_id}/cancel", [
                'reason' => 'cancel after success',
            ])
            ->assertStatus(422);
    }

    private function bindProcessor(AuctionRefundProcessorInterface $processor): void
    {
        $this->app->instance(AuctionRefundProcessorInterface::class, $processor);
    }

    private function plannedRefund(string $idSuffix = ''): array
    {
        [$auction, $user, $participant] = $this->auction();
        $deposit = AuctionDeposit::create([
            'auction_id' => $auction->id,
            'participant_id' => $participant->id,
            'user_id' => $user->id,
            'type' => 'bidder',
            'status' => AuctionDepositStatus::Held,
            'required_amount_minor' => 10_000,
            'held_amount_minor' => 10_000,
            'currency_code' => 'JOD',
            'held_at' => now()->subHour(),
        ]);
        $payment = $this->paymentForDeposit($auction, $deposit, $user, 10_000, $idSuffix);

        $refund = app(RefundAuctionDepositAction::class)->execute($deposit, 'lifecycle refund '.$idSuffix);

        return [$refund, $deposit, $payment, $auction, $user];
    }

    private function auction(): array
    {
        $seller = $this->user();
        $bidder = $this->user();
        $terms = AuctionTermsVersion::create([
            'version_number' => ((int) AuctionTermsVersion::max('version_number')) + 1,
            'title' => 'Terms',
            'body' => 'Auction terms.',
            'is_active' => true,
            'published_at' => now()->subDay(),
        ]);
        $category = Category::create(['name' => 'refund-lifecycle-cat-'.Str::ulid(), 'display_order' => 0]);
        $country = Country::create(['name' => 'refund-lifecycle-country-'.Str::ulid(), 'code' => strtoupper(substr((string) Str::ulid(), 0, 6))]);
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
            'title' => 'Refund lifecycle auction',
            'description' => 'Refund lifecycle auction.',
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
        $participant = AuctionParticipant::create([
            'auction_id' => $auction->id,
            'user_id' => $bidder->id,
            'status' => 'qualified',
            'registered_at' => now()->subDay(),
            'qualified_at' => now()->subHour(),
        ]);

        return [$auction, $bidder, $participant];
    }

    private function paymentForDeposit(Auction $auction, AuctionDeposit $deposit, User $user, int $amount, string $idSuffix): PaymentTransaction
    {
        $method = PaymentMethod::create([
            'name' => 'Manual transfer',
            'code' => 'refund-lifecycle-'.Str::ulid().$idSuffix,
            'instructions' => 'Upload receipt.',
            'requires_manual_review' => true,
            'is_active' => true,
        ]);
        $submission = PaymentSubmission::create([
            'auction_id' => $auction->id,
            'deposit_id' => $deposit->id,
            'user_id' => $user->id,
            'payment_method_id' => $method->id,
            'purpose' => PaymentPurpose::BidderDeposit,
            'status' => PaymentSubmissionStatus::Approved,
            'amount_minor' => $amount,
            'currency_code' => 'JOD',
            'receipt_disk' => 'spaces_private',
            'receipt_path' => 'deposit.pdf',
            'receipt_mime_type' => 'application/pdf',
            'receipt_size_bytes' => 100,
            'idempotency_key' => 'deposit-'.Str::ulid().$idSuffix,
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
            'provider_transaction_id' => 'provider-'.Str::ulid().$idSuffix,
            'idempotency_key' => 'payment-'.Str::ulid().$idSuffix,
            'successful_obligation_key' => "deposit:{$deposit->id}",
            'processed_at' => now()->subHour(),
        ]);
    }

    private function user(string $role = 'user'): User
    {
        $unique = strtolower((string) Str::ulid());
        $phoneSuffix = str_pad((string) (abs(crc32($unique)) % 10_000_000), 7, '0', STR_PAD_LEFT);

        return User::create([
            'name' => fake()->name(),
            'email' => "refund-lifecycle-{$unique}@example.test",
            'phone' => '+96275'.$phoneSuffix,
            'password' => Hash::make('password'),
            'role' => $role,
            'email_verified_at' => now(),
        ]);
    }
}

final readonly class StaticRefundProcessor implements AuctionRefundProcessorInterface
{
    public function __construct(private RefundProcessingResult $result) {}

    public function process(RefundTransaction $refund): RefundProcessingResult
    {
        return $this->result;
    }
}

final readonly class InspectingRefundProcessor implements AuctionRefundProcessorInterface
{
    public function __construct(private \Closure $inspect, private RefundProcessingResult $result) {}

    public function process(RefundTransaction $refund): RefundProcessingResult
    {
        ($this->inspect)($refund);

        return $this->result;
    }
}

final readonly class TokenReplacingRefundProcessor implements AuctionRefundProcessorInterface
{
    public function __construct(private string $replacementToken) {}

    public function process(RefundTransaction $refund): RefundProcessingResult
    {
        DB::table('refund_transactions')
            ->where('id', $refund->id)
            ->update(['processing_token' => $this->replacementToken]);

        return RefundProcessingResult::succeeded('stale-provider-ref');
    }
}
