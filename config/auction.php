<?php

use App\Services\Auction\Payments\Providers\EFawateercomPaymentProvider;
use App\Services\Auction\Payments\Providers\FakeBillPaymentProvider;
use App\Services\Auction\Payments\Providers\FakePaymentProvider;
use App\Services\Auction\Payments\Providers\NGeniusPaymentProvider;

return [
    'admin_permissions' => [
        'auction.review',
        'auction.approve',
        'auction.cancel',
        'auction.cancel.admin',
        'auction.cancel.compliance',
        'auction.end_early',
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
        'auction.payouts.view',
        'auction.payouts.manage',
        'auction.dashboard.view',
        'auction.participants.block',
    ],

    'payments' => [
        'allow_fake_provider' => (bool) env('AUCTION_PAYMENTS_ALLOW_FAKE_PROVIDER', false),
        'disabled_providers' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('AUCTION_PAYMENTS_DISABLED_PROVIDERS', ''))
        ))),
        'intent_ttl_seconds' => (int) env('AUCTION_PAYMENTS_INTENT_TTL_SECONDS', 1800),
        'checkout_claim_seconds' => (int) env('AUCTION_PAYMENTS_CHECKOUT_CLAIM_SECONDS', 120),
        'reconcile_after_seconds' => (int) env('AUCTION_PAYMENTS_RECONCILE_AFTER_SECONDS', 300),
        'reconcile_batch' => (int) env('AUCTION_PAYMENTS_RECONCILE_BATCH', 100),
        'webhook_max_body_bytes' => (int) env('AUCTION_PAYMENTS_WEBHOOK_MAX_BODY_BYTES', 65536),
        'intent_rate_limit_per_minute' => (int) env('AUCTION_PAYMENTS_INTENT_RATE_LIMIT_PER_MINUTE', 10),
        'webhook_rate_limit_per_minute' => (int) env('AUCTION_PAYMENTS_WEBHOOK_RATE_LIMIT_PER_MINUTE', 600),
        'bill_query_rate_limit_per_minute' => (int) env('AUCTION_PAYMENTS_BILL_QUERY_RATE_LIMIT_PER_MINUTE', 3000),
        'return_url' => env('AUCTION_PAYMENTS_RETURN_URL', env('APP_URL', 'http://localhost').'/payments/return'),
        'providers' => [
            'fake' => [
                'class' => FakePaymentProvider::class,
                'required_credentials' => ['webhook_secret'],
                'checkout_url' => env('AUCTION_PAYMENTS_FAKE_CHECKOUT_URL', 'https://fake-checkout.test'),
            ],

            'fake_bill' => [
                'class' => FakeBillPaymentProvider::class,
                'required_credentials' => ['webhook_secret'],
                'bill_ttl_seconds' => (int) env('AUCTION_PAYMENTS_FAKE_BILL_TTL_SECONDS', 86400),
                'billing_reference' => ['length' => 10, 'charset' => 'numeric', 'check_digit' => true],
                'bill_reference' => ['length' => 12, 'charset' => 'numeric', 'check_digit' => true, 'no_leading_zero' => true],
            ],

            'efawateercom' => [
                'class' => EFawateercomPaymentProvider::class,
                'required_credentials' => ['biller_code', 'username', 'password'],
                'purpose_codes' => [
                    'bidder_deposit' => env('AUCTION_PAYMENTS_EFAWATEERCOM_BIDDER_DEPOSIT_CODE', 'SOOMBID'),
                    'seller_deposit' => env('AUCTION_PAYMENTS_EFAWATEERCOM_SELLER_DEPOSIT_CODE', 'SOOMSELL'),
                    'winner_settlement' => env('AUCTION_PAYMENTS_EFAWATEERCOM_WINNER_SETTLEMENT_CODE', 'SOOMWIN'),
                ],
                'bill_ttl_seconds' => (int) env('AUCTION_PAYMENTS_EFAWATEERCOM_BILL_TTL_SECONDS', 86400),
                'bill_reference' => [
                    'length' => (int) env('AUCTION_PAYMENTS_EFAWATEERCOM_REFERENCE_LENGTH', 12),
                    'charset' => 'numeric',
                    'check_digit' => true,
                    'no_leading_zero' => true,
                ],
                'bill_status' => env('AUCTION_PAYMENTS_EFAWATEERCOM_BILL_STATUS', 'BillNew'),
                'bill_type' => env('AUCTION_PAYMENTS_EFAWATEERCOM_BILL_TYPE', 'OneOff'),
                'bill_error_codes' => [
                    'bill_already_paid' => (int) env('AUCTION_PAYMENTS_EFAWATEERCOM_CODE_ALREADY_PAID', 324),
                    'bill_not_found' => (int) env('AUCTION_PAYMENTS_EFAWATEERCOM_CODE_NOT_FOUND', 404),
                    'bill_not_payable' => (int) env('AUCTION_PAYMENTS_EFAWATEERCOM_CODE_NOT_PAYABLE', 404),
                    'no_payable_bills' => (int) env('AUCTION_PAYMENTS_EFAWATEERCOM_CODE_NO_BILLS', 404),
                    'unknown_billing_reference' => (int) env('AUCTION_PAYMENTS_EFAWATEERCOM_CODE_NOT_FOUND', 404),
                    'default' => 404,
                ],
                'request_error_codes' => [
                    'unauthenticated' => (int) env('AUCTION_PAYMENTS_EFAWATEERCOM_CODE_UNAUTHENTICATED', 401),
                    'invalid_request' => (int) env('AUCTION_PAYMENTS_EFAWATEERCOM_CODE_INVALID', 400),
                    'unmatched' => (int) env('AUCTION_PAYMENTS_EFAWATEERCOM_CODE_UNMATCHED', 404),
                    'default' => 400,
                ],
            ],

            'ngenius' => [
                'class' => NGeniusPaymentProvider::class,
                'required_credentials' => ['api_key', 'outlet_reference', 'base_url', 'webhook_secret'],
                'sandbox' => (bool) env('AUCTION_PAYMENTS_NGENIUS_SANDBOX', true),
                'currencies' => array_values(array_filter(array_map(
                    'trim',
                    explode(',', (string) env('AUCTION_PAYMENTS_NGENIUS_CURRENCIES', 'JOD'))
                ))),
                'timeout_seconds' => (int) env('AUCTION_PAYMENTS_NGENIUS_TIMEOUT_SECONDS', 20),
                'checkout_ttl_seconds' => (int) env('AUCTION_PAYMENTS_NGENIUS_CHECKOUT_TTL_SECONDS', 1800),
                'fallback_email' => env('AUCTION_PAYMENTS_NGENIUS_FALLBACK_EMAIL', 'payments@soom.jo'),
            ],
        ],
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

    'deadlines' => [
        'winner_payment_grace_period_hours' => (int) env('AUCTION_WINNER_PAYMENT_GRACE_PERIOD_HOURS', 24),
        'winner_payment_reminder_hours_before' => array_values(array_filter(
            array_map('intval', explode(',', (string) env('AUCTION_WINNER_PAYMENT_REMINDER_HOURS_BEFORE', '24,6,1'))),
            static fn (int $hour): bool => $hour > 0
        )),
        'handover_reminder_hours_before' => array_values(array_filter(
            array_map('intval', explode(',', (string) env('AUCTION_HANDOVER_REMINDER_HOURS_BEFORE', '24,1'))),
            static fn (int $hour): bool => $hour > 0
        )),
        'seller_deposit_deadline_hours' => (int) env('AUCTION_SELLER_DEPOSIT_DEADLINE_HOURS', 48),
        'seller_deposit_expiry_lease_minutes' => (int) env('AUCTION_SELLER_DEPOSIT_EXPIRY_LEASE_MINUTES', 15),
        'review_sla_hours' => (int) env('AUCTION_REVIEW_SLA_HOURS', 24),
    ],

    'bidding' => [
        'rate_limit_per_minute' => (int) env('AUCTION_BID_RATE_LIMIT_PER_MINUTE', 30),
        'rate_limit_per_minute_per_ip' => (int) env('AUCTION_BID_RATE_LIMIT_PER_MINUTE_PER_IP', 120),
    ],

    'participation' => [
        'rate_limit_per_minute' => (int) env('AUCTION_PARTICIPATION_RATE_LIMIT_PER_MINUTE', 20),
        'rate_limit_per_minute_per_ip' => (int) env('AUCTION_PARTICIPATION_RATE_LIMIT_PER_MINUTE_PER_IP', 60),
    ],
];
