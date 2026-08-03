<?php

declare(strict_types=1);

namespace App\DTO\Auction;

use App\Domain\Auction\Enums\AuctionDepositStatus;
use App\Domain\Auction\Enums\AuctionParticipantStatus;
use App\Domain\Auction\Enums\AuctionStatus;
use Carbon\CarbonInterface;

final readonly class BidEligibilityContextDTO
{
    public function __construct(
        public ?int $viewerId,
        public int $sellerId,
        public AuctionStatus $status,
        public ?CarbonInterface $startsAt,
        public ?CarbonInterface $endsAt,
        public CarbonInterface $now,
        public bool $configurationAvailable,
        public ?AuctionParticipantStatus $participantStatus = null,
        public bool $hasAcceptedTerms = false,
        public ?int $requiredTermsVersionId = null,
        public ?AuctionDepositStatus $depositStatus = null,
        public int $depositHeldMinor = 0,
        public int $depositRequiredMinor = 0,
    ) {}
}
