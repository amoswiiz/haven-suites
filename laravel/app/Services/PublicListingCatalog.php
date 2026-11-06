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

    public function apartments(Tenant $tenant): Collection
    {
        return $this->units($tenant)->map(fn (object $unit): object => $this->managedApartment($unit))
            ->concat($this->loadPartners($tenant))->sortBy(fn (object $apartment): string => $apartment->property_name.' '.$apartment->unit_number)->values();
    }

    private function managedApartment(object $unit): object
    {
        return (object) ((array) $unit + [
            'reference' => 'unit:'.$unit->id, 'kind' => 'managed',
            'unit_id' => (int) $unit->id, 'partner_apartment_id' => null,
        ]);
    }

    private function loadPartners(Tenant $tenant, ?int $partnerId = null): Collection
    {
        $config = config('database.connections.tenant');
        $config['database'] = $tenant->database_name;
        $db = DB::build($config);
        try {
            if (! $db->getSchemaBuilder()->hasTable('partner_apartments')) {
                return collect();
            }

            return $db->table('partner_apartments')->when($partnerId !== null, fn ($query) => $query->where('id', $partnerId))
                ->orderBy('apartment_name')->orderBy('unit_number')
                ->get(['id', 'apartment_name', 'unit_number', 'bedrooms', 'bathrooms', 'status'])
                ->map(fn (object $apartment): object => (object) [
                    'id' => (int) $apartment->id, 'reference' => 'partner:'.$apartment->id, 'kind' => 'partner',
                    'unit_id' => null, 'partner_apartment_id' => (int) $apartment->id,
                    'property_name' => $apartment->apartment_name, 'unit_number' => $apartment->unit_number,
                    'bedrooms' => $apartment->bedrooms, 'bathrooms' => $apartment->bathrooms,
                    'status' => $apartment->status, 'nightly_rate' => null,
                ]);
        } finally {
            $db->disconnect();
        }
    }

    private function apartmentForListing(Tenant $tenant, object $listing): ?object
    {
        if ($listing->unit_id !== null && ($listing->partner_apartment_id ?? null) === null) {
            $unit = $this->loadUnits($tenant, (int) $listing->unit_id)->first();

            return $unit ? $this->managedApartment($unit) : null;
        }
        if ($listing->unit_id === null && ($listing->partner_apartment_id ?? null) !== null) {
            return $this->loadPartners($tenant, (int) $listing->partner_apartment_id)->first();
        }

        return null;
    }

    private function complete(object $listing): bool
    {
        return $listing->max_guests !== null && (int) $listing->max_guests > 0
            && $listing->min_nights !== null && (int) $listing->min_nights > 0
            && trim($listing->title) !== '' && trim($listing->description) !== '' && trim($listing->city) !== '';
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
        $apartments = $this->apartments($tenant)->where('status', 'active')->keyBy('reference');
        $listings = DB::table('public_listings')->where('tenant_id', $tenant->id)->where('status', 'published')
            ->orderByDesc('featured')->orderBy('title')->get();
        $photos = DB::table('public_listing_photos')->where('tenant_id', $tenant->id)
            ->whereIn('listing_id', $listings->pluck('id'))->orderBy('sort_order')->orderBy('id')->get()->groupBy('listing_id');

        return $listings->filter(fn (object $listing): bool => $this->complete($listing)
            && (($listing->unit_id === null) !== (($listing->partner_apartment_id ?? null) === null))
            && $apartments->has($this->reference($listing)) && $photos->has($listing->id))
            ->map(fn (object $listing): array => $this->project($tenant, $listing, $apartments->get($this->reference($listing)), $photos->get($listing->id)))->values();
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
        $unit = $this->apartmentForListing($tenant, $listing);
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
        $unit = $this->apartmentForListing($tenant, $listing);
        abort_unless($this->complete($listing) && $unit && $unit->status === 'active', 404);

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

    private function reference(object $listing): string
    {
        return $listing->unit_id !== null ? 'unit:'.$listing->unit_id : 'partner:'.($listing->partner_apartment_id ?? '');
    }

    private function project(Tenant $tenant, object $listing, object $unit, Collection $photos, bool $preview = false): array
    {
        $amenities = array_values(array_intersect(json_decode($listing->amenities ?? '[]', true) ?? [], array_keys(self::AMENITIES)));

        return [
            'id' => (int) $listing->id, 'unit_id' => $listing->unit_id !== null ? (int) $listing->unit_id : null,
            'partner_apartment_id' => ($listing->partner_apartment_id ?? null) !== null ? (int) $listing->partner_apartment_id : null,
            'inventory_kind' => $unit->kind,
            'slug' => $listing->slug, 'title' => $listing->title, 'description' => $listing->description,
            'city' => $listing->city, 'neighbourhood' => $listing->neighbourhood, 'public_location' => $listing->public_location,
            'bedrooms' => $unit->bedrooms !== null ? (int) $unit->bedrooms : null, 'bathrooms' => $unit->bathrooms !== null ? (int) $unit->bathrooms : null,
            'max_guests' => $listing->max_guests !== null ? (int) $listing->max_guests : null, 'min_nights' => $listing->min_nights !== null ? (int) $listing->min_nights : null,
            'check_in_time' => $listing->check_in_time, 'check_out_time' => $listing->check_out_time,
            'starting_price' => $listing->starting_price ?? $unit->nightly_rate, 'currency' => $tenant->currency,
            'amenities' => $amenities, 'amenity_labels' => array_map(fn (string $key): string => self::AMENITIES[$key], $amenities),
            'house_rules' => $listing->house_rules, 'featured' => (bool) $listing->featured,
            'videos' => app(YouTubeListingVideos::class)->project($listing->youtube_videos ?? null),
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
