<?php

$rootDomain = env('SOOM_ROOT_DOMAIN', 'soom.test');

return [
    'root_domain' => $rootDomain,
    'admin_api_host' => env('SOOM_ADMIN_API_HOST', 'api-admin.'.$rootDomain),
    'legacy_api_host' => env('SOOM_LEGACY_API_HOST') ?: null,
    'legacy_market_code' => strtoupper((string) env('SOOM_LEGACY_MARKET_CODE', 'JO')),
    'legacy_sunset' => env('SOOM_LEGACY_SUNSET') ?: null,
    'default_market_code' => strtoupper((string) env('SOOM_DEFAULT_MARKET_CODE', 'JO')),
    'development_market_code' => strtoupper((string) env('SOOM_DEV_MARKET_CODE', env('SOOM_DEFAULT_MARKET_CODE', 'JO'))),
    'trusted_proxies' => array_values(array_filter(array_map('trim', explode(',', (string) env('SOOM_TRUSTED_PROXY_IPS', ''))))),
];
