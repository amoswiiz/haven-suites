<?php

namespace App\Services;

use App\Models\Tenant;
use Carbon\CarbonImmutable;

/**
 * Reads a guest's WhatsApp enquiry (as sent from the public site's WhatsApp buttons) into enquiry form values.
 *
 * Messages are written by guests, so every value is optional, validated, and only ever used to prefill a form
 * that staff review before saving.
 */
class WhatsAppEnquiryReader
{
    /**
     * @return array{listing_slug: ?string, listing_code: ?string, check_in: ?string, check_out: ?string, guests: ?int}
     */
    public function read(Tenant $tenant, string $message): array
    {
        $message = mb_substr($message, 0, 3000);
        $slug = null;
        $pattern = '#/stays/'.preg_quote($tenant->slug, '#').'/([a-z0-9]+(?:-[a-z0-9]+)*)#i';
        if (preg_match($pattern, $message, $matches)) {
            $slug = strtolower($matches[1]);
        }
        $code = preg_match('/^\s*Code:\s*([A-Za-z0-9][A-Za-z0-9-]{0,19})\s*$/mi', $message, $matches) ? strtoupper($matches[1]) : null;
        $checkIn = $checkOut = null;
        if (preg_match('/^\s*Dates:\s*(.+?)\s+[–-]\s+(.+?)(?:\s*\(|\s*$)/mu', $message, $matches)) {
            $checkIn = $this->date($matches[1]);
            $checkOut = $this->date($matches[2]);
        } elseif (preg_match('/^\s*Check-in:\s*(.+?)\s*$/mi', $message, $matches)) {
            $checkIn = $this->date($matches[1]);
        }
        if ($checkIn && $checkOut && $checkOut <= $checkIn) {
            $checkOut = null;
        }
        $guests = preg_match('/^\s*Guests:\s*(\d{1,3})\b/mi', $message, $matches) && (int) $matches[1] > 0 ? (int) $matches[1] : null;

        return ['listing_slug' => $slug, 'listing_code' => $code, 'check_in' => $checkIn, 'check_out' => $checkOut, 'guests' => $guests];
    }

    /** Parse a date such as "Sat 12 Dec 2026" (the format used in our WhatsApp messages) or 2026-12-12. */
    private function date(string $value): ?string
    {
        // Drop a leading weekday ("Sat"): parsing it could move the date if a guest edited the text.
        $value = (string) preg_replace('/^[A-Za-z]{3,9},?\s+/', '', trim($value));
        foreach (['!j M Y', '!j F Y', '!Y-m-d'] as $format) {
            try {
                $date = CarbonImmutable::createFromFormat($format, $value);
            } catch (\Throwable) {
                continue;
            }
            $errors = CarbonImmutable::getLastErrors();
            if ($date && ! ($errors['warning_count'] ?? 0) && $date->year >= 2000 && $date->year <= 2100) {
                return $date->format('Y-m-d');
            }
        }

        return null;
    }
}
