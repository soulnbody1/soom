<?php

declare(strict_types=1);

return [
    'rate_limits' => [
        'public_per_minute' => (int) env('CATALOG_PUBLIC_RATE_LIMIT_PER_MINUTE', 120),
    ],

    'between_range_limit' => (int) env('CATALOG_BETWEEN_RANGE_LIMIT', 500),
];
