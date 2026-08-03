<?php

declare(strict_types=1);

namespace Tests\Feature\Auction\Concerns;

use App\Domain\Auction\Enums\AuctionDepositStatus;
use App\Domain\Auction\Enums\AuctionParticipantStatus;
use App\Domain\Auction\Enums\AuctionStatus;
use App\Domain\Auction\Enums\PaymentPurpose;
use App\Domain\Auction\Enums\PaymentSubmissionStatus;
use App\Domain\Auction\Enums\PaymentTransactionStatus;
use App\Models\Auction\Auction;
use App\Models\Auction\AuctionBid;
use App\Models\Auction\AuctionConfigurationVersion;
use App\Models\Auction\AuctionDeposit;
use App\Models\Auction\AuctionParticipant;
use App\Models\Auction\AuctionTermsAcceptance;
use App\Models\Auction\AuctionTermsVersion;
use App\Models\Auction\PaymentMethod;
use App\Models\Auction\PaymentSubmission;
use App\Models\Auction\PaymentTransaction;
use App\Models\Category;
use App\Models\Country;
use App\Models\User;
use App\Repositories\Auction\AuctionConfigurationSnapshotRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

trait BuildsAuctionDeadlineFixtures
{
    private function auction(AuctionStatus $status, ?Carbon $endsAt = null, int $sellerDeposit = 2_000): array
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
        $category = Category::create(['name' => 'deadline-cat-'.Str::ulid(), 'display_order' => 0]);
        $country = Country::create(['name' => 'deadline-country-'.Str::ulid(), 'code' => strtoupper(substr((string) Str::ulid(), 0, 6))]);
        $endsAt ??= Carbon::now()->subMinute();

        $auction = Auction::create([
            'seller_id' => $seller->id,
            'category_id' => $category->id,
            'country_id' => $country->id,
            'terms_version_id' => $terms->id,
            'configuration_version_id' => $configuration->id,
            'currency_code' => 'JOD',
            'title' => 'Deadline auction',
            'description' => 'Deadline auction.',
            'status' => $status,
            'starting_amount_minor' => 10_000,
            'reserve_amount_minor' => null,
            'minimum_bid_increment_minor' => 500,
            'seller_deposit_amount_minor' => $sellerDeposit,
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
        $this->acceptTerms($auction, $participant, $user);

        return [$user, $participant];
    }

    private function bid(Auction $auction, AuctionParticipant $participant, User $user, int $amount, int $sequence): AuctionBid
    {
        return AuctionBid::create([
            'auction_id' => $auction->id,
            'participant_id' => $participant->id,
            'bidder_id' => $user->id,
            'amount_minor' => $amount,
            'currency_code' => $auction->currency_code,
            'sequence_number' => $sequence,
            'idempotency_key' => 'deadline-bid-'.$sequence.'-'.Str::ulid(),
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

    private function paymentMethod(): PaymentMethod
    {
        return PaymentMethod::create([
            'name' => 'Deadline payment method',
            'code' => 'deadline-'.Str::ulid(),
            'instructions' => 'Test method.',
            'requires_manual_review' => true,
            'is_active' => true,
        ]);
    }

    private function successfulDepositPayment(Auction $auction, AuctionDeposit $deposit, int $userId, int $amount): void
    {
        $submission = PaymentSubmission::create([
            'auction_id' => $auction->id,
            'deposit_id' => $deposit->id,
            'user_id' => $userId,
            'payment_method_id' => $this->paymentMethod()->id,
            'purpose' => PaymentPurpose::BidderDeposit,
            'status' => PaymentSubmissionStatus::Approved,
            'amount_minor' => $amount,
            'currency_code' => $auction->currency_code,
            'receipt_disk' => 'spaces_private',
            'receipt_path' => 'deadline-deposit.pdf',
            'receipt_mime_type' => 'application/pdf',
            'receipt_size_bytes' => 100,
            'idempotency_key' => 'deadline-deposit-'.Str::ulid(),
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
            'provider_transaction_id' => 'deadline-deposit-'.Str::ulid(),
            'idempotency_key' => 'deadline-deposit-'.Str::ulid(),
            'successful_obligation_key' => "deposit:{$deposit->id}",
            'processed_at' => Carbon::now(),
        ]);
    }

    private function pendingSettlementSubmission(Auction $auction, int $settlementId, int $userId, int $amount): PaymentSubmission
    {
        return PaymentSubmission::create([
            'auction_id' => $auction->id,
            'settlement_id' => $settlementId,
            'user_id' => $userId,
            'payment_method_id' => $this->paymentMethod()->id,
            'purpose' => PaymentPurpose::WinnerSettlement,
            'status' => PaymentSubmissionStatus::PendingReview,
            'amount_minor' => $amount,
            'currency_code' => $auction->currency_code,
            'receipt_disk' => 'spaces_private',
            'receipt_path' => 'deadline-settlement.pdf',
            'receipt_mime_type' => 'application/pdf',
            'receipt_size_bytes' => 100,
            'idempotency_key' => 'deadline-settlement-'.Str::ulid(),
            'submitted_at' => Carbon::now(),
        ]);
    }

    private function user(string $role = 'user'): User
    {
        $unique = strtolower((string) Str::ulid());
        $phoneSuffix = str_pad((string) (abs(crc32($unique)) % 10_000_000), 7, '0', STR_PAD_LEFT);

        return User::create([
            'name' => fake()->name(),
            'email' => "deadline-{$unique}@example.test",
            'phone' => '+96277'.$phoneSuffix,
            'password' => Hash::make('password'),
            'role' => $role,
            'email_verified_at' => Carbon::now(),
        ]);
    }
}
