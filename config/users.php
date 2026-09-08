<?php

declare(strict_types=1);

return [
    'rate_limits' => [
        'profile_update_per_hour' => (int) env('PROFILE_UPDATE_RATE_LIMIT_PER_HOUR', 10),
    ],
];
