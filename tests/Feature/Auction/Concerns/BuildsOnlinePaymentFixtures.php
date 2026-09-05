<?php

declare(strict_types=1);

namespace Tests\Feature\Auction\Concerns;

use App\Domain\Auction\Enums\AuctionDepositStatus;
use App\Domain\Auction\Enums\AuctionParticipantStatus;
use App\Domain\Auction\Enums\AuctionStatus;
use App\Domain\Auction\Enums\PaymentChannel;
use App\Domain\Auction\Enums\PaymentRail;
use App\Domain\Auction\Enums\SettlementStatus;
use App\Models\Auction\Auction;
use App\Models\Auction\AuctionBid;
use App\Models\Auction\AuctionDeposit;
use App\Models\Auction\AuctionParticipant;
use App\Models\Auction\AuctionSettlement;
use App\Models\Auction\AuctionTermsAcceptance;
use App\Models\Auction\AuctionTermsVersion;
use App\Models\Auction\PaymentMethod;
use App\Models\Category;
use App\Models\Country;
use App\Models\User;
use App\Services\Auction\Payments\Providers\FakeBillPaymentProvider;
use App\Services\Auction\Payments\Providers\FakePaymentProvider;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

trait BuildsOnlinePaymentFixtures
{
    protected function enableFakeProvider(): FakePaymentProvider
    {
        config([
            'auction.payments.allow_fake_provider' => true,
            'auction.payments.disabled_providers' => [],
            'services.fake.webhook_secret' => 'test-webhook-secret',
        ]);

        $provider = app(FakePaymentProvider::class);
        $provider->reset();

        return $provider;
    }

    protected function onlinePaymentMethod(array $overrides = []): PaymentMethod
    {
        return PaymentMethod::create(array_replace([
            'name' => 'Fake card gateway',
            'code' => 'fake-card-'.Str::ulid(),
            'channel' => PaymentChannel::Online,
            'rail' => PaymentRail::Card,
            'provider_code' => FakePaymentProvider::CODE,
            'is_sandbox' => true,
            'requires_manual_review' => false,
            'is_active' => true,
            'display_order' => 1,
        ], $overrides));
    }

    protected function enableFakeBillProvider(): FakeBillPaymentProvider
    {
        config([
            'auction.payments.allow_fake_provider' => true,
            'auction.payments.disabled_providers' => [],
            'services.fake_bill.webhook_secret' => 'test-bill-secret',
        ]);

        return app(FakeBillPaymentProvider::class);
    }

    protected function billPaymentMethod(array $overrides = []): PaymentMethod
    {
        return PaymentMethod::create(array_replace([
            'name' => 'Fake bill rail',
            'code' => 'fake-bill-'.Str::ulid(),
            'channel' => PaymentChannel::Online,
            'rail' => PaymentRail::Bill,
            'provider_code' => FakeBillPaymentProvider::CODE,
            'is_sandbox' => true,
            'requires_manual_review' => false,
            'is_active' => true,
            'display_order' => 2,
        ], $overrides));
    }

    protected function registerBidder(Auction $auction, User $bidder): AuctionParticipant
    {
        $participant = AuctionParticipant::create([
            'auction_id' => $auction->id,
            'user_id' => $bidder->id,
            'status' => AuctionParticipantStatus::Registered,
            'registered_at' => Carbon::now()->subDay(),
        ]);

        AuctionTermsAcceptance::create([
            'auction_id' => $auction->id,
            'participant_id' => $participant->id,
            'user_id' => $bidder->id,
            'terms_version_id' => $auction->terms_version_id,
            'accepted_at' => Carbon::now()->subDay(),
        ]);

        return $participant;
    }

    protected function manualPaymentMethod(): PaymentMethod
    {
        return PaymentMethod::create([
            'name' => 'Manual bank transfer',
            'code' => 'manual-'.Str::ulid(),
            'channel' => PaymentChannel::Manual,
            'rail' => PaymentRail::Transfer,
            'instructions' => 'Upload receipt.',
            'requires_manual_review' => true,
            'is_active' => true,
        ]);
    }

    protected function paymentUser(string $role = 'user'): User
    {
        $unique = strtolower((string) Str::ulid());
        $phoneSuffix = str_pad((string) (abs(crc32($unique)) % 10_000_000), 7, '0', STR_PAD_LEFT);

        return User::create([
            'name' => 'Online payment '.$unique,
            'email' => "online-payment-{$unique}@example.test",
            'phone' => '+96277'.$phoneSuffix,
            'password' => Hash::make('password'),
            'role' => $role,
            'email_verified_at' => Carbon::now(),
        ]);
    }

