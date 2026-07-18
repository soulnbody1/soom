<?php

declare(strict_types=1);

namespace App\Domain\Auction\Enums;

enum NonWinnerDepositHoldPolicy: string
{
    case RefundAllImmediately = 'refund_all_non_winners_immediately';
    case HoldTopN = 'hold_top_n_bidders_until_winner_payment';
    case HoldAllEligible = 'hold_all_eligible_bidders_until_winner_payment';
}
