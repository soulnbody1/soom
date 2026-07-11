<?php

return [
    'admin_permissions' => [
        'auction.review',
        'auction.approve',
        'auction.cancel',
        'auction.payment.review',
        'auction.payment.approve',
        'auction.refund.execute',
        'auction.settlement.override',
        'auction.dispute.resolve',
    ],

    'refunds' => [
        'provider' => env('AUCTION_REFUND_PROVIDER', 'manual'),
        'auto_succeed_manual_refunds' => false,
    ],

    'non_winner_deposit_policy' => env('AUCTION_NON_WINNER_DEPOSIT_POLICY', 'hold_all_eligible_bidders_until_winner_payment'),
];
