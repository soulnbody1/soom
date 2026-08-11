<?php

return [
    'enabled' => (bool) env('CONTENT_REVIEW_ENABLED', false),

    'default_mode' => env('CONTENT_REVIEW_DEFAULT_MODE', 'manual'),

    'provider' => env('CONTENT_REVIEW_PROVIDER', 'fake'),

    'model' => env('CONTENT_REVIEW_MODEL', 'claude-sonnet-5'),

    'queue' => env('CONTENT_REVIEW_QUEUE', 'content-review'),

    'sweeper' => [
        'batch' => (int) env('CONTENT_REVIEW_SWEEPER_BATCH', 25),
        'requeue_after_seconds' => (int) env('CONTENT_REVIEW_REQUEUE_AFTER_SECONDS', 60),
        'lease_seconds' => (int) env('CONTENT_REVIEW_LEASE_SECONDS', 300),
    ],

    'defaults' => [
        'timeout_seconds' => (int) env('CONTENT_REVIEW_TIMEOUT_SECONDS', 45),
        'max_attempts' => (int) env('CONTENT_REVIEW_MAX_ATTEMPTS', 3),
        'backoff_seconds' => array_map('intval', explode(',', (string) env('CONTENT_REVIEW_BACKOFF_SECONDS', '60,300,900'))),
        'max_concurrent' => (int) env('CONTENT_REVIEW_MAX_CONCURRENT', 5),
        'max_output_tokens' => (int) env('CONTENT_REVIEW_MAX_OUTPUT_TOKENS', 2000),
        'daily_budget_micros' => (int) env('CONTENT_REVIEW_DAILY_BUDGET_MICROS', 5_000_000),
        'monthly_budget_micros' => (int) env('CONTENT_REVIEW_MONTHLY_BUDGET_MICROS', 100_000_000),
        'circuit_breaker' => [
            'failure_threshold' => (int) env('CONTENT_REVIEW_CIRCUIT_FAILURE_THRESHOLD', 5),
            'window_seconds' => (int) env('CONTENT_REVIEW_CIRCUIT_WINDOW_SECONDS', 300),
            'open_seconds' => (int) env('CONTENT_REVIEW_CIRCUIT_OPEN_SECONDS', 600),
        ],
    ],

    'pricing' => [
        'version' => env('CONTENT_REVIEW_PRICING_VERSION', '2026-06-24'),
        'currency' => 'USD',
        'models' => [
            'claude-fable-5' => ['input' => 10_000_000, 'output' => 50_000_000],
            'claude-opus-5' => ['input' => 5_000_000, 'output' => 25_000_000],
            'claude-opus-4-8' => ['input' => 5_000_000, 'output' => 25_000_000],
            'claude-sonnet-5' => ['input' => 3_000_000, 'output' => 15_000_000],
            'claude-sonnet-4-6' => ['input' => 3_000_000, 'output' => 15_000_000],
            'claude-haiku-4-5' => ['input' => 1_000_000, 'output' => 5_000_000],
        ],
    ],

    'budget' => [
        'estimated_input_tokens' => (int) env('CONTENT_REVIEW_ESTIMATED_INPUT_TOKENS', 4000),
        'fallback_estimate_micros' => (int) env('CONTENT_REVIEW_FALLBACK_ESTIMATE_MICROS', 5000),
        'reservation_ttl_seconds' => (int) env('CONTENT_REVIEW_RESERVATION_TTL_SECONDS', 900),
        'lock_seconds' => (int) env('CONTENT_REVIEW_BUDGET_LOCK_SECONDS', 5),
    ],

    'concurrency' => [
        'slot_ttl_seconds' => (int) env('CONTENT_REVIEW_SLOT_TTL_SECONDS', 900),
        'release_delay_seconds' => (int) env('CONTENT_REVIEW_SLOT_RELEASE_DELAY', 30),
    ],

    'limits' => [
        'max_text_chars' => (int) env('CONTENT_REVIEW_MAX_TEXT_CHARS', 8000),
        'max_images' => (int) env('CONTENT_REVIEW_MAX_IMAGES', 4),
        'max_image_bytes' => (int) env('CONTENT_REVIEW_MAX_IMAGE_BYTES', 2_000_000),
        'allowed_image_mimes' => ['image/jpeg', 'image/png', 'image/webp'],
    ],

    'provider_test' => [
        'rate_limit_per_minute' => (int) env('CONTENT_REVIEW_PROVIDER_TEST_PER_MINUTE', 3),
        'rate_limit_per_hour' => (int) env('CONTENT_REVIEW_PROVIDER_TEST_PER_HOUR', 20),
    ],

    'alerts' => [
        'per_subject_cooldown_seconds' => (int) env('CONTENT_REVIEW_ALERT_SUBJECT_COOLDOWN', 3600),
        'global_cooldown_seconds' => (int) env('CONTENT_REVIEW_ALERT_GLOBAL_COOLDOWN', 3600),
        'repeat_seconds' => (int) env('CONTENT_REVIEW_ALERT_REPEAT_SECONDS', 21_600),
        'state_ttl_seconds' => (int) env('CONTENT_REVIEW_ALERT_STATE_TTL_SECONDS', 604_800),
        'evaluation_cache_seconds' => (int) env('CONTENT_REVIEW_ALERT_EVALUATION_CACHE_SECONDS', 60),
        'queue_delay_seconds' => (int) env('CONTENT_REVIEW_ALERT_QUEUE_DELAY_SECONDS', 600),
        'invalid_output_percent' => (int) env('CONTENT_REVIEW_ALERT_INVALID_OUTPUT_PERCENT', 10),
        'invalid_output_min_sample' => (int) env('CONTENT_REVIEW_ALERT_INVALID_OUTPUT_SAMPLE', 50),
        'invalid_output_window_hours' => (int) env('CONTENT_REVIEW_ALERT_INVALID_OUTPUT_WINDOW_HOURS', 24),
        'permanent_failure_threshold' => (int) env('CONTENT_REVIEW_ALERT_PERMANENT_FAILURES', 5),
        'permanent_failure_window_hours' => (int) env('CONTENT_REVIEW_ALERT_PERMANENT_FAILURE_WINDOW_HOURS', 1),
        'escalation_backlog_threshold' => (int) env('CONTENT_REVIEW_ALERT_ESCALATION_BACKLOG', 50),
    ],

    'metrics' => [
        'cache_seconds' => (int) env('CONTENT_REVIEW_METRICS_CACHE_SECONDS', 60),
        'max_range_days' => (int) env('CONTENT_REVIEW_METRICS_MAX_RANGE_DAYS', 92),
        'default_range' => env('CONTENT_REVIEW_METRICS_DEFAULT_RANGE', '7d'),
    ],

    'heartbeat' => [
        'ttl_seconds' => (int) env('CONTENT_REVIEW_HEARTBEAT_TTL_SECONDS', 86_400),
    ],

    'backfill' => [
        'default_limit' => (int) env('CONTENT_REVIEW_BACKFILL_DEFAULT_LIMIT', 50),
        'max_limit' => (int) env('CONTENT_REVIEW_BACKFILL_MAX_LIMIT', 500),
        'chunk' => (int) env('CONTENT_REVIEW_BACKFILL_CHUNK', 100),
    ],

    'reconcile' => [
        'stale_queued_seconds' => (int) env('CONTENT_REVIEW_RECONCILE_STALE_QUEUED_SECONDS', 3600),
        'limit' => (int) env('CONTENT_REVIEW_RECONCILE_LIMIT', 200),
    ],

    'admin_permissions' => [
        'content_review.view',
        'content_review.run',
        'content_review.cancel',
        'content_review.force_manual',
        'content_review.override',
        'content_review.settings.manage',
        'content_review.policy.manage',
        'content_review.costs.view',
        'content_review.technical.view',
        'content_review.metrics.view',
    ],

    'role_admin_permissions' => [
        'content_review.view',
        'content_review.run',
        'content_review.cancel',
    ],
];
