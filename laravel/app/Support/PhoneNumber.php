<?php

namespace App\Support;

/**
 * Normalises phone numbers to international (E.164) form, with Kenya as the default country.
 */
class PhoneNumber
{
    public const DEFAULT_PREFIX = '+254';

    /**
     * Return the number as +<country><number>, or null when it is empty or not a usable number.
     *
     * Kenyan numbers must be +254 followed by nine digits starting with 7 or 1.
     */
    public static function normalize(?string $value): ?string
    {
        $phone = self::compact($value);
        if (self::isBlank($value)) {
            return null;
        }
        if (preg_match('/^00([1-9][0-9]*)$/D', $phone, $matches)) {
            $phone = '+'.$matches[1];
        }
        if (preg_match('/^0([17][0-9]{8})$/D', $phone, $matches)
            || preg_match('/^\+?2540?([17][0-9]{8})$/D', $phone, $matches)) {
            return self::DEFAULT_PREFIX.$matches[1];
        }
        if (str_starts_with($phone, '+254') || str_starts_with($phone, '254')) {
            return null;
        }

        return preg_match('/^\+[1-9][0-9]{7,14}$/D', $phone) ? $phone : null;
    }

    /**
     * True when nothing beyond an optional country prefix was entered, such as an untouched "+254" field.
     */
    public static function isBlank(?string $value): bool
    {
        return in_array(self::compact($value), ['', '+', '+254', '254'], true);
    }

    private static function compact(?string $value): string
    {
        return preg_replace('/[\s().-]+/u', '', trim($value ?? '')) ?? '';
    }
}
