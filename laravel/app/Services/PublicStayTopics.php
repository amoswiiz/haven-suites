<?php

namespace App\Services;

use App\Models\Tenant;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Pages for the kind of stay guests search for in a city, such as "Sea view apartments in Mombasa" or
 * "2-bedroom apartments in Nairobi". A page exists only when enough listings match, so none is thin.
 */
final class PublicStayTopics
{
    /** Fewest matching listings for a topic page to exist. */
    public const MIN_STAYS = 2;

    /** @var array<string, array{label: string, intro: string}> */
    public const TOPICS = [
        'sea-view-apartments' => ['label' => 'Sea view apartments', 'intro' => 'Wake up to the ocean. These apartments have a view of the sea from the living room, bedroom or balcony.'],
        'beachfront-apartments' => ['label' => 'Beachfront apartments', 'intro' => 'Step straight onto the sand. These apartments are on the beach or a short walk from it.'],
        'apartments-with-pool' => ['label' => 'Apartments with a pool', 'intro' => 'Cool off without leaving home. Every apartment here has a swimming pool for guests.'],
        'monthly-rentals' => ['label' => 'Monthly rentals', 'intro' => 'Furnished apartments for stays of a month or more, ready to move into with Wi-Fi, a kitchen and housekeeping options.'],
        'business-stays' => ['label' => 'Apartments for business stays', 'intro' => 'Quiet, well-connected apartments with space to work, for business trips, consultants and relocating staff.'],
        'studio-apartments' => ['label' => 'Studio apartments', 'intro' => 'Compact, fully furnished studios for solo travellers and couples.'],
        '1-bedroom-apartments' => ['label' => '1-bedroom apartments', 'intro' => 'One-bedroom apartments with a separate living area, ideal for couples and solo travellers.'],
        '2-bedroom-apartments' => ['label' => '2-bedroom apartments', 'intro' => 'Two-bedroom apartments for small families and friends travelling together.'],
        '3-bedroom-apartments' => ['label' => '3-bedroom apartments', 'intro' => 'Three-bedroom apartments with room for families and groups.'],
        '4-bedroom-apartments' => ['label' => '4-bedroom homes', 'intro' => 'Large four-bedroom homes and apartments for big families and groups.'],
    ];

    public static function exists(string $slug): bool
    {
        return isset(self::TOPICS[$slug]);
    }

    /** @param  array<string, mixed>  $listing  A published listing from the catalogue. */
    public static function matches(string $slug, array $listing): bool
    {
        $collections = $listing['collections'] ?? [];
        $amenities = $listing['amenities'] ?? [];
        $bedrooms = $listing['bedrooms'] ?? null;

        return match ($slug) {
            'sea-view-apartments' => in_array('sea_view', $collections, true) || in_array('sea_view', $amenities, true),
            'beachfront-apartments' => in_array('beachfront', $collections, true) || in_array('beachfront', $amenities, true),
            'apartments-with-pool' => in_array('pool', $amenities, true),
            'monthly-rentals' => in_array('long_stay', $collections, true) || ! empty($listing['monthly_discount']),
            'business-stays' => in_array('business', $collections, true) || in_array('workspace', $amenities, true),
            'studio-apartments' => $bedrooms === 0,
            default => preg_match('/^(\d+)-bedroom-apartments$/', $slug, $found) === 1 && $bedrooms === (int) $found[1],
        };
    }

    /**
     * Topics with enough listings in one city.
     *
     * @param  Collection<int, array<string, mixed>>  $cityStays
     * @return list<array{slug: string, label: string, count: int}>
     */
    public static function forCity(Collection $cityStays): array
    {
        $topics = [];
        foreach (self::TOPICS as $slug => $topic) {
            $count = $cityStays->filter(fn (array $listing): bool => self::matches($slug, $listing))->count();
            if ($count >= self::MIN_STAYS) {
                $topics[] = ['slug' => $slug, 'label' => $topic['label'], 'count' => $count];
            }
        }

        return $topics;
    }

    public static function url(Tenant $tenant, string $city, string $slug): string
    {
        return route('public-stays.area', ['company' => $tenant->slug, 'city' => Str::slug($city), 'area' => $slug]);
    }
}
