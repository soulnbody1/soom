<?php

declare(strict_types=1);

namespace App\Services\Auction\Refunds;

use App\DTO\Auction\RefundProcessingResult;
use App\Models\Auction\RefundTransaction;

final class ManualReviewRefundProcessor implements AuctionRefundProcessorInterface
{
    public function process(RefundTransaction $refund): RefundProcessingResult
    {
        return RefundProcessingResult::manualReviewRequired(
            'manual_provider_required',
            'No automated refund provider is configured for this refund. Manual admin confirmation is required.',
            [
                'provider' => $refund->provider,
                'contract' => 'manual_confirmation_required',
            ]
        );
    }
}
