<?php

namespace App\Support;

class InvoiceDefaults
{
    public static function settings(array $settings = []): array
    {
        if (app('currentTenant')->slug === 'haven') {
            $settings['business_phone'] = "0702 898 694 - Winnie\n0705 738 666 - Maggie";
        }

        return array_replace($settings, app('currentTenant')->invoice_preferences ?? []);
    }

    /** @return array{notes: string, terms: string} */
    public static function forCompany(array $settings = []): array
    {
        $preferences = app('currentTenant')->invoice_preferences ?? [];
        if ($preferences !== []) {
            return ['notes' => (string) ($preferences['default_notes'] ?? ''), 'terms' => (string) ($preferences['default_terms'] ?? $settings['default_terms'] ?? '')];
        }
        if (app('currentTenant')->slug !== 'haven') {
            return ['notes' => '', 'terms' => (string) ($settings['default_terms'] ?? '')];
        }

        return [
            'notes' => "Thank you for choosing Haven Suites BNB\nMpesa Payment: Paybill 247 247 Account 898 694\nWe look forward to your stay!",
            'terms' => "Reservation Deposit: 50% deposit is required to book.\nBalance: Due upon check-in.\n\nCheck-in: 11:00 AM\nCheck-out: 10:00 AM\n\nCancellation:\n7+ days: Full refund.\n3-6 days: 50% refund.\n48hrs: No refund.\n\nNote: Guests are responsible for any damages.",
        ];
    }
}
