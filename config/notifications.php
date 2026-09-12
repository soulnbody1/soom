<?php

declare(strict_types=1);

return [
    'rate_limits' => [
        'read_per_minute' => (int) env('NOTIFICATIONS_READ_RATE_LIMIT_PER_MINUTE', 120),
        'write_per_minute' => (int) env('NOTIFICATIONS_WRITE_RATE_LIMIT_PER_MINUTE', 60),
    ],

    'per_page' => [
        'default' => (int) env('NOTIFICATIONS_PER_PAGE_DEFAULT', 10),
        'max' => (int) env('NOTIFICATIONS_PER_PAGE_MAX', 50),
    ],

    'queue' => env('NOTIFICATIONS_QUEUE', 'default'),

    'retention_days' => (int) env('NOTIFICATIONS_RETENTION_DAYS', 90),
];
