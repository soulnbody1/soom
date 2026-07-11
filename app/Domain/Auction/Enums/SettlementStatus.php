<?php

declare(strict_types=1);

namespace App\Domain\Auction\Enums;

enum SettlementStatus: string
{
    case PaymentPending = 'payment_pending';
    case Paid = 'paid';
    case HandoverPending = 'handover_pending';
    case Completed = 'completed';
    case Defaulted = 'defaulted';
    case Disputed = 'disputed';
    case Cancelled = 'cancelled';
}
