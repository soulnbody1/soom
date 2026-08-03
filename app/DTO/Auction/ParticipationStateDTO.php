<?php

declare(strict_types=1);

namespace App\DTO\Auction;

use App\Domain\Auction\Enums\AuctionParticipantStatus;
use App\Domain\Auction\Enums\BidBlockingReason;
use App\Domain\Auction\Enums\NextActionCode;
use App\Domain\Auction\Enums\ParticipationDepositStatus;

final readonly class ParticipationStateDTO
{
    public function __construct(
        public bool $isRegistered,
        public ?AuctionParticipantStatus $participantStatus,
        public ?string $registeredAt,
        public ?string $qualifiedAt,
        public bool $termsAccepted,
        public ?string $termsAcceptedAt,
        public ?string $requiredTermsVersionId,
        public int $depositRequiredMinor,
        public string $currencyCode,
        public ParticipationDepositStatus $depositStatus,
        public bool $canBid,
        public BidBlockingReason $blockingReason,
        public bool $isHighestBidder,
        public ?int $myHighestBidMinor,
        public int $myBidsCount,
        public bool $isWinner,
        public bool $isSeller,
        public NextActionCode $nextAction,
        public bool $nextActionAllowed,
    ) {}

    public static function guest(int $depositRequiredMinor, string $currencyCode, ?string $requiredTermsVersionId): self
    {
        return new self(
            isRegistered: false,
            participantStatus: null,
            registeredAt: null,
            qualifiedAt: null,
            termsAccepted: false,
            termsAcceptedAt: null,
            requiredTermsVersionId: $requiredTermsVersionId,
            depositRequiredMinor: $depositRequiredMinor,
            currencyCode: $currencyCode,
            depositStatus: $depositRequiredMinor > 0
                ? ParticipationDepositStatus::NotSubmitted
                : ParticipationDepositStatus::NotRequired,
            canBid: false,
            blockingReason: BidBlockingReason::AuthenticationRequired,
            isHighestBidder: false,
            myHighestBidMinor: null,
            myBidsCount: 0,
            isWinner: false,
            isSeller: false,
            nextAction: NextActionCode::Login,
            nextActionAllowed: true,
        );
    }
}
