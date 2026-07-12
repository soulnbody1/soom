<?php

return [
    'admin_permissions' => [
        'auction.review',
        'auction.approve',
        'auction.cancel',
        'auction.payment.review',
        'auction.payment.approve',
        'auction.refund.execute',
        'auction.refunds.confirm_manual',
        'auction.refunds.cancel',
        'auction.settlement.override',
        'auction.dispute.resolve',
    ],

    'refunds' => [
        'provider' => env('AUCTION_REFUND_PROVIDER', 'manual'),
        'auto_succeed_manual_refunds' => false,
        'lease_seconds' => (int) env('AUCTION_REFUND_LEASE_SECONDS', 300),
        'max_attempts' => (int) env('AUCTION_REFUND_MAX_ATTEMPTS', 5),
        'backoff_seconds' => array_map('intval', explode(',', env('AUCTION_REFUND_BACKOFF_SECONDS', '60,300,900,3600'))),
    ],

    'non_winner_deposit_policy' => env('AUCTION_NON_WINNER_DEPOSIT_POLICY', 'hold_all_eligible_bidders_until_winner_payment'),
    'non_winner_deposit_hold_count' => (int) env('AUCTION_NON_WINNER_DEPOSIT_HOLD_COUNT', 1),

    'outbox' => [
        'max_attempts' => (int) env('AUCTION_OUTBOX_MAX_ATTEMPTS', 3),
        'retry_delay_seconds' => (int) env('AUCTION_OUTBOX_RETRY_DELAY_SECONDS', 60),
    ],
];
