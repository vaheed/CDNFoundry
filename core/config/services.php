<?php

return [
    'geoip' => ['database' => env('GEOIP_DATABASE', '/mmdb/GeoLite2-City.mmdb')],
    'acme' => [
        'enabled' => filter_var(env('ACME_ENABLED', false), FILTER_VALIDATE_BOOL),
        'verify_tls' => filter_var(env('ACME_VERIFY_TLS', true), FILTER_VALIDATE_BOOL),
        'directory_url' => env('ACME_DIRECTORY_URL', 'https://acme-v02.api.letsencrypt.org/directory'),
        'contact_email' => env('ACME_CONTACT_EMAIL'),
        'order_budget_per_hour' => (int) env('ACME_ORDER_BUDGET_PER_HOUR', 20),
        'initial_jitter_seconds' => (int) env('ACME_INITIAL_JITTER_SECONDS', 300),
        'dns_ttl' => (int) env('ACME_DNS_TTL', 60),
        'challenge_lifetime_minutes' => (int) env('ACME_CHALLENGE_LIFETIME_MINUTES', 30),
        'renew_before_days' => (int) env('ACME_RENEW_BEFORE_DAYS', 30),
        'expiry_alert_days' => (int) env('TLS_EXPIRY_ALERT_DAYS', 14),
    ],

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
