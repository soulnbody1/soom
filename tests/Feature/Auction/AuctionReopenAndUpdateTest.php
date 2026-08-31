<?php

declare(strict_types=1);

namespace Tests\Feature\Auction;

use App\Domain\Auction\Enums\AuctionStatus;
use App\DTO\Auction\UpdateDraftAuctionInputDTO;
use App\Models\Auction\Auction;
use App\Models\Auction\AuctionActivityLog;
use App\Models\Auction\AuctionConfigurationVersion;
use App\Models\Auction\AuctionMedia;
use App\Models\Auction\AuctionStatusHistory;
use App\Models\Auction\AuctionTermsVersion;
use App\Models\Category;
use App\Models\Country;
use App\Models\User;
use App\Services\Auction\Actions\ReopenRejectedAuctionAction;
use App\Services\Auction\Actions\ReviewAuctionAction;
use App\Services\Auction\Actions\SubmitAuctionForReviewAction;
use App\Services\Auction\Actions\UpdateDraftAuctionAction;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Auction\Concerns\AcceptsAuctionTerms;
use Tests\TestCase;

final class AuctionReopenAndUpdateTest extends TestCase
{
    use AcceptsAuctionTerms;

    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate', ['--force' => true]);
        Storage::fake('spaces');
    }

    public function test_full_reject_reopen_update_resubmit_journey(): void
    {
        $version = $this->configurationVersion();
        $seller = $this->user();
        $admin = $this->user('admin');
        $auction = $this->auction(AuctionStatus::Draft, $version, $seller);

        app(SubmitAuctionForReviewAction::class)->execute($auction, $seller->id, $this->requiredTermsVersionId($auction));
        $this->assertSame(AuctionStatus::PendingReview, $auction->refresh()->status);

        app(ReviewAuctionAction::class)->reject($auction->refresh(), $admin->id, 'blurred images');
        $this->assertSame(AuctionStatus::Rejected, $auction->refresh()->status);

        Sanctum::actingAs($seller);

        $this->patchJson("/api/soom/auctions/{$auction->public_id}", ['title' => 'edited while rejected'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'auction_not_editable');

        $this->postJson("/api/soom/auctions/{$auction->public_id}/reopen")
            ->assertOk()
            ->assertJsonPath('data.status', 'draft');

        $this->assertSame(AuctionStatus::Draft, $auction->refresh()->status);

        $this->postJson("/api/soom/auctions/{$auction->public_id}", [
            '_method' => 'PATCH',
            'title' => 'clear photos and full description',
            'description' => 'A fully corrected description for the resubmitted auction.',
            'media' => [UploadedFile::fake()->image('fixed.jpg')],
        ])->assertOk();

        $auction->refresh();
        $this->assertSame('clear photos and full description', $auction->title);
        $this->assertSame(1, AuctionMedia::where('auction_id', $auction->id)->count());

        $this->postJson("/api/soom/auctions/{$auction->public_id}/submit-review", $this->termsBody($auction))
            ->assertOk()
            ->assertJsonPath('data.status', 'pending_review');

        $this->assertSame(AuctionStatus::PendingReview, $auction->refresh()->status);

        $history = AuctionStatusHistory::where('auction_id', $auction->id)
            ->orderBy('id')
            ->get();

        $this->assertSame(
            ['pending_review', 'rejected', 'draft', 'pending_review'],
            $history->pluck('to_status')->all()
        );

        $rejection = $history->firstWhere('to_status', 'rejected');
        $this->assertSame('blurred images', $rejection->reason);
        $this->assertSame('admin', $rejection->actor_type);
        $this->assertSame($admin->id, $rejection->changed_by);

        $reopen = $history->firstWhere('to_status', 'draft');
        $this->assertSame('user', $reopen->actor_type);
        $this->assertSame($seller->id, $reopen->changed_by);
        $this->assertSame(__('auction.audit.seller_reopened_rejected_auction'), $reopen->reason);
    }

    public function test_only_the_owner_can_reopen_a_rejected_auction(): void
    {
        $auction = $this->auction(AuctionStatus::Rejected, $this->configurationVersion());
        $intruder = $this->user();

        Sanctum::actingAs($intruder);
        $this->postJson("/api/soom/auctions/{$auction->public_id}/reopen")->assertForbidden();

        $admin = $this->user('admin');
        Sanctum::actingAs($admin);
        $this->postJson("/api/soom/auctions/{$auction->public_id}/reopen")->assertForbidden();

        $this->assertSame(AuctionStatus::Rejected, $auction->refresh()->status);
    }

    public function test_reopen_is_rejected_from_every_non_rejected_status(): void
    {
        $version = $this->configurationVersion();

        foreach ([
            AuctionStatus::Draft,
            AuctionStatus::PendingReview,
            AuctionStatus::AwaitingSellerDeposit,
            AuctionStatus::Scheduled,
            AuctionStatus::Live,
            AuctionStatus::Ended,
            AuctionStatus::Cancelled,
            AuctionStatus::Completed,
        ] as $status) {
            $seller = $this->user();
            $auction = $this->auction($status, $version, $seller);

            Sanctum::actingAs($seller);

            $this->postJson("/api/soom/auctions/{$auction->public_id}/reopen")
                ->assertStatus(422)
                ->assertJsonPath('code', 'auction_not_reopenable');

            $this->assertSame($status, $auction->refresh()->status);
        }
    }

    public function test_reopening_twice_returns_a_stable_domain_error_and_writes_one_transition(): void
    {
        $seller = $this->user();
        $auction = $this->auction(AuctionStatus::Rejected, $this->configurationVersion(), $seller);

        Sanctum::actingAs($seller);

        $this->postJson("/api/soom/auctions/{$auction->public_id}/reopen")->assertOk();
        $this->postJson("/api/soom/auctions/{$auction->public_id}/reopen")
            ->assertStatus(422)
            ->assertJsonPath('code', 'auction_not_reopenable');

        $this->assertSame(
            1,
            AuctionStatusHistory::where('auction_id', $auction->id)->where('to_status', 'draft')->count()
        );
    }

    public function test_reopen_preserves_media_activity_and_rejection_history(): void
    {
        $seller = $this->user();
        $auction = $this->auction(AuctionStatus::Rejected, $this->configurationVersion(), $seller);
        $media = AuctionMedia::create([
            'auction_id' => $auction->id,
            'disk' => 'spaces',
            'path' => 'auctions/keep.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1024,
            'sort_order' => 0,
            'is_primary' => true,
        ]);
        AuctionStatusHistory::create([
            'auction_id' => $auction->id,
            'from_status' => 'pending_review',
            'to_status' => 'rejected',
            'changed_by' => $this->user('admin')->id,
            'actor_type' => 'admin',
            'reason' => 'documented rejection reason',
        ]);

        app(ReopenRejectedAuctionAction::class)->execute($auction, $seller->id);

        $this->assertDatabaseHas('auction_media', ['id' => $media->id]);
        $this->assertDatabaseHas('auction_status_history', [
            'auction_id' => $auction->id,
            'to_status' => 'rejected',
            'reason' => 'documented rejection reason',
        ]);
        $this->assertSame($auction->configuration_version_id, $auction->refresh()->configuration_version_id);
    }

    public function test_reopen_does_not_resubmit_the_auction_for_review(): void
    {
        $seller = $this->user();
        $auction = $this->auction(AuctionStatus::Rejected, $this->configurationVersion(), $seller);

        app(ReopenRejectedAuctionAction::class)->execute($auction, $seller->id);

        $this->assertSame(AuctionStatus::Draft, $auction->refresh()->status);
        $this->assertSame(
            0,
            AuctionStatusHistory::where('auction_id', $auction->id)->where('to_status', 'pending_review')->count()
        );
    }

    public function test_owner_can_update_a_draft_auction(): void
    {
        $seller = $this->user();
        $auction = $this->auction(AuctionStatus::Draft, $this->configurationVersion(), $seller);

        Sanctum::actingAs($seller);

        $this->patchJson("/api/soom/auctions/{$auction->public_id}", [
            'title' => 'A corrected title',
            'description' => 'A corrected description that is long enough.',
            'starting_amount' => '25.000',
            'reserve_amount' => '40.000',
        ])->assertOk()->assertJsonPath('data.title', 'A corrected title');

        $auction->refresh();
        $this->assertSame('A corrected title', $auction->title);
        $this->assertSame(25_000, (int) $auction->starting_amount_minor);
        $this->assertSame(40_000, (int) $auction->reserve_amount_minor);
    }

    public function test_other_users_cannot_update_a_draft_auction(): void
    {
        $auction = $this->auction(AuctionStatus::Draft, $this->configurationVersion());

        Sanctum::actingAs($this->user());
        $this->patchJson("/api/soom/auctions/{$auction->public_id}", ['title' => 'hijacked'])->assertForbidden();

        $this->assertNotSame('hijacked', $auction->refresh()->title);
    }

    public function test_update_is_blocked_in_every_non_draft_status(): void
    {
        $version = $this->configurationVersion();

        foreach ([
            AuctionStatus::PendingReview,
            AuctionStatus::Rejected,
            AuctionStatus::AwaitingSellerDeposit,
            AuctionStatus::Scheduled,
            AuctionStatus::Live,
            AuctionStatus::Ended,
            AuctionStatus::Completed,
            AuctionStatus::Cancelled,
        ] as $status) {
            $seller = $this->user();
            $auction = $this->auction($status, $version, $seller);

            Sanctum::actingAs($seller);

            $this->patchJson("/api/soom/auctions/{$auction->public_id}", ['title' => 'nope'])
                ->assertStatus(422)
                ->assertJsonPath('code', 'auction_not_editable');

            $this->assertNotSame('nope', $auction->refresh()->title);
        }
    }

    public function test_protected_fields_are_never_writable_through_the_update_endpoint(): void
    {
        $seller = $this->user();
        $other = $this->user();
        $auction = $this->auction(AuctionStatus::Draft, $this->configurationVersion(), $seller);
        $originalConfiguration = $auction->configuration_version_id;

        Sanctum::actingAs($seller);

        $this->patchJson("/api/soom/auctions/{$auction->public_id}", [
            'title' => 'legit change',
            'seller_id' => $other->id,
            'status' => 'scheduled',
            'published_at' => now()->toIso8601String(),
            'started_at' => now()->toIso8601String(),
            'configuration_version_id' => 99_999,
            'minimum_bid_increment_minor' => 1,
            'seller_deposit_amount_minor' => 1,
            'platform_fee_basis_points' => 1,
            'winning_bid_id' => 1,
            'current_leading_bid_id' => 1,
        ])->assertOk();

        $auction->refresh();
        $this->assertSame($seller->id, $auction->seller_id);
        $this->assertSame(AuctionStatus::Draft, $auction->status);
        $this->assertNull($auction->published_at);
        $this->assertNull($auction->started_at);
        $this->assertSame($originalConfiguration, $auction->configuration_version_id);
        $this->assertSame(500, (int) $auction->minimum_bid_increment_minor);
        $this->assertNull($auction->winning_bid_id);
        $this->assertSame('legit change', $auction->title);
    }

    public function test_update_validates_amounts_and_schedule(): void
    {
        $seller = $this->user();
        $auction = $this->auction(AuctionStatus::Draft, $this->configurationVersion(), $seller);

        Sanctum::actingAs($seller);

        $this->patchJson("/api/soom/auctions/{$auction->public_id}", ['reserve_amount' => '1.000'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed');

        $this->patchJson("/api/soom/auctions/{$auction->public_id}", ['starting_amount' => '10.0000'])
            ->assertStatus(422);

        $this->patchJson("/api/soom/auctions/{$auction->public_id}", [
            'starts_at' => now()->addDays(5)->toIso8601String(),
            'ends_at' => now()->addDays(4)->toIso8601String(),
        ])->assertStatus(422);

        $this->patchJson("/api/soom/auctions/{$auction->public_id}", ['currency_code' => 'USD'])
            ->assertStatus(422);

        $this->patchJson("/api/soom/auctions/{$auction->public_id}", ['starts_at' => now()->subDay()->toIso8601String()])
            ->assertStatus(422);
    }

    public function test_currency_change_is_accepted_when_amounts_are_resupplied(): void
    {
        $seller = $this->user();
        $auction = $this->auction(AuctionStatus::Draft, $this->configurationVersion(), $seller);

        Sanctum::actingAs($seller);

        $this->patchJson("/api/soom/auctions/{$auction->public_id}", [
            'currency_code' => 'USD',
            'starting_amount' => '15.00',
        ])->assertOk();

        $auction->refresh();
        $this->assertSame('USD', $auction->currency_code);
        $this->assertSame(1_500, (int) $auction->starting_amount_minor);
    }

    public function test_media_replacement_is_atomic_and_keeps_old_files_until_commit(): void
    {
        $seller = $this->user();
        $auction = $this->auction(AuctionStatus::Draft, $this->configurationVersion(), $seller);

        Sanctum::actingAs($seller);

        $this->postJson("/api/soom/auctions/{$auction->public_id}", [
            '_method' => 'PATCH',
            'media' => [UploadedFile::fake()->image('one.jpg'), UploadedFile::fake()->image('two.jpg')],
        ])->assertOk();

        $original = AuctionMedia::where('auction_id', $auction->id)->orderBy('sort_order')->get();
        $this->assertCount(2, $original);
        foreach ($original as $item) {
            Storage::disk('spaces')->assertExists($item->path);
        }

        $existedAtCommit = null;
        $paths = $original->pluck('path')->all();
        \Illuminate\Support\Facades\Event::listen(
            \Illuminate\Database\Events\TransactionCommitted::class,
            static function () use (&$existedAtCommit, $paths): void {
                if ($existedAtCommit === null) {
                    $existedAtCommit = array_map(
                        static fn (string $path): bool => Storage::disk('spaces')->exists($path),
                        $paths
                    );
                }
            }
        );

        $this->postJson("/api/soom/auctions/{$auction->public_id}", [
            '_method' => 'PATCH',
            'media' => [UploadedFile::fake()->image('replacement.jpg')],
        ])->assertOk();

        $this->assertSame([true, true], $existedAtCommit);

        $replacement = AuctionMedia::where('auction_id', $auction->id)->get();
        $this->assertCount(1, $replacement);
        Storage::disk('spaces')->assertExists($replacement->first()->path);

        foreach ($original as $item) {
            Storage::disk('spaces')->assertMissing($item->path);
        }
    }

    public function test_failed_media_replacement_leaves_no_partial_state(): void
    {
        $seller = $this->user();
        $auction = $this->auction(AuctionStatus::Draft, $this->configurationVersion(), $seller);
        $existing = AuctionMedia::create([
            'auction_id' => $auction->id,
            'disk' => 'spaces',
            'path' => 'auctions/original.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 2048,
            'sort_order' => 0,
            'is_primary' => true,
        ]);
        Storage::disk('spaces')->put('auctions/original.jpg', 'original-bytes');

        $input = UpdateDraftAuctionInputDTO::fromValidated([
            'title' => 'attempted change',
            'media' => [
                UploadedFile::fake()->image('good.jpg'),
                $this->unreadableUpload(),
            ],
        ]);

        $failed = false;

        try {
            app(UpdateDraftAuctionAction::class)->execute($auction, $input, $seller->id);
        } catch (\Throwable) {
            $failed = true;
        }

        $this->assertTrue($failed);

        $auction->refresh();
        $this->assertSame('Reopen auction', $auction->title);
        $this->assertDatabaseHas('auction_media', ['id' => $existing->id, 'path' => 'auctions/original.jpg']);
        Storage::disk('spaces')->assertExists('auctions/original.jpg');
        $this->assertSame(1, AuctionMedia::where('auction_id', $auction->id)->count());
        $this->assertCount(1, Storage::disk('spaces')->allFiles());
        $this->assertSame(
            0,
            AuctionActivityLog::where('auction_id', $auction->id)->where('event_type', 'auction.updated')->count()
        );
    }

    public function test_update_records_changed_field_names_only_and_is_idempotent(): void
    {
        $seller = $this->user();
        $auction = $this->auction(AuctionStatus::Draft, $this->configurationVersion(), $seller);

        Sanctum::actingAs($seller);

        $this->patchJson("/api/soom/auctions/{$auction->public_id}", ['title' => 'a new title'])->assertOk();

        $log = AuctionActivityLog::where('auction_id', $auction->id)
            ->where('event_type', 'auction.updated')
            ->firstOrFail();

        $this->assertSame(['title'], $log->metadata['changed_fields']);
        $this->assertSame($seller->id, $log->user_id);
        $this->assertSame('user', $log->actor_type);
        $this->assertArrayNotHasKey('description', $log->metadata);

        $this->patchJson("/api/soom/auctions/{$auction->public_id}", ['title' => 'a new title'])->assertOk();

        $this->assertSame(
            1,
            AuctionActivityLog::where('auction_id', $auction->id)->where('event_type', 'auction.updated')->count()
        );
    }

    public function test_update_media_read_cost_does_not_grow_with_the_number_of_media_rows(): void
    {
        $small = $this->countMediaSelectsForReplacement(1);
        $large = $this->countMediaSelectsForReplacement(8);

        $this->assertSame($small, $large);
    }

    private function countMediaSelectsForReplacement(int $existingCount): int
    {
        $seller = $this->user();
        $auction = $this->auction(AuctionStatus::Draft, $this->configurationVersion(), $seller);

        for ($index = 0; $index < $existingCount; $index++) {
            AuctionMedia::create([
                'auction_id' => $auction->id,
                'disk' => 'spaces',
                'path' => "auctions/seed-{$auction->id}-{$index}.jpg",
                'mime_type' => 'image/jpeg',
                'size_bytes' => 512,
                'sort_order' => $index,
                'is_primary' => $index === 0,
            ]);
        }

        Sanctum::actingAs($seller);

        \Illuminate\Support\Facades\DB::enableQueryLog();

        $this->postJson("/api/soom/auctions/{$auction->public_id}", [
            '_method' => 'PATCH',
            'media' => [UploadedFile::fake()->image('replacement.jpg')],
        ])->assertOk();

        $selects = collect(\Illuminate\Support\Facades\DB::getQueryLog())
            ->filter(static fn (array $entry): bool => str_contains(strtolower((string) $entry['query']), 'auction_media'))
            ->count();

        \Illuminate\Support\Facades\DB::disableQueryLog();
        \Illuminate\Support\Facades\DB::flushQueryLog();

        return $selects;
    }

    private function unreadableUpload(): UploadedFile
    {
        $file = UploadedFile::fake()->image('broken.jpg');
        @unlink($file->getPathname());

        return $file;
    }

    private function configurationVersion(): AuctionConfigurationVersion
    {
        return AuctionConfigurationVersion::create([
            'version_number' => ((int) AuctionConfigurationVersion::max('version_number')) + 1,
            'is_active' => true,
            'published_at' => now()->subDay(),
            'configuration' => [
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
                'winner_default_deposit_policy' => [
                    'disposition' => 'full_forfeit',
                    'forfeit_amount_minor' => 0,
                ],
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
            ],
        ]);
    }

    private function auction(AuctionStatus $status, AuctionConfigurationVersion $version, ?User $seller = null): Auction
    {
        $terms = AuctionTermsVersion::create([
            'version_number' => ((int) AuctionTermsVersion::max('version_number')) + 1,
            'title' => 'Terms',
            'body' => 'Reopen terms body '.Str::ulid(),
            'is_active' => true,
            'published_at' => now()->subDay(),
        ]);

        return Auction::create([
            'seller_id' => ($seller ?? $this->user())->id,
            'category_id' => Category::create(['name' => 'reopen-cat-'.Str::ulid(), 'display_order' => 0])->id,
            'country_id' => Country::create(['name' => 'reopen-country-'.Str::ulid(), 'code' => strtoupper(substr((string) Str::ulid(), 0, 6))])->id,
            'terms_version_id' => $terms->id,
            'configuration_version_id' => $version->id,
            'currency_code' => 'JOD',
            'title' => 'Reopen auction',
            'description' => 'Reopen auction description.',
            'status' => $status,
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
            'original_ends_at' => now()->addDays(3),
            'ends_at' => now()->addDays(3),
        ]);
    }

    private function user(string $role = 'user'): User
    {
        $unique = strtolower((string) Str::ulid());

        return User::create([
            'name' => 'Reopen User',
            'email' => "reopen-{$unique}@example.test",
            'phone' => '+96279'.str_pad((string) (abs(crc32($unique)) % 10_000_000), 7, '0', STR_PAD_LEFT),
            'password' => Hash::make('password'),
            'role' => $role,
            'email_verified_at' => now(),
        ]);
    }
}
