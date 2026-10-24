<?php

return [
    'live_delivery' => env('INVESTOR_NOTIFICATIONS_LIVE', false),
    'sms_driver' => env('INVESTOR_SMS_DRIVER', 'log'),
    'sms_api_key' => env('INVESTOR_SMS_API_KEY'),
    'sms_username' => env('INVESTOR_SMS_USERNAME'),
    'sms_sender' => env('INVESTOR_SMS_SENDER'),
    'twilio_sid' => env('INVESTOR_TWILIO_SID'),
    'twilio_token' => env('INVESTOR_TWILIO_TOKEN'),
];
