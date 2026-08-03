<?php

declare(strict_types=1);

use App\Domain\Auction\Enums\AuctionDepositStatus;
use App\Domain\Auction\Enums\AuctionParticipantStatus;
use App\Domain\Auction\Enums\AuctionStatus;
use App\Domain\Auction\Enums\BidBlockingReason;
use App\DTO\Auction\BidEligibilityContextDTO;
use App\Services\Auction\Support\BidEligibilityLadder;
use Illuminate\Support\Carbon;

function ladderContext(array $overrides = []): BidEligibilityContextDTO
{
    $now = Carbon::parse('2026-08-03T12:00:00+03:00');

    $defaults = [
        'viewerId' => 10,
        'sellerId' => 99,
        'status' => AuctionStatus::Live,
        'startsAt' => $now->copy()->subHour(),
        'endsAt' => $now->copy()->addHour(),
        'now' => $now,
        'configurationAvailable' => true,
        'participantStatus' => AuctionParticipantStatus::Qualified,
        'hasAcceptedTerms' => true,
        'requiredTermsVersionId' => 1,
        'depositStatus' => AuctionDepositStatus::Held,
        'depositHeldMinor' => 1_000,
        'depositRequiredMinor' => 1_000,
    ];

    return new BidEligibilityContextDTO(...array_merge($defaults, $overrides));
}

test('fully eligible bidder is not blocked', function () {
    expect((new BidEligibilityLadder)->evaluate(ladderContext()))->toBe(BidBlockingReason::None);
});

test('ladder reports the first blocking reason in product order', function (array $overrides, BidBlockingReason $expected) {
    expect((new BidEligibilityLadder)->evaluate(ladderContext($overrides)))->toBe($expected);
})->with([
    'guest' => [['viewerId' => null], BidBlockingReason::AuthenticationRequired],
    'seller' => [['viewerId' => 99], BidBlockingReason::IsSeller],
    'snapshot unavailable' => [['configurationAvailable' => false], BidBlockingReason::ConfigurationUnavailable],
    'auction ended' => [['status' => AuctionStatus::Ended], BidBlockingReason::AuctionEnded],
    'auction scheduled' => [['status' => AuctionStatus::Scheduled], BidBlockingReason::AuctionNotLive],
    'window not open' => [['startsAt' => Carbon::parse('2026-08-03T13:00:00+03:00')], BidBlockingReason::BiddingWindowClosed],
    'window closed' => [['endsAt' => Carbon::parse('2026-08-03T11:00:00+03:00')], BidBlockingReason::BiddingWindowClosed],
    'not registered' => [['participantStatus' => null], BidBlockingReason::NotRegistered],
    'blocked' => [['participantStatus' => AuctionParticipantStatus::Blocked], BidBlockingReason::ParticipantBlocked],
    'terms not accepted' => [['hasAcceptedTerms' => false], BidBlockingReason::TermsRequired],
    'deposit not submitted' => [
        ['participantStatus' => AuctionParticipantStatus::Registered, 'depositStatus' => null, 'depositHeldMinor' => 0],
        BidBlockingReason::DepositRequired,
    ],
    'deposit under review' => [
        ['participantStatus' => AuctionParticipantStatus::Registered, 'depositStatus' => AuctionDepositStatus::PendingReview, 'depositHeldMinor' => 0],
        BidBlockingReason::DepositUnderReview,
    ],
    'deposit rejected' => [
        ['participantStatus' => AuctionParticipantStatus::Registered, 'depositStatus' => AuctionDepositStatus::Rejected, 'depositHeldMinor' => 0],
        BidBlockingReason::DepositRejected,
    ],
    'held below required' => [
        ['depositHeldMinor' => 500],
        BidBlockingReason::DepositRequired,
    ],
]);

test('terms are demanded before the deposit', function () {
    $reason = (new BidEligibilityLadder)->evaluate(ladderContext([
        'participantStatus' => AuctionParticipantStatus::Registered,
        'hasAcceptedTerms' => false,
        'depositStatus' => null,
        'depositHeldMinor' => 0,
    ]));

    expect($reason)->toBe(BidBlockingReason::TermsRequired);
});

test('zero deposit auction does not block a qualified bidder', function () {
    $reason = (new BidEligibilityLadder)->evaluate(ladderContext([
        'depositStatus' => null,
        'depositHeldMinor' => 0,
        'depositRequiredMinor' => 0,
    ]));

    expect($reason)->toBe(BidBlockingReason::None);
});

test('zero deposit auction still requires qualification', function () {
    $reason = (new BidEligibilityLadder)->evaluate(ladderContext([
        'participantStatus' => AuctionParticipantStatus::Registered,
        'depositStatus' => null,
        'depositHeldMinor' => 0,
        'depositRequiredMinor' => 0,
    ]));

    expect($reason)->toBe(BidBlockingReason::NotQualified);
});

test('every blocking reason maps to an existing translation key', function () {
    $errors = require base_path('lang/ar/auction.php');

    foreach (BidBlockingReason::cases() as $reason) {
        expect($errors['errors'])->toHaveKey($reason->errorKey());
    }
});
