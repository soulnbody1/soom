<?php

declare(strict_types=1);

namespace App\Domain\Auction\Enums;

enum PaymentPurpose: string
{
    case SellerDeposit = 'seller_deposit';
    case BidderDeposit = 'bidder_deposit';
    case WinnerSettlement = 'winner_settlement';
}
