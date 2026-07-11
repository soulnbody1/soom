<?php

declare(strict_types=1);

namespace App\Domain\Auction\Enums;

enum AuctionParticipantStatus: string
{
    case Registered = 'registered';
    case Qualified = 'qualified';
    case Blocked = 'blocked';
}
