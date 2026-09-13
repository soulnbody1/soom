<?php

declare(strict_types=1);

return [
    'message_max_length' => (int) env('SELLER_RATING_MESSAGE_MAX_LENGTH', 1000),
    'pagination' => [
        'default' => (int) env('SELLER_RATINGS_PER_PAGE', 15),
        'maximum' => (int) env('SELLER_RATINGS_MAX_PER_PAGE', 50),
    ],
    'rate_limits' => [
        'read_per_minute' => (int) env('SELLER_RATINGS_READ_PER_MINUTE', 120),
        'write_per_hour' => (int) env('SELLER_RATINGS_WRITE_PER_HOUR', 20),
    ],
];
