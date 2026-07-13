<?php

declare(strict_types=1);

namespace App\Domain\Auction\Enums;

enum AuctionCancellationTrigger: string
{
    case SellerRequested = 'seller_requested';
    case AdminRequested = 'admin_requested';
    case SystemTriggered = 'system_triggered';
    case DisputeResolved = 'dispute_resolved';
    case Compliance = 'compliance';
    case Fraud = 'fraud';
    case PlatformFault = 'platform_fault';
    case SellerBreach = 'seller_breach';
    case BuyerFault = 'buyer_fault';
    case NeutralAdministrative = 'neutral_administrative';
}
