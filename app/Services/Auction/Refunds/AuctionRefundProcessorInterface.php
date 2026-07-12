<?php

declare(strict_types=1);

namespace App\Services\Auction\Refunds;

use App\DTO\Auction\RefundProcessingResult;
use App\Models\Auction\RefundTransaction;

interface AuctionRefundProcessorInterface
{
    public function process(RefundTransaction $refund): RefundProcessingResult;
}
