<?php

declare(strict_types=1);

return [
    'rate_limits' => [
        'public_per_minute' => (int) env('ADS_PUBLIC_RATE_LIMIT_PER_MINUTE', 120),
        'search_per_minute' => (int) env('ADS_SEARCH_RATE_LIMIT_PER_MINUTE', 30),
        'write_per_hour' => (int) env('ADS_WRITE_RATE_LIMIT_PER_HOUR', 10),
        'engagement_per_minute' => (int) env('ADS_ENGAGEMENT_RATE_LIMIT_PER_MINUTE', 60),
    ],

    'notifications' => [
        'chunk_size' => (int) env('ADS_NOTIFICATION_CHUNK_SIZE', 200),
        'interaction_threshold' => (int) env('ADS_NOTIFICATION_INTERACTION_THRESHOLD', 3),
    ],
];