    protected function paymentAuction(AuctionStatus $status, array $overrides = [], array $configurationOverrides = []): array
    {
        $seller = $this->paymentUser();
        $terms = AuctionTermsVersion::create([
            'version_number' => ((int) AuctionTermsVersion::max('version_number')) + 1,
            'title' => 'Terms',
            'body' => 'Auction terms.',
            'is_active' => true,
            'published_at' => Carbon::now()->subDay(),
        ]);
        $category = Category::create(['name' => 'cat-'.Str::ulid(), 'display_order' => 0]);
        $country = Country::create(['name' => 'country-'.Str::ulid(), 'code' => 'JO'.substr((string) Str::ulid(), 0, 4)]);
        $configuration = $this->auctionConfigurationVersion($configurationOverrides);

        $auction = Auction::create(array_replace([
            'seller_id' => $seller->id,
            'category_id' => $category->id,
            'country_id' => $country->id,
            'terms_version_id' => $terms->id,
            'configuration_version_id' => $configuration->id,
            'currency_code' => 'JOD',
            'title' => 'Online payment auction',
            'description' => 'Online payment auction.',
            'status' => $status,
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
            'original_ends_at' => Carbon::now()->addDay(),
            'ends_at' => Carbon::now()->addDay(),
        ], $overrides));

        $this->snapshotApprovedAuction($auction, $seller->id);

        return [$auction, $seller];
    }

    protected function registeredBidder(Auction $auction): array
    {
        $bidder = $this->paymentUser();

        $participant = AuctionParticipant::create([
            'auction_id' => $auction->id,
            'user_id' => $bidder->id,
            'status' => AuctionParticipantStatus::Registered,
            'registered_at' => Carbon::now()->subDay(),
        ]);

        AuctionTermsAcceptance::create([
            'auction_id' => $auction->id,
            'participant_id' => $participant->id,
            'user_id' => $bidder->id,
            'terms_version_id' => $auction->terms_version_id,
            'accepted_at' => Carbon::now()->subDay(),
        ]);

        return [$bidder, $participant];
    }

    protected function winnerSettlement(Auction $auction): array
    {
        $winner = $this->paymentUser();

        $participant = AuctionParticipant::create([
            'auction_id' => $auction->id,
            'user_id' => $winner->id,
            'status' => AuctionParticipantStatus::Qualified,
            'registered_at' => Carbon::now()->subDays(2),
            'qualified_at' => Carbon::now()->subDay(),
        ]);

        AuctionDeposit::create([
            'auction_id' => $auction->id,
            'participant_id' => $participant->id,
            'user_id' => $winner->id,
            'type' => 'bidder',
            'status' => AuctionDepositStatus::Held,
            'required_amount_minor' => 1_000,
            'held_amount_minor' => 1_000,
            'currency_code' => 'JOD',
            'held_at' => Carbon::now()->subDay(),
        ]);

        $bid = AuctionBid::create([
            'auction_id' => $auction->id,
            'participant_id' => $participant->id,
            'bidder_id' => $winner->id,
            'amount_minor' => 100_000,
            'currency_code' => 'JOD',
            'sequence_number' => 1,
            'idempotency_key' => 'bid-'.Str::ulid(),
            'server_received_at' => Carbon::now(),
            'accepted_at' => Carbon::now(),
        ]);

        $auction->forceFill(['winning_bid_id' => $bid->id])->save();

        $settlement = AuctionSettlement::create([
            'auction_id' => $auction->id,
            'winning_bid_id' => $bid->id,
            'winner_id' => $winner->id,
            'sequence_number' => 1,
            'is_current' => true,
            'current_marker' => 1,
            'status' => SettlementStatus::PaymentPending,
            'winning_amount_minor' => 100_000,
            'deposit_applied_minor' => 0,
            'platform_fee_minor' => 2_500,
            'seller_net_amount_minor' => 97_500,
            'amount_due_minor' => 100_000,
            'amount_paid_minor' => 0,
            'remaining_amount_minor' => 100_000,
            'currency_code' => 'JOD',
            'payment_due_at' => Carbon::now()->addDay(),
        ]);

        return [$winner, $settlement];
    }
}
