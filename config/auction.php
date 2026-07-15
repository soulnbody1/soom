<?php

return [
    'admin_permissions' => [
        'auction.review',
        'auction.approve',
        'auction.cancel',
        'auction.cancel.admin',
        'auction.cancel.compliance',
        'auction.payment.review',
        'auction.payment.approve',
        'auction.refund.execute',
        'auction.refunds.manage',
        'auction.refunds.confirm_manual',
        'auction.refunds.cancel',
        'auction.settlement.override',
        'auction.dispute.resolve',
        'auction.winners.mark_defaulted',
        'auction.payment.override_deadline',
    ],

    'refunds' => [
        'provider' => env('AUCTION_REFUND_PROVIDER', 'manual'),
        'lease_seconds' => (int) env('AUCTION_REFUND_LEASE_SECONDS', 300),
        'max_attempts' => (int) env('AUCTION_REFUND_MAX_ATTEMPTS', 5),
        'backoff_seconds' => array_map('intval', explode(',', env('AUCTION_REFUND_BACKOFF_SECONDS', '60,300,900,3600'))),
    ],

    'non_winner_deposit_policy' => env('AUCTION_NON_WINNER_DEPOSIT_POLICY', 'hold_all_eligible_bidders_until_winner_payment'),
    'non_winner_deposit_hold_count' => (int) env('AUCTION_NON_WINNER_DEPOSIT_HOLD_COUNT', 1),

    'winner_default_deposit_policy' => [
        'disposition' => env('AUCTION_WINNER_DEFAULT_DEPOSIT_DISPOSITION', 'full_forfeit'),
        'forfeit_amount_minor' => (int) env('AUCTION_WINNER_DEFAULT_DEPOSIT_FORFEIT_AMOUNT_MINOR', 0),
    ],

    'seller_deposit_policy' => [
        'unsold' => env('AUCTION_SELLER_DEPOSIT_UNSOLD', 'refund'),
        'completed' => env('AUCTION_SELLER_DEPOSIT_COMPLETED', 'refund'),
        'seller_cancellation_before_start' => env('AUCTION_SELLER_DEPOSIT_SELLER_CANCEL_BEFORE_START', 'refund'),
        'seller_cancellation_after_start' => env('AUCTION_SELLER_DEPOSIT_SELLER_CANCEL_AFTER_START', 'manual_review'),
        'admin_cancellation_platform_fault' => env('AUCTION_SELLER_DEPOSIT_ADMIN_PLATFORM_FAULT', 'refund'),
        'admin_cancellation_seller_fault' => env('AUCTION_SELLER_DEPOSIT_ADMIN_SELLER_FAULT', 'forfeit'),
        'admin_cancellation_neutral' => env('AUCTION_SELLER_DEPOSIT_ADMIN_NEUTRAL', 'refund'),
        'admin_cancellation_fraud_or_compliance' => env('AUCTION_SELLER_DEPOSIT_ADMIN_FRAUD_OR_COMPLIANCE', 'manual_review'),
        'system_cancellation_platform_fault' => env('AUCTION_SELLER_DEPOSIT_SYSTEM_PLATFORM_FAULT', 'refund'),
        'system_cancellation_seller_fault' => env('AUCTION_SELLER_DEPOSIT_SYSTEM_SELLER_FAULT', 'forfeit'),
        'system_cancellation_neutral' => env('AUCTION_SELLER_DEPOSIT_SYSTEM_NEUTRAL', 'refund'),
        'winner_default' => env('AUCTION_SELLER_DEPOSIT_WINNER_DEFAULT', 'keep_held'),
        'seller_breach' => env('AUCTION_SELLER_DEPOSIT_SELLER_BREACH', 'forfeit'),
        'dispute_complete' => env('AUCTION_SELLER_DEPOSIT_DISPUTE_COMPLETE', 'refund'),
        'dispute_cancel' => env('AUCTION_SELLER_DEPOSIT_DISPUTE_CANCEL', 'manual_review'),
        'dispute_resume_handover' => env('AUCTION_SELLER_DEPOSIT_DISPUTE_RESUME_HANDOVER', 'keep_held'),
    ],

    'outbox' => [
        'max_attempts' => (int) env('AUCTION_OUTBOX_MAX_ATTEMPTS', 3),
        'retry_delay_seconds' => (int) env('AUCTION_OUTBOX_RETRY_DELAY_SECONDS', 300),
    ],
];
