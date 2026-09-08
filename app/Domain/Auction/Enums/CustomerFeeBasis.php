<?php

declare(strict_types=1);

namespace App\Domain\Auction\Enums;

enum CustomerFeeBasis: string
{
    case Principal = 'principal';
    case FinalPayable = 'final_payable';
}
