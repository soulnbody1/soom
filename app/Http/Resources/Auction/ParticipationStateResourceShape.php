<?php

declare(strict_types=1);

namespace App\Http\Resources\Auction;

use App\Domain\Auction\Enums\BidBlockingReason;
use App\Domain\Auction\Enums\ParticipationDepositStatus;
use App\DTO\Auction\ParticipationStateDTO;

final class ParticipationStateResourceShape
{
    public static function fromState(ParticipationStateDTO $state): array
    {
        return [
            'is_registered' => $state->isRegistered,
            'participant_status' => $state->participantStatus?->value,
            'registered_at' => $state->registeredAt,
            'qualified_at' => $state->qualifiedAt,
            'terms_accepted' => $state->termsAccepted,
            'terms_accepted_at' => $state->termsAcceptedAt,
            'required_terms_version_id' => $state->requiredTermsVersionId,
            'deposit_required' => MoneyResource::make($state->depositRequiredMinor, $state->currencyCode),
            'deposit_status' => $state->depositStatus->value,
            'can_bid' => $state->canBid,
            'blocking_reason' => $state->blockingReason === BidBlockingReason::None
                ? null
                : $state->blockingReason->value,
            'is_highest_bidder' => $state->isHighestBidder,
            'my_highest_bid' => $state->myHighestBidMinor === null
                ? null
                : MoneyResource::make($state->myHighestBidMinor, $state->currencyCode),
            'my_bids_count' => $state->myBidsCount,
            'is_winner' => $state->isWinner,
            'is_seller' => $state->isSeller,
        ];
    }

    public static function guestShape(string $currencyCode): array
    {
        return [
            'is_registered' => false,
            'participant_status' => null,
            'registered_at' => null,
            'qualified_at' => null,
            'terms_accepted' => false,
            'terms_accepted_at' => null,
            'required_terms_version_id' => null,
            'deposit_required' => MoneyResource::make(0, $currencyCode),
            'deposit_status' => ParticipationDepositStatus::NotRequired->value,
            'can_bid' => false,
            'blocking_reason' => BidBlockingReason::AuthenticationRequired->value,
            'is_highest_bidder' => false,
            'my_highest_bid' => null,
            'my_bids_count' => 0,
            'is_winner' => false,
            'is_seller' => false,
        ];
    }
}
