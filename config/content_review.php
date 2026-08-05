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
    ],

    'role_admin_permissions' => [
        'content_review.view',
        'content_review.run',
        'content_review.cancel',
    ],
];
