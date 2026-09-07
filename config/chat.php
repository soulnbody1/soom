<?php

declare(strict_types=1);

return [
    'rate_limits' => [
        'send_per_minute' => (int) env('CHAT_SEND_RATE_LIMIT_PER_MINUTE', 30),
        'read_per_minute' => (int) env('CHAT_READ_RATE_LIMIT_PER_MINUTE', 120),
        'write_per_minute' => (int) env('CHAT_WRITE_RATE_LIMIT_PER_MINUTE', 60),
    ],

    'presence_timeout_seconds' => (int) env('CHAT_PRESENCE_TIMEOUT_SECONDS', 3),

    'presence_enabled' => (bool) env('CHAT_PRESENCE_ENABLED', true),
];
