<?php

namespace App\Services;

use App\Models\Tenant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PublicListingCatalog
{
    public const AMENITIES = [
        'wifi' => 'Wi-Fi', 'air_conditioning' => 'Air conditioning', 'pool' => 'Swimming pool',
        'beachfront' => 'Beachfront', 'sea_view' => 'Sea view', 'parking' => 'Parking',
        'kitchen' => 'Kitchen', 'balcony' => 'Balcony', 'gym' => 'Gym', 'washer' => 'Washing machine',
        'security' => 'Security', 'elevator' => 'Lift', 'workspace' => 'Workspace', 'pets_allowed' => 'Pets allowed',
    ];

    public function settings(Tenant $tenant): ?object
    {
        return DB::table('public_sites')->where('tenant_id', $tenant->id)->first();
    }

    public function site(Tenant $tenant): ?object
    {
        if (! $tenant->active || ($tenant->modules['properties'] ?? true) === false) {
            return null;
        }
        $site = $this->settings($tenant);

        return $site?->enabled ? $site : null;
    }

    public function assignees(Tenant $tenant): Collection
    {
        return $tenant->users()->where('active', true)->get()->filter(function ($user): bool {
            foreach (json_decode($user->pivot->roles, true) ?? [] as $role) {
                foreach (config('haven.roles.'.$role, []) as $permission) {
                    if (Str::is($permission, 'sales.manage')) {
                        return true;
                    }
                }
            }

            return false;
        })->values();
    }

    public function units(Tenant $tenant): Collection
    {
        return $this->loadUnits($tenant);
    }

    private function loadUnits(Tenant $tenant, ?int $unitId = null): Collection
    {
        $config = config('database.connections.tenant');
        $config['database'] = $tenant->database_name;
        $db = DB::build($config);
        try {
            return $db->table('units as u')->leftJoin('properties as p', 'p.id', '=', 'u.property_id')
                ->when($unitId !== null, fn ($query) => $query->where('u.id', $unitId))
                ->orderBy('p.name')->orderBy('u.unit_number')
                ->get(['u.id', 'u.unit_number', 'u.bedrooms', 'u.bathrooms', 'u.status', 'u.nightly_rate', 'p.name as property_name']);
        } finally {
            $db->disconnect();
        }
    }

    public function published(Tenant $tenant): Collection
    {
        if ($this->site($tenant) === null) {
            return collect();
        }
        $units = $this->units($tenant)->where('status', 'active')->keyBy('id');
        $listings = DB::table('public_listings')->where('tenant_id', $tenant->id)->where('status', 'published')
            ->orderByDesc('featured')->orderBy('title')->get();
        $photos = DB::table('public_listing_photos')->where('tenant_id', $tenant->id)
            ->whereIn('listing_id', $listings->pluck('id'))->orderBy('sort_order')->orderBy('id')->get()->groupBy('listing_id');

        return $listings->filter(fn (object $listing): bool => $units->has($listing->unit_id) && $photos->has($listing->id))
            ->map(fn (object $listing): array => $this->project($tenant, $listing, $units->get($listing->unit_id), $photos->get($listing->id)))->values();
    }

    public function findPublished(Tenant $tenant, string $slug): array
    {
        [$listing, $unit] = $this->publishedEntry($tenant, $slug);
        $photos = DB::table('public_listing_photos')->where('tenant_id', $tenant->id)->where('listing_id', $listing->id)
            ->orderBy('sort_order')->orderBy('id')->get();
        abort_if($photos->isEmpty(), 404);

        return $this->project($tenant, $listing, $unit, $photos);
    }

    public function preview(Tenant $tenant, int $id): array
    {
        $listing = DB::table('public_listings')->where('tenant_id', $tenant->id)->where('id', $id)->firstOrFail();
        $unit = $this->loadUnits($tenant, (int) $listing->unit_id)->first();
        abort_unless($unit, 404);
        $photos = DB::table('public_listing_photos')->where('tenant_id', $tenant->id)->where('listing_id', $id)
            ->orderBy('sort_order')->orderBy('id')->get();

        return $this->project($tenant, $listing, $unit, $photos, true);
    }

    public function image(Tenant $tenant, string $slug, int $photo): array
    {
        [$listing] = $this->publishedEntry($tenant, $slug);

        return $this->imageForListing($tenant, (int) $listing->id, $photo);
    }

    private function publishedEntry(Tenant $tenant, string $slug): array
    {
        abort_unless($this->site($tenant), 404);
        $listing = DB::table('public_listings')->where('tenant_id', $tenant->id)->where('status', 'published')->where('slug', $slug)->firstOrFail();
        $unit = $this->loadUnits($tenant, (int) $listing->unit_id)->first();
        abort_unless($unit && $unit->status === 'active', 404);

        return [$listing, $unit];
    }

    public function imageForListing(Tenant $tenant, int $listingId, int $photo): array
    {
        $image = DB::table('public_listing_photos')->where('tenant_id', $tenant->id)->where('listing_id', $listingId)->where('id', $photo)->firstOrFail();
        $pattern = '#^listing-photos/'.(int) $tenant->id.'/'.(int) $listingId.'/[a-f0-9]{48}\.(?:jpg|png)$#D';
        abort_unless(preg_match($pattern, $image->storage_key) && in_array($image->mime_type, ['image/jpeg', 'image/png'], true), 404);
        abort_unless(Storage::disk('local')->exists($image->storage_key), 404);

        return ['path' => Storage::disk('local')->path($image->storage_key), 'mime_type' => $image->mime_type];
    }

    private function project(Tenant $tenant, object $listing, object $unit, Collection $photos, bool $preview = false): array
    {
        $amenities = array_values(array_intersect(json_decode($listing->amenities ?? '[]', true) ?? [], array_keys(self::AMENITIES)));

        return [
            'id' => (int) $listing->id, 'unit_id' => (int) $listing->unit_id,
            'slug' => $listing->slug, 'title' => $listing->title, 'description' => $listing->description,
            'city' => $listing->city, 'neighbourhood' => $listing->neighbourhood, 'public_location' => $listing->public_location,
            'bedrooms' => (int) $unit->bedrooms, 'bathrooms' => (int) $unit->bathrooms,
            'max_guests' => (int) $listing->max_guests, 'min_nights' => (int) $listing->min_nights,
            'check_in_time' => $listing->check_in_time, 'check_out_time' => $listing->check_out_time,
            'starting_price' => $listing->starting_price ?? $unit->nightly_rate, 'currency' => $tenant->currency,
            'amenities' => $amenities, 'amenity_labels' => array_map(fn (string $key): string => self::AMENITIES[$key], $amenities),
            'house_rules' => $listing->house_rules, 'featured' => (bool) $listing->featured,
            'updated_at' => $listing->updated_at,
            'photos' => $photos->map(fn (object $photo): array => [
                'id' => (int) $photo->id, 'caption' => $photo->caption ?: $listing->title,
                'url' => $preview
                    ? route('public-listings.photo', ['tenant' => $tenant->slug, 'id' => $listing->id, 'photo' => $photo->id])
                    : route('public-stays.photo', ['company' => $tenant->slug, 'slug' => $listing->slug, 'photo' => $photo->id]),
            ])->all(),
        ];
    }
}
