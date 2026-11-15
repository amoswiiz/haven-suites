<?php

namespace App\Services;

use Illuminate\Http\Request;

/**
 * Which parts of the new homepage are switched on. Each step can be turned on or off on its own in Website settings;
 * "?layout=new" previews every step and "?layout=classic" shows the original page, without changing the setting.
 * Only presentation changes: search fields, addresses and the enquiry flow are the same in both layouts.
 */
class HomepageLayout
{
    /** @var array<string, string> */
    public const STEPS = [
        'search' => 'Search-first header, category bar and filter drawer',
        'cards' => 'Simpler listing cards with estimated totals for chosen dates',
        'explore' => 'Map view and saved stays (♡)',
    ];

    /** @return array{search: bool, cards: bool, explore: bool} */
    public static function for(?object $site, ?Request $request = null): array
    {
        $preview = $request?->query('layout');
        if ($preview === 'new' || $preview === 'classic') {
            $steps = [];
            foreach (array_keys(self::STEPS) as $step) {
                $steps[$step] = $preview === 'new';
            }

            return $steps;
        }

        return self::saved($site);
    }

    /** @return array{search: bool, cards: bool, explore: bool} */
    public static function saved(?object $site): array
    {
        $saved = json_decode((string) ($site->homepage_layout ?? ''), true);

        return self::encodeSteps(is_array($saved) ? $saved : []);
    }

    /** @param  array<string, mixed>  $input */
    public static function encode(array $input): string
    {
        return json_encode(self::encodeSteps($input), JSON_THROW_ON_ERROR);
    }

    /** @param  array<string, mixed>  $input @return array{search: bool, cards: bool, explore: bool} */
    private static function encodeSteps(array $input): array
    {
        $steps = [];
        foreach (array_keys(self::STEPS) as $step) {
            $steps[$step] = filter_var($input[$step] ?? false, FILTER_VALIDATE_BOOL);
        }

        return $steps;
    }
}
