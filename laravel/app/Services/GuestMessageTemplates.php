<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

class GuestMessageTemplates
{
    public const PURPOSES = ['confirmation' => 'Booking confirmation', 'check_in' => 'Check-in instructions', 'checkout_reminder' => 'Checkout reminder', 'checkout' => 'Checkout thanks', 'review' => 'Ask for a review', 'upcoming' => 'Upcoming stay reminder', 'custom' => 'Custom message'];

    public const FIELDS = ['guest_name', 'company_name', 'apartment', 'property_name', 'check_in', 'check_out', 'reservation_id'];

    public const STARTERS = [
        'confirmation' => 'Hi {{guest_name}}, your booking with {{company_name}} is confirmed for {{check_in}} to {{check_out}}. We look forward to welcoming you!',
        'check_in' => 'Welcome to {{company_name}}, {{guest_name}}! Your apartment is {{apartment}} at {{property_name}}. Check-in: {{check_in}}. We look forward to hosting you!',
        'checkout' => 'Thank you for staying with {{company_name}}, {{guest_name}}! We hope you enjoyed your stay at {{apartment}} and look forward to welcoming you again.',
        'checkout_reminder' => 'Hi {{guest_name}}, we hope you enjoyed your stay with {{company_name}}. Your checkout date is {{check_out}}. Please let us know your expected departure time.',
        'review' => 'Hi {{guest_name}}, thank you for choosing {{company_name}}! We would love to hear about your stay. Please share your feedback with us.',
        'upcoming' => 'Hi {{guest_name}}, we look forward to welcoming you to {{company_name}} on {{check_in}}. Your stay at {{apartment}} is from {{check_in}} to {{check_out}}. Please let us know your expected arrival time.',
        'custom' => 'Hello {{guest_name}}, ',
    ];

    public function validateBody(string $body): void
    {
        preg_match_all('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/', $body, $matches);
        $unknown = array_diff($matches[1], self::FIELDS);
        $remaining = preg_replace('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/', '', $body);
        if ($unknown || str_contains($remaining, '{{') || str_contains($remaining, '}}')) {
            throw ValidationException::withMessages(['body' => 'Use only the supported placeholders: '.implode(', ', self::FIELDS).'.']);
        }
    }

    public function render(string $body, array $context): string
    {
        $this->validateBody($body);
        $text = preg_replace_callback('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/', function (array $match) use ($context): string {
            if (! isset($context[$match[1]]) || trim((string) $context[$match[1]]) === '') {
                throw ValidationException::withMessages(['body' => 'Missing '.$match[1].'. Choose a booking/apartment or replace that placeholder with text.']);
            }

            return (string) $context[$match[1]];
        }, $body);
        if (mb_strlen($text) > 1600 || trim($text) === '' || str_contains($text, '{{') || str_contains($text, '}}')) {
            throw ValidationException::withMessages(['body' => 'The final SMS must contain 1–1,600 characters and no unresolved placeholders.']);
        }

        return $text;
    }

    public function phone(?string $phone): string
    {
        $phone = preg_replace('/[\s()\-]/', '', $phone ?? '');
        if (preg_match('/^0[17]\d{8}$/D', $phone)) {
            return '+254'.substr($phone, 1);
        }
        if (preg_match('/^254[17]\d{8}$/D', $phone)) {
            return '+'.$phone;
        }
        if (str_starts_with($phone, '00')) {
            return '+'.substr($phone, 2);
        }

        return $phone;
    }
}
