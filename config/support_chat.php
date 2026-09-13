<?php

declare(strict_types=1);

return [
    'message_max_length' => (int) env('SUPPORT_MESSAGE_MAX_LENGTH', 5000),
    'subject_max_length' => (int) env('SUPPORT_SUBJECT_MAX_LENGTH', 160),
    'reopen_window_days' => (int) env('SUPPORT_REOPEN_WINDOW_DAYS', 14),
    'rate_limits' => [
        'read_per_minute' => (int) env('SUPPORT_READ_PER_MINUTE', 120),
        'create_per_hour' => (int) env('SUPPORT_CREATE_PER_HOUR', 10),
        'message_per_minute' => (int) env('SUPPORT_MESSAGE_PER_MINUTE', 20),
        'admin_write_per_minute' => (int) env('SUPPORT_ADMIN_WRITE_PER_MINUTE', 90),
    ],
];
