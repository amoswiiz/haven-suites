<?php

namespace App\Services;

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Area pages such as /in/nairobi/kilimani: each city and neighbourhood with published stays gets a page, with an
 * optional introduction and highlights written by staff.
 */
class PublicAreaGuides
{
    /**
     * Find the city, and optionally the neighbourhood, that a URL refers to.
     *
     * @param  list<array{city: string, count: int, areas: list<array{name: string, count: int}>}>  $destinations
     * @return array{city: string, area: ?string, count: int, areas: list<array{name: string, count: int}>}|null
     */
    public function resolve(array $destinations, string $citySlug, ?string $areaSlug = null): ?array
    {
        foreach ($destinations as $destination) {
            if (Str::slug($destination['city']) !== $citySlug) {
                continue;
            }
            if ($areaSlug === null) {
                return ['city' => $destination['city'], 'area' => null, 'count' => $destination['count'], 'areas' => $destination['areas']];
            }
            foreach ($destination['areas'] as $area) {
                if (Str::slug($area['name']) === $areaSlug) {
                    return ['city' => $destination['city'], 'area' => $area['name'], 'count' => $area['count'], 'areas' => $destination['areas']];
                }
            }
        }

        return null;
    }

    public function url(Tenant $tenant, string $city, ?string $area = null): string
    {
        $parameters = ['company' => $tenant->slug, 'city' => Str::slug($city)];

        return $area !== null && Str::slug($area) !== ''
            ? route('public-stays.area', $parameters + ['area' => Str::slug($area)])
            : route('public-stays.city', $parameters);
    }

    /** @return array{intro: list<string>, highlights: list<string>, custom: bool} */
    public function content(Tenant $tenant, string $city, ?string $area, int $count): array
    {
        $guide = DB::table('public_area_guides')->where('tenant_id', $tenant->id)->where('city', $city)->where('area', $area ?? '')->first();
        $place = $area ? $area.', '.$city : $city;
        $intro = trim((string) ($guide->intro ?? ''));

        return [
            'intro' => array_values(array_filter(array_map('trim', preg_split('/\R{2,}/u', $intro !== '' ? $intro
                : 'Explore '.$count.' serviced '.($count === 1 ? 'apartment' : 'apartments').' and short-stay homes in '.$place.', arranged by '.$tenant->name.'. Compare photos, amenities and starting prices, then send an enquiry for your dates. Our team confirms availability and the final price.') ?: []))),
            'highlights' => array_values(array_filter(array_map('trim', preg_split('/\R/u', (string) ($guide->highlights ?? '')) ?: []))),
            'custom' => $intro !== '',
        ];
    }

    /** Saved guides keyed by "city|area" for the staff editor. */
    public function all(Tenant $tenant): array
    {
        return DB::table('public_area_guides')->where('tenant_id', $tenant->id)->get()
            ->keyBy(fn (object $guide): string => $guide->city.'|'.$guide->area)->all();
    }

    public function save(Tenant $tenant, string $city, ?string $area, ?string $intro, ?string $highlights): void
    {
        $intro = trim((string) $intro) ?: null;
        $highlights = trim((string) $highlights) ?: null;
        $key = ['tenant_id' => $tenant->id, 'city' => $city, 'area' => $area ?? ''];
        if ($intro === null && $highlights === null) {
            DB::table('public_area_guides')->where($key)->delete();

            return;
        }
        DB::table('public_area_guides')->updateOrInsert($key, ['intro' => $intro, 'highlights' => $highlights, 'updated_at' => now(), 'created_at' => now()]);
    }
}
