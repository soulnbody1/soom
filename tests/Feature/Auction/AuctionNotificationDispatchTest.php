<?php

declare(strict_types=1);

namespace Tests\Feature\Auction;

use App\Domain\Auction\Enums\AuctionDepositStatus;
use App\Domain\Auction\Enums\AuctionParticipantStatus;
use App\Domain\Auction\Enums\AuctionStatus;
use App\Domain\Auction\Enums\OutboxStatus;
use App\Domain\Auction\Enums\PaymentPurpose;
use App\Domain\Auction\Enums\PaymentSubmissionStatus;
use App\Domain\Auction\Enums\PaymentTransactionStatus;
use App\Events\Auction\AuctionPublicAnnouncementEvent;
use App\Events\Auction\AuctionRealtimeEvent;
use App\Jobs\Auction\DispatchAuctionOutboxJob;
use App\Models\Auction\Auction;
use App\Models\Auction\AuctionConfigurationVersion;
use App\Models\Auction\AuctionDeposit;
use App\Models\Auction\AuctionParticipant;
use App\Models\Auction\AuctionTermsAcceptance;
use App\Models\Auction\AuctionTermsVersion;
use App\Models\Auction\OutboxMessage;
use App\Models\Auction\PaymentMethod;
use App\Models\Auction\PaymentSubmission;
use App\Models\Auction\PaymentTransaction;
use App\Models\Category;
use App\Models\Country;
use App\Models\User;
use App\Repositories\Auction\AuctionConfigurationSnapshotRepository;
use App\Services\Auction\Actions\DispatchOutboxMessagesAction;
use App\Services\Auction\Actions\FinalizeAuctionAction;
use App\Services\Auction\Actions\PlaceBidAction;
use App\Services\Auction\Actions\ReviewAuctionAction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AuctionNotificationDispatchTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate', ['--force' => true]);
    }

    public function test_outbid_notifies_previous_leader_only_and_realtime_stays_anonymous(): void
    {
        Event::fake([AuctionRealtimeEvent::class, AuctionPublicAnnouncementEvent::class]);

        [$auction, $seller] = $this->auction(AuctionStatus::Live, Carbon::now()->addHour());
        [$bidderA, $participantA] = $this->qualifiedParticipant($auction, 10_000);
        [$bidderB, $participantB] = $this->qualifiedParticipant($auction, 10_000);
        $this->acceptTerms($auction, $participantA, $bidderA);
        $this->acceptTerms($auction, $participantB, $bidderB);

        $placeBid = app(PlaceBidAction::class);
        $placeBid->execute($auction, $bidderA->id, '10.000', 'JOD', 'bid-a-'.Str::ulid());
        $placeBid->execute($auction->refresh(), $bidderB->id, '10.500', 'JOD', 'bid-b-'.Str::ulid());

        $job = app(DispatchAuctionOutboxJob::class);
        $job->handle(app(DispatchOutboxMessagesAction::class));
        $job->handle(app(DispatchOutboxMessagesAction::class));

        $outbid = $bidderA->notifications()->get();
        $this->assertCount(1, $outbid);
        $this->assertSame('auction.bid_accepted', $outbid[0]->data['event_type']);
        $this->assertSame('تمت المزايدة عليك', $outbid[0]->data['title']);
        $this->assertStringContainsString('10.500', $outbid[0]->data['message']);

        $this->assertSame(0, $bidderB->notifications()->count());
        $this->assertSame(0, $seller->notifications()->count());

        Event::assertDispatchedTimes(AuctionRealtimeEvent::class, 2);
        Event::assertDispatched(AuctionRealtimeEvent::class, function (AuctionRealtimeEvent $event) use ($auction): bool {
            $payload = $event->broadcastWith();

            return $event->broadcastOn()->name === "auction.{$auction->public_id}"
                && $event->broadcastAs() === 'auction.bid_accepted'
                && $payload['bidder'] === ['anonymous' => true]
                && ! array_key_exists('bidder_id', $payload)
                && ! array_key_exists('name', $payload)
                && ! array_key_exists('receipt_path', $payload)
                && $payload['auction_id'] === $auction->public_id;
        });
    }

    public function test_raising_own_leading_bid_sends_no_outbid_notification(): void
    {
        Event::fake([AuctionRealtimeEvent::class]);

        [$auction] = $this->auction(AuctionStatus::Live, Carbon::now()->addHour());
        [$bidder, $participant] = $this->qualifiedParticipant($auction, 10_000);
        $this->acceptTerms($auction, $participant, $bidder);

        $placeBid = app(PlaceBidAction::class);
        $placeBid->execute($auction, $bidder->id, '10.000', 'JOD', 'own-1-'.Str::ulid());
        $placeBid->execute($auction->refresh(), $bidder->id, '10.500', 'JOD', 'own-2-'.Str::ulid());

        app(DispatchAuctionOutboxJob::class)->handle(app(DispatchOutboxMessagesAction::class));

        $this->assertSame(0, $bidder->notifications()->count());
        Event::assertDispatchedTimes(AuctionRealtimeEvent::class, 2);
    }

    public function test_rejected_review_notifies_seller_with_reason_in_arabic(): void
    {
        [$auction, $seller] = $this->auction(AuctionStatus::PendingReview, Carbon::now()->addDay());
        $admin = $this->user('admin');

        app(ReviewAuctionAction::class)->reject($auction, $admin->id, 'الصور غير واضحة');

        app(DispatchAuctionOutboxJob::class)->handle(app(DispatchOutboxMessagesAction::class));

        $notifications = $seller->notifications()->get();
        $this->assertCount(1, $notifications);
        $this->assertSame('auction.status_changed', $notifications[0]->data['event_type']);
        $this->assertSame('لم تتم الموافقة على المزاد', $notifications[0]->data['title']);
        $this->assertStringContainsString('الصور غير واضحة', $notifications[0]->data['message']);
    }

    public function test_bidder_deposit_approval_notifies_qualified_bidder(): void
    {
        [$auction] = $this->auction(AuctionStatus::Live, Carbon::now()->addHour());
        $bidder = $this->user();

        OutboxMessage::create([
            'event_id' => (string) Str::ulid(),
            'topic' => 'auction.events',
            'event_type' => 'auction.payment_approved',
            'aggregate_type' => Auction::class,
            'aggregate_id' => $auction->id,
            'payload' => ['user_id' => $bidder->id, 'purpose' => 'bidder_deposit'],
            'status' => OutboxStatus::Pending,
            'available_at' => Carbon::now()->subMinute(),
        ]);

        app(DispatchAuctionOutboxJob::class)->handle(app(DispatchOutboxMessagesAction::class));

        $notifications = $bidder->notifications()->get();
        $this->assertCount(1, $notifications);
        $this->assertSame('تمت الموافقة على عربونك', $notifications[0]->data['title']);
        $this->assertStringContainsString('مؤهلًا', $notifications[0]->data['message']);
    }

    public function test_finalization_notifies_winner_seller_and_losing_bidders(): void
    {
        Event::fake([AuctionRealtimeEvent::class]);

        [$auction, $seller] = $this->auction(AuctionStatus::Live, Carbon::now()->subMinute());
        [$winner, $winnerParticipant] = $this->qualifiedParticipant($auction, 10_000);
        [$loser, $loserParticipant] = $this->qualifiedParticipant($auction, 10_000);
        $this->acceptTerms($auction, $winnerParticipant, $winner);
        $this->acceptTerms($auction, $loserParticipant, $loser);
        $this->bid($auction, $loserParticipant, $loser, 90_000, 1);
        $this->bid($auction, $winnerParticipant, $winner, 100_000, 2);
        $auction->forceFill(['current_leading_bid_id' => $auction->bids()->orderByDesc('amount_minor')->first()->id])->save();

        app(FinalizeAuctionAction::class)->execute($auction->refresh());

        app(DispatchAuctionOutboxJob::class)->handle(app(DispatchOutboxMessagesAction::class));

        $winnerNotifications = $winner->notifications()->get();
        $this->assertCount(1, $winnerNotifications);
        $this->assertSame('auction.finalized', $winnerNotifications[0]->data['event_type']);
        $this->assertSame('مبروك، فزت بالمزاد', $winnerNotifications[0]->data['title']);
        $this->assertStringContainsString('100.000', $winnerNotifications[0]->data['message']);

        $sellerFinalized = $seller->notifications()->get()
            ->filter(fn ($notification) => $notification->data['event_type'] === 'auction.finalized');
        $this->assertCount(1, $sellerFinalized);
        $this->assertSame('تم اختيار الفائز بمزادك', $sellerFinalized->first()->data['title']);

        $loserNotifications = $loser->notifications()->get();
        $this->assertCount(1, $loserNotifications);
        $this->assertSame('auction.bidder_lost', $loserNotifications[0]->data['event_type']);
        $this->assertSame('لم تفز بالمزاد', $loserNotifications[0]->data['title']);
        $this->assertStringNotContainsString((string) $winner->name, (string) $loserNotifications[0]->data['message']);
    }

    public function test_scheduled_auction_broadcasts_public_announcement_without_private_data(): void
    {
        Event::fake([AuctionPublicAnnouncementEvent::class]);

        [$auction, $seller] = $this->auction(AuctionStatus::PendingReview, Carbon::now()->addDay(), sellerDepositMinor: 0, reserveMinor: 500_000);
        $admin = $this->user('admin');

        app(ReviewAuctionAction::class)->approve($auction, $admin->id, 'ok');

        app(DispatchAuctionOutboxJob::class)->handle(app(DispatchOutboxMessagesAction::class));

        $this->assertSame(1, $seller->notifications()->count());
        $this->assertSame('تم نشر مزادك', $seller->notifications()->first()->data['title']);

        Event::assertDispatched(AuctionPublicAnnouncementEvent::class, function (AuctionPublicAnnouncementEvent $event) use ($auction): bool {
            $payload = $event->broadcastWith();

            return $event->broadcastOn()->name === 'public.auctions'
                && $payload['type'] === 'published'
                && $payload['auction_id'] === $auction->public_id
                && ! array_key_exists('reserve_amount', $payload)
                && ! array_key_exists('reserve_amount_minor', $payload)
                && ! array_key_exists('seller_id', $payload)
                && ! str_contains(json_encode($payload, JSON_UNESCAPED_UNICODE), '500.000');
        });
    }

    public function test_unknown_outbox_event_is_failed_with_diagnostics_not_silently_converted(): void
    {
        [$auction] = $this->auction(AuctionStatus::Live, Carbon::now()->addHour());

        $message = OutboxMessage::create([
            'event_id' => (string) Str::ulid(),
            'topic' => 'auction.events',
            'event_type' => 'auction.totally_unknown',
            'aggregate_type' => Auction::class,
            'aggregate_id' => $auction->id,
            'payload' => [],
            'status' => OutboxStatus::Pending,
            'available_at' => Carbon::now()->subMinute(),
        ]);

        app(DispatchAuctionOutboxJob::class)->handle(app(DispatchOutboxMessagesAction::class));

        $message->refresh();
        $this->assertSame(OutboxStatus::Pending, $message->status);
        $this->assertSame(1, $message->attempts);
        $this->assertStringContainsString('Unsupported auction outbox event', (string) $message->last_error);
        $this->assertSame(0, \Illuminate\Notifications\DatabaseNotification::count());
    }

    private function auction(AuctionStatus $status, Carbon $endsAt, int $sellerDepositMinor = 2_000, ?int $reserveMinor = null): array
    {
        $seller = $this->user();
        $terms = AuctionTermsVersion::create([
            'version_number' => ((int) AuctionTermsVersion::max('version_number')) + 1,
            'title' => 'Terms',
            'body' => 'Auction terms.',
            'is_active' => true,
            'published_at' => Carbon::now()->subDay(),
        ]);
        $configuration = AuctionConfigurationVersion::create([
            'version_number' => ((int) AuctionConfigurationVersion::max('version_number')) + 1,
            'configuration' => [
                'seller_deposit_policy' => config('auction.seller_deposit_policy'),
                'winner_default_deposit_policy' => config('auction.winner_default_deposit_policy'),
                'non_winner_deposit_policy' => config('auction.non_winner_deposit_policy'),
                'non_winner_deposit_hold_count' => (int) config('auction.non_winner_deposit_hold_count', 1),
            ],
            'is_active' => true,
            'published_at' => Carbon::now()->subDay(),
        ]);
        $category = Category::create(['name' => 'notify-cat-'.Str::ulid(), 'display_order' => 0]);
        $country = Country::create(['name' => 'notify-country-'.Str::ulid(), 'code' => strtoupper(substr((string) Str::ulid(), 0, 6))]);
        $auction = Auction::create([
            'seller_id' => $seller->id,
            'category_id' => $category->id,
            'country_id' => $country->id,
            'terms_version_id' => $terms->id,
            'configuration_version_id' => $configuration->id,
            'currency_code' => 'JOD',
            'title' => 'Notification auction',
            'description' => 'Notification auction.',
            'status' => $status,
            'starting_amount_minor' => 10_000,
            'reserve_amount_minor' => $reserveMinor,
            'minimum_bid_increment_minor' => 500,
            'seller_deposit_amount_minor' => $sellerDepositMinor,
            'bidder_deposit_amount_minor' => 10_000,
            'platform_fee_type' => 'percentage',
            'platform_fee_basis_points' => 250,
            'platform_fee_fixed_minor' => 0,
            'winner_payment_deadline_hours' => 48,
            'handover_deadline_hours' => 72,
            'starts_at' => Carbon::now()->subDays(2),
            'original_ends_at' => $endsAt,
            'ends_at' => $endsAt,
        ]);
        app(AuctionConfigurationSnapshotRepository::class)->createForApprovedAuction($auction, $seller->id);

        return [$auction->refresh(), $seller];
    }

    private function qualifiedParticipant(Auction $auction, int $heldDeposit): array
    {
        $user = $this->user();
        $participant = AuctionParticipant::create([
            'auction_id' => $auction->id,
            'user_id' => $user->id,
            'status' => AuctionParticipantStatus::Qualified,
            'registered_at' => Carbon::now()->subDays(2),
            'qualified_at' => Carbon::now()->subDay(),
        ]);
        $deposit = AuctionDeposit::create([
            'auction_id' => $auction->id,
            'participant_id' => $participant->id,
            'user_id' => $user->id,
            'type' => 'bidder',
            'status' => AuctionDepositStatus::Held,
            'required_amount_minor' => $heldDeposit,
            'held_amount_minor' => $heldDeposit,
            'currency_code' => $auction->currency_code,
            'held_at' => Carbon::now()->subDay(),
        ]);
        $this->successfulDepositPayment($auction, $deposit, $user->id, $heldDeposit);

        return [$user, $participant];
    }

    private function bid(Auction $auction, AuctionParticipant $participant, User $user, int $amount, int $sequence): \App\Models\Auction\AuctionBid
    {
        return \App\Models\Auction\AuctionBid::create([
            'auction_id' => $auction->id,
            'participant_id' => $participant->id,
            'bidder_id' => $user->id,
            'amount_minor' => $amount,
            'currency_code' => $auction->currency_code,
            'sequence_number' => $sequence,
            'idempotency_key' => 'notify-bid-'.$sequence.'-'.Str::ulid(),
            'server_received_at' => Carbon::now(),
            'accepted_at' => Carbon::now(),
        ]);
    }

    private function acceptTerms(Auction $auction, AuctionParticipant $participant, User $user): void
    {
        AuctionTermsAcceptance::create([
            'auction_id' => $auction->id,
            'participant_id' => $participant->id,
            'user_id' => $user->id,
            'terms_version_id' => $auction->terms_version_id,
            'accepted_at' => Carbon::now()->subDay(),
        ]);
    }

    private function successfulDepositPayment(Auction $auction, AuctionDeposit $deposit, int $userId, int $amount): void
    {
        $method = PaymentMethod::create([
            'name' => 'Notify payment method',
            'code' => 'notify-'.Str::ulid(),
            'instructions' => 'Test method.',
            'requires_manual_review' => true,
            'is_active' => true,
        ]);
        $submission = PaymentSubmission::create([
            'auction_id' => $auction->id,
            'deposit_id' => $deposit->id,
            'user_id' => $userId,
            'payment_method_id' => $method->id,
            'purpose' => PaymentPurpose::BidderDeposit,
            'status' => PaymentSubmissionStatus::Approved,
            'amount_minor' => $amount,
            'currency_code' => $auction->currency_code,
            'receipt_disk' => 'spaces_private',
            'receipt_path' => 'notify-deposit.pdf',
            'receipt_mime_type' => 'application/pdf',
            'receipt_size_bytes' => 100,
            'idempotency_key' => 'notify-deposit-'.Str::ulid(),
            'submitted_at' => Carbon::now(),
            'reviewed_at' => Carbon::now(),
        ]);
        PaymentTransaction::create([
            'payment_submission_id' => $submission->id,
            'auction_id' => $auction->id,
            'user_id' => $userId,
            'purpose' => PaymentPurpose::BidderDeposit,
            'status' => PaymentTransactionStatus::Succeeded,
            'amount_minor' => $amount,
            'currency_code' => $auction->currency_code,
            'provider' => 'manual',
            'provider_transaction_id' => 'notify-deposit-'.Str::ulid(),
            'idempotency_key' => 'notify-deposit-'.Str::ulid(),
            'successful_obligation_key' => "deposit:{$deposit->id}",
            'processed_at' => Carbon::now(),
        ]);
    }

    private function user(string $role = 'user'): User
    {
        $unique = strtolower((string) Str::ulid());
        $phoneSuffix = str_pad((string) (abs(crc32($unique)) % 10_000_000), 7, '0', STR_PAD_LEFT);

        return User::create([
            'name' => fake()->name(),
            'email' => "notify-{$unique}@example.test",
            'phone' => '+96276'.$phoneSuffix,
            'password' => Hash::make('password'),
            'role' => $role,
            'email_verified_at' => Carbon::now(),
        ]);
    }
}
