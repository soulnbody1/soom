<?php

declare(strict_types=1);

return [
    'length' => 4,

    'ttl_minutes' => (int) env('OTP_TTL_MINUTES', 15),

    'max_verification_attempts' => (int) env('OTP_MAX_VERIFICATION_ATTEMPTS', 5),

    'whatsapp' => [
        'timeout_seconds' => (int) env('OTP_WHATSAPP_TIMEOUT_SECONDS', 10),
        'connect_timeout_seconds' => (int) env('OTP_WHATSAPP_CONNECT_TIMEOUT_SECONDS', 5),
    ],

    'send' => [
        'cooldown_seconds' => (int) env('OTP_SEND_COOLDOWN_SECONDS', 120),
        'max_per_window' => (int) env('OTP_SEND_MAX_PER_WINDOW', 4),
        'window_seconds' => (int) env('OTP_SEND_WINDOW_SECONDS', 86400),
        'max_per_ip_per_hour' => (int) env('OTP_SEND_MAX_PER_IP_PER_HOUR', 10),
    ],

    'rate_limits' => [
        'login_per_minute' => (int) env('AUTH_LOGIN_RATE_LIMIT_PER_MINUTE', 10),
        'register_per_hour' => (int) env('AUTH_REGISTER_RATE_LIMIT_PER_HOUR', 5),
        'otp_request_per_hour' => (int) env('AUTH_OTP_REQUEST_RATE_LIMIT_PER_HOUR', 10),
        'otp_verify_per_minute' => (int) env('AUTH_OTP_VERIFY_RATE_LIMIT_PER_MINUTE', 10),
        'refresh_per_minute' => (int) env('AUTH_REFRESH_RATE_LIMIT_PER_MINUTE', 20),
    ],
];
