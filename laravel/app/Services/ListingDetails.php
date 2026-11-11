<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * Cleans and presents the guest-facing details of a listing: approximate location and nearby places, rooms and
 * beds, property facts, weekend and seasonal prices, 360° tour links and the listing quality checklist.
 */
class ListingDetails
{
    /** Room labels for photos, in the order the "browse every photo" view groups them. @var array<string, string> */
    public const ROOM_TAGS = [
        'living' => 'Living room', 'bedroom' => 'Bedrooms', 'bathroom' => 'Bathrooms', 'kitchen' => 'Kitchen & dining',
        'balcony' => 'Balcony & views', 'amenities' => 'Pool, gym & amenities', 'exterior' => 'Building & outside', 'other' => 'Other',
    ];

    /** @var array<string, string> */
    public const TRAVEL_MODES = ['walk' => 'walk', 'drive' => 'drive'];

    public const MAX_ROOMS = 10;

    public const MAX_NEARBY = 8;

    public const MAX_SEASONS = 8;

    /** Read "-4.0435, 39.7123" or a Google Maps / OpenStreetMap link. @return array{0: float, 1: float}|null */
    public static function parseLocation(?string $input): ?array
    {
        $text = trim((string) $input);
        if ($text === '') {
            return null;
        }
        $patterns = [
            '/!3d(-?\d{1,2}(?:\.\d+)?)!4d(-?\d{1,3}(?:\.\d+)?)/',
            '/@(-?\d{1,2}(?:\.\d+)?),\s*(-?\d{1,3}(?:\.\d+)?)/',
            '/[?&](?:q|query|ll|center|destination)=(-?\d{1,2}(?:\.\d+)?)(?:,|%2C)\s*(-?\d{1,3}(?:\.\d+)?)/i',
            '/[?&]mlat=(-?\d{1,2}(?:\.\d+)?)&mlon=(-?\d{1,3}(?:\.\d+)?)/',
            '/#map=\d+\/(-?\d{1,2}(?:\.\d+)?)\/(-?\d{1,3}(?:\.\d+)?)/',
            '/^(-?\d{1,2}(?:\.\d+)?)\s*[,\s]\s*(-?\d{1,3}(?:\.\d+)?)$/',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $matches)) {
                $latitude = (float) $matches[1];
                $longitude = (float) $matches[2];
                if ($latitude >= -90 && $latitude <= 90 && $longitude >= -180 && $longitude <= 180 && ($latitude != 0.0 || $longitude != 0.0)) {
                    return [round($latitude, 6), round($longitude, 6)];
                }
            }
        }

        return null;
    }

    /**
     * Map of the surrounding area only: the centre is rounded to about 500 m and the view covers roughly 2 km,
     * so the building itself cannot be located from the public page.
     *
     * @return array{embed_url: string, link_url: string}|null
     */
    public static function areaMap(mixed $latitude, mixed $longitude): ?array
    {
        if (! is_numeric($latitude) || ! is_numeric($longitude)) {
            return null;
        }
        $lat = round((float) $latitude * 200) / 200;
        $lng = round((float) $longitude * 200) / 200;
        $box = implode(',', array_map(fn (float $value): string => number_format($value, 4, '.', ''), [$lng - 0.012, $lat - 0.009, $lng + 0.012, $lat + 0.009]));

        return [
            'embed_url' => 'https://www.openstreetmap.org/export/embed.html?bbox='.rawurlencode($box).'&layer=mapnik',
            'link_url' => 'https://www.openstreetmap.org/#map=15/'.number_format($lat, 3, '.', '').'/'.number_format($lng, 3, '.', ''),
        ];
    }

    /** @return list<array{name: string, minutes: int, mode: string}> */
    public static function nearby(mixed $rows): array
    {
        return collect(is_array($rows) ? $rows : [])->map(function ($row): ?array {
            $name = trim((string) ($row['name'] ?? ''));
            $minutes = (int) ($row['minutes'] ?? 0);
            if ($name === '' || $minutes < 1) {
                return null;
            }

            return ['name' => mb_substr($name, 0, 60), 'minutes' => min($minutes, 600),
                'mode' => isset(self::TRAVEL_MODES[$row['mode'] ?? '']) ? $row['mode'] : 'drive'];
        })->filter()->take(self::MAX_NEARBY)->values()->all();
    }

    /** @param list<int> $photoIds @return list<array{name: string, beds: string, photo_id: ?int, ensuite: bool}> */
    public static function rooms(mixed $rows, array $photoIds = []): array
    {
        return collect(is_array($rows) ? $rows : [])->map(function ($row) use ($photoIds): ?array {
            $name = trim((string) ($row['name'] ?? ''));
            $beds = trim((string) ($row['beds'] ?? ''));
            if ($name === '' && $beds === '') {
                return null;
            }
            $photo = (int) ($row['photo_id'] ?? 0);

            return ['name' => mb_substr($name !== '' ? $name : 'Room', 0, 40), 'beds' => mb_substr($beds, 0, 80),
                'photo_id' => in_array($photo, $photoIds, true) ? $photo : null, 'ensuite' => filter_var($row['ensuite'] ?? false, FILTER_VALIDATE_BOOL)];
        })->filter()->take(self::MAX_ROOMS)->values()->all();
    }

    /** @return list<array{name: string, from: string, to: string, price: float}> */
    public static function seasons(mixed $rows): array
    {
        return collect(is_array($rows) ? $rows : [])->map(function ($row): ?array {
            $from = self::date($row['from'] ?? null);
            $to = self::date($row['to'] ?? null);
            $price = is_numeric($row['price'] ?? null) ? round((float) $row['price'], 2) : 0.0;
            if (! $from || ! $to || $to < $from || $price <= 0) {
                return null;
            }

            return ['name' => mb_substr(trim((string) ($row['name'] ?? '')) ?: 'Seasonal price', 0, 40), 'from' => $from, 'to' => $to, 'price' => $price];
        })->filter()->sortBy('from')->take(self::MAX_SEASONS)->values()->all();
    }

    /** Embed address for a Kuula or Matterport tour link, or null when the link is not one of those. */
    public static function tourEmbed(?string $url): ?string
    {
        $url = trim((string) $url);
        $parts = parse_url($url);
        if (! $parts || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['port'])) {
            return null;
        }
        $host = strtolower($parts['host'] ?? '');
        $path = $parts['path'] ?? '';
        parse_str($parts['query'] ?? '', $query);
        if (in_array($host, ['kuula.co', 'www.kuula.co'], true) && preg_match('#^/share/(?:collection/)?([A-Za-z0-9]{3,20})/?$#', $path, $matches)) {
            return 'https://kuula.co/share/'.(str_contains($path, '/collection/') ? 'collection/' : '').$matches[1].'?fs=1&vr=1&thumbs=1&info=0&logo=0';
        }
        if ($host === 'my.matterport.com' && $path === '/show/' && is_string($query['m'] ?? null) && preg_match('/^[A-Za-z0-9]{8,20}$/D', $query['m'])) {
            return 'https://my.matterport.com/show/?m='.$query['m'].'&play=1&qs=1';
        }

        return null;
    }

    /**
     * Price per night for each night of a stay: a seasonal price wins, then the weekend price on Friday and Saturday
     * nights, then the starting price.
     *
     * @param  array{starting_price: mixed, weekend_price?: mixed, seasonal_rates?: list<array{from: string, to: string, price: float}>}  $listing
     */
    public static function nightlyPrice(array $listing, CarbonImmutable $night): ?float
    {
        $day = $night->toDateString();
        foreach ($listing['seasonal_rates'] ?? [] as $season) {
            if ($day >= $season['from'] && $day <= $season['to']) {
                return (float) $season['price'];
            }
        }
        if (! empty($listing['weekend_price']) && in_array($night->dayOfWeekIso, [5, 6], true)) {
            return (float) $listing['weekend_price'];
        }

        return (float) ($listing['starting_price'] ?? 0) > 0 ? (float) $listing['starting_price'] : null;
    }

    /** "Photos checked" date for guests, hidden after 18 months. */
    public static function photosChecked(mixed $date): ?string
    {
        if (! $date) {
            return null;
        }
        $checked = CarbonImmutable::parse((string) $date);

        return $checked->greaterThan(now()->subMonths(18)) ? $checked->format('F Y') : null;
    }

    /**
     * Suggestions that make a listing more likely to get enquiries. Each item says what to fix.
     *
     * @param  Collection<int, object>  $photos  Rows from public_listing_photos.
     * @return list<array{label: string, done: bool, tip: string}>
     */
    public static function checklist(?object $listing, Collection $photos): array
    {
        $captioned = $photos->filter(fn (object $photo): bool => trim((string) $photo->caption) !== '')->count();
        $rooms = $photos->pluck('room')->filter()->unique();
        $cover = $photos->sortBy([['sort_order', 'asc'], ['id', 'asc']])->first();
        $coverLandscape = null;
        if ($cover && is_string($cover->storage_key ?? null)) {
            $size = @getimagesize(Storage::disk('local')->path($cover->storage_key));
            $coverLandscape = $size ? ($size[0] >= 1200 && $size[0] > $size[1]) : null;
        }
        $amenities = json_decode($listing->amenities ?? '[]', true) ?: [];

        return [
            ['label' => 'At least 8 photos', 'done' => $photos->count() >= 8, 'tip' => 'Guests trust listings with many photos. You have '.$photos->count().'.'],
            ['label' => 'Bedroom, bathroom and kitchen photos', 'done' => $rooms->intersect(['bedroom', 'bathroom', 'kitchen'])->count() === 3, 'tip' => 'Tag each photo with its room so guests can find them.'],
            ['label' => 'Every photo described', 'done' => $photos->isNotEmpty() && $captioned === $photos->count(), 'tip' => $captioned.' of '.$photos->count().' photos have a description.'],
            ['label' => 'Wide, sharp cover photo', 'done' => $coverLandscape === true, 'tip' => 'Use a landscape cover at least 1,200 pixels wide.'],
            ['label' => 'Description of 150+ characters', 'done' => mb_strlen(trim((string) ($listing->description ?? ''))) >= 150, 'tip' => 'Describe the space, the area and who the stay suits.'],
            ['label' => 'At least 5 amenities', 'done' => count($amenities) >= 5, 'tip' => 'Tick Wi-Fi, backup power, water, parking and security where they apply.'],
            ['label' => 'Map area and nearby places', 'done' => ($listing->latitude ?? null) !== null && count(json_decode($listing->nearby ?? '[]', true) ?: []) >= 2, 'tip' => 'Add the map location and two or more nearby places.'],
            ['label' => 'Rooms and beds listed', 'done' => count(json_decode($listing->rooms ?? '[]', true) ?: []) >= 1, 'tip' => 'Add each bedroom with its beds under "Where guests sleep".'],
            ['label' => 'Photos checked in the last year', 'done' => ($listing->photos_verified_at ?? null) && CarbonImmutable::parse($listing->photos_verified_at)->greaterThan(now()->subYear()), 'tip' => 'Confirm the photos still show the apartment as it is.'],
        ];
    }

    private static function date(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) {
            return null;
        }
        try {
            return CarbonImmutable::createFromFormat('!Y-m-d', $value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
