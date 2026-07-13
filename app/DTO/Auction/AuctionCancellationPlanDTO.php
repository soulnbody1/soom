<?php

declare(strict_types=1);

namespace App\DTO\Auction;

use App\Domain\Auction\Enums\AuctionCancellationTrigger;
use App\Domain\Auction\Enums\SellerDepositDisposition;

final readonly class AuctionCancellationPlanDTO
{
    public function __construct(
        public int $auctionId,
        public AuctionCancellationTrigger $trigger,
        public string $operationKey,
        public ?int $currentSettlementId,
        public ?int $currentWinnerUserId,
        public int $pendingSubmissionCount,
        public int $successfulWinnerPaymentCount,
        public int $successfulBidderDepositPaymentCount,
        public SellerDepositDisposition $sellerDepositDisposition,
        public bool $manualReviewRequired,
        public array $manualReviewItems = [],
    ) {}

    public function metadata(): array
    {
        return [
            'auction_id' => $this->auctionId,
            'trigger' => $this->trigger->value,
            'operation_key' => $this->operationKey,
            'current_settlement_id' => $this->currentSettlementId,
            'current_winner_user_id' => $this->currentWinnerUserId,
            'pending_submission_count' => $this->pendingSubmissionCount,
            'successful_winner_payment_count' => $this->successfulWinnerPaymentCount,
            'successful_bidder_deposit_payment_count' => $this->successfulBidderDepositPaymentCount,
            'seller_deposit_disposition' => $this->sellerDepositDisposition->value,
            'manual_review_required' => $this->manualReviewRequired,
            'manual_review_items' => $this->manualReviewItems,
        ];
    }
}
