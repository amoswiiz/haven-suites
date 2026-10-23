<?php

return [
    'sandbox_enabled' => (bool) env('SAAS_BILLING_SANDBOX_ENABLED', false),
    'prices_minor' => [
        'starter' => (int) env('SAAS_STARTER_PRICE_MINOR', 30000),
        'team' => (int) env('SAAS_TEAM_PRICE_MINOR', 150000),
        'scale' => (int) env('SAAS_SCALE_PRICE_MINOR', 250000),
    ],
    'stripe' => ['secret' => env('STRIPE_TEST_SECRET'), 'webhook_secret' => env('STRIPE_TEST_WEBHOOK_SECRET')],
    'mpesa' => ['key' => env('MPESA_SANDBOX_CONSUMER_KEY'), 'secret' => env('MPESA_SANDBOX_CONSUMER_SECRET'), 'shortcode' => env('MPESA_SANDBOX_SHORTCODE'), 'passkey' => env('MPESA_SANDBOX_PASSKEY'), 'callback_base_url' => env('MPESA_SANDBOX_CALLBACK_BASE_URL')],
    'manual_instructions' => 'Sandbox invoice for testing only. Do not transfer money against this reference.',
];
