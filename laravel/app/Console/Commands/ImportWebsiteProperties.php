<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\ListingPhotoVariants;
use App\Services\PublicImageEncoder;
use App\Services\PublicListingCatalog;
use App\Services\WebsiteBlogSource;
use App\Services\WebsiteListingSource;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Copies the short-stay properties from the old WordPress website into the new site. Each property becomes a
 * partner apartment (an apartment the company markets but does not manage, so it never touches housekeeping or
 * accounting) with a listing at the same web address, its photos, map point, features and nightly price.
 * Properties for sale and addresses already on the new site are skipped, so the import can run again safely.
 */
#[Signature('haven:import-website-properties {tenant : Company slug} {--publish : Publish each imported listing that has photos} {--dry-run : Show what would be imported without saving} {--only= : Import one old property by its web address} {--actor= : User recorded in the listing history (defaults to the website enquiry contact)}')]
#[Description('Import the old website\'s short-stay properties as partner apartments with listings and photos')]
class ImportWebsiteProperties extends Command
{
    public const OWNER_PLACEHOLDER = 'Owner to confirm (old website)';

    private const MAX_PHOTOS = 30;

    private int $actorId = 0;

    /** Old website labels and the new site's collections. */
    private const COLLECTIONS = ['beachfront homes' => 'beachfront', 'beachfront' => 'beachfront', 'sea view' => 'sea_view',
        'guest favorite' => 'guest_favourite', 'guest favourite' => 'guest_favourite', 'discount' => 'special_offer', 'discounted' => 'special_offer'];

    /** Old website city names that were menu labels rather than cities. */
    private const CITY_NAMES = ['nairobi homes' => 'Nairobi', 'nyali links' => 'Mombasa', 'nyali' => 'Mombasa', 'diani beach' => 'Diani'];

    /** Areas named in titles, for properties the old website gave no city. */
    private const AREA_CITIES = ['kileleshwa' => 'Nairobi', 'kilimani' => 'Nairobi', 'westlands' => 'Nairobi', 'lavington' => 'Nairobi', 'riverside' => 'Nairobi',
        'karen' => 'Nairobi', 'runda' => 'Nairobi', 'parklands' => 'Nairobi', 'nyali' => 'Mombasa', 'bamburi' => 'Mombasa', 'shanzu' => 'Mombasa', 'mtwapa' => 'Mombasa'];

    public function handle(WebsiteListingSource $source, WebsiteBlogSource $media, PublicImageEncoder $encoder): int
    {
        $tenant = Tenant::where('slug', $this->argument('tenant'))->where('active', true)->first();
        $site = $tenant ? DB::table('public_sites')->where('tenant_id', $tenant->id)->first() : null;
        $this->actorId = (int) ($this->option('actor') ?: ($site->enquiry_assignee_id ?? 0));
        if (! $tenant || ! $site || ! DB::table('users')->where('id', $this->actorId)->where('active', true)->exists()) {
            $this->error('Choose an active company with a public website, and an active user with --actor (or set the website enquiry contact).');

            return self::FAILURE;
        }
        config(['database.connections.tenant.database' => $tenant->database_name]);
        DB::purge('tenant');
        $apartments = DB::connection('tenant');
        if (! $apartments->getSchemaBuilder()->hasTable('partner_apartments')) {
            $this->error('This company has no partner apartments table. Nothing was imported.');

            return self::FAILURE;
        }
        try {
            $properties = $source->fetch();
        } catch (\RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
        if (filled($this->option('only'))) {
            $properties = array_values(array_filter($properties, fn (array $property): bool => $property['slug'] === $this->option('only')));
        }

        [$plan, $skipped] = $this->plan($tenant, $properties);
        foreach ($skipped as $reason => $titles) {
            if ($titles !== []) {
                $this->line('Skipped ('.$reason.'): '.count($titles));
                foreach ($titles as $title) {
                    $this->line('  - '.$title);
                }
            }
        }
        $photoCount = array_sum(array_map(fn (array $property): int => min(self::MAX_PHOTOS, count($property['photo_ids'])), $plan));
        if ($this->option('dry-run')) {
            foreach ($plan as $property) {
                $this->line('Ready: '.$property['title'].' → /'.$property['new_slug'].' ('.$property['city'].', '.min(self::MAX_PHOTOS, count($property['photo_ids'])).' photos'
                    .($property['price'] ? ', '.number_format((float) $property['price']).' a night' : ', price on request').')');
            }
            $this->info('Dry run: '.count($plan).' properties with '.$photoCount.' photos would be imported'.($this->option('publish') ? ' and published.' : ' as drafts.'));

            return self::SUCCESS;
        }
        if ($plan === []) {
            $this->info('Nothing new to import.');

            return self::SUCCESS;
        }

        $this->info('Importing '.count($plan).' properties with up to '.$photoCount.' photos. This can take a few minutes.');
        $urls = $media->mediaUrls(array_merge(...array_map(fn (array $property): array => array_slice($property['photo_ids'], 0, self::MAX_PHOTOS), $plan)));
        $published = 0;
        $drafts = 0;
        $failed = [];
        foreach ($plan as $property) {
            try {
                $result = $this->import($tenant, $apartments, $property, $urls, $media, $encoder);
                if ($result['published']) {
                    $published++;
                } else {
                    $drafts++;
                }
                $this->line(($result['published'] ? 'Published: ' : 'Draft: ').$property['title'].' ('.$result['photos'].' photos)');
            } catch (\Throwable $exception) {
                report($exception);
                $failed[] = $property['title'];
                $this->warn('Failed: '.$property['title'].' ('.mb_substr($exception->getMessage(), 0, 300).'). It can be imported by running the command again.');
            }
        }
        PublicListingCatalog::forget($tenant->id);
        $this->info('Imported '.($published + $drafts).' properties: '.$published.' published, '.$drafts.' saved as drafts'.($failed ? ', '.count($failed).' failed' : '').'.');
        $this->line('Each one is a partner apartment named "'.self::OWNER_PLACEHOLDER.'". Add the owner\'s name and phone in Partner apartments so availability requests reach them.');
        $this->line('Guest numbers were estimated as two per bedroom. Check them in each listing.');

        return $failed === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  list<array<string, mixed>>  $properties
     * @return array{0: list<array<string, mixed>>, 1: array<string, list<string>>}
     */
    private function plan(Tenant $tenant, array $properties): array
    {
        $existing = DB::table('public_listings')->where('tenant_id', $tenant->id)->get(['slug', 'listing_code']);
        $slugs = array_fill_keys($existing->pluck('slug')->all(), true);
        $codes = array_fill_keys($existing->pluck('listing_code')->filter()->map(fn (string $code): string => strtolower($code))->all(), true);
        $codeCounts = array_count_values(array_filter(array_map(fn (array $property): string => strtolower((string) $property['unit_code']), $properties)));
        $plan = [];
        $skipped = ['for sale' => [], 'already on the new site' => [], 'no city' => [], 'reserved address' => []];
        foreach ($properties as $property) {
            $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($property['slug'])), '-');
            $city = $this->city($property);
            $reason = match (true) {
                $property['for_sale'] && ! $property['daily'] => 'for sale',
                isset($slugs[$slug]) => 'already on the new site',
                in_array($slug, PublicListingCatalog::RESERVED_SLUGS, true) || $slug === '' => 'reserved address',
                $city === null => 'no city',
                default => null,
            };
            if ($reason !== null) {
                $skipped[$reason][] = $property['title'];

                continue;
            }
            $code = strtoupper((string) $property['unit_code']);
            $codeKey = strtolower($code);
            $useCode = $code !== '' && mb_strlen($code) <= 20 && ($codeCounts[$codeKey] ?? 0) === 1 && ! isset($codes[$codeKey]);
            if ($useCode) {
                $codes[$codeKey] = true;
            }
            $slugs[$slug] = true;
            $plan[] = array_merge($property, ['new_slug' => $slug, 'city' => $city, 'code' => $useCode ? $code : null,
                'price' => $property['daily'] && $property['website_price'] !== '' ? $property['website_price'] : null]);
        }

        return [$plan, $skipped];
    }

    /**
     * @param  array<string, mixed>  $property
     * @param  array<int, string>  $urls
     * @return array{published: bool, photos: int}
     */
    private function import(Tenant $tenant, ConnectionInterface $apartments, array $property, array $urls, WebsiteBlogSource $media, PublicImageEncoder $encoder): array
    {
        $photos = [];
        foreach (array_slice($property['photo_ids'], 0, self::MAX_PHOTOS) as $id) {
            $jpeg = isset($urls[$id]) ? $this->photo($urls[$id], $media, $encoder) : null;
            if ($jpeg !== null) {
                $photos[] = $jpeg;
            }
        }
        $bedrooms = is_numeric($property['website_bedrooms']) ? max(0, min(20, (int) $property['website_bedrooms'])) : null;
        $bathrooms = is_numeric($property['website_bathrooms']) ? max(0, min(20, (int) $property['website_bathrooms'])) : null;
        $area = trim((string) $property['neighbourhood']);
        $description = trim($property['description']) ?: 'A '.($bedrooms ? $bedrooms.'-bedroom ' : '').'stay in '.($area !== '' ? $area.', ' : '').$property['city'].'. Contact our team for details and availability.';
        $publish = (bool) $this->option('publish') && $photos !== [];
        $collections = array_values(array_unique(array_merge(
            array_values(array_filter(array_map(fn (string $label): ?string => self::COLLECTIONS[mb_strtolower(trim($label))] ?? null, $property['labels']))),
            array_values(array_intersect(['beachfront', 'sea_view'], $property['amenities'])),
        )));

        $partnerId = $apartments->table('partner_apartments')->insertGetId([
            'owner_name' => self::OWNER_PLACEHOLDER, 'apartment_name' => mb_substr($property['title'], 0, 150),
            'unit_number' => $property['code'], 'location' => mb_substr($area !== '' ? $area : $property['city'], 0, 150),
            'bedrooms' => $bedrooms ?? 1, 'bathrooms' => $bathrooms, 'status' => 'active',
            'notes' => 'Imported from the old website: '.$property['legacy_url']."\nAdd the owner's name and phone number so availability requests reach them.",
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $stored = [];
        try {
            DB::transaction(function () use ($tenant, $property, $partnerId, $photos, $bedrooms, $bathrooms, $area, $description, $publish, $collections, &$stored): void {
                $listingId = DB::table('public_listings')->insertGetId([
                    'tenant_id' => $tenant->id, 'unit_id' => null, 'partner_apartment_id' => $partnerId, 'slug' => $property['new_slug'],
                    'title' => mb_substr($property['title'], 0, 180), 'description' => mb_substr($description, 0, 15000),
                    'city' => mb_substr($property['city'], 0, 100), 'neighbourhood' => $area !== '' ? mb_substr($area, 0, 150) : null,
                    'public_location' => mb_substr($area !== '' ? $area.', '.$property['city'] : $property['city'], 0, 255),
                    'max_guests' => $bedrooms ? min(16, $bedrooms * 2) : 2, 'min_nights' => 1, 'check_in_time' => '12:00', 'check_out_time' => '10:00',
                    'starting_price' => $property['price'], 'amenities' => json_encode($property['amenities'], JSON_THROW_ON_ERROR),
                    'status' => $publish ? 'published' : 'draft', 'featured' => false, 'version' => 1,
                    'property_type' => $this->propertyType($property['title'], $bedrooms), 'collections' => json_encode($collections, JSON_THROW_ON_ERROR),
                    'listing_code' => $property['code'], 'bedrooms' => $bedrooms, 'bathrooms' => $bathrooms,
                    'latitude' => $property['latitude'], 'longitude' => $property['longitude'],
                    'nearby' => '[]', 'rooms' => '[]', 'seasonal_rates' => '[]', 'created_at' => now(), 'updated_at' => now(),
                ]);
                foreach ($photos as $position => $jpeg) {
                    $path = 'listing-photos/'.$tenant->id.'/'.$listingId.'/'.bin2hex(random_bytes(24)).'.jpg';
                    if (! Storage::disk('local')->put($path, $jpeg)) {
                        throw new \RuntimeException('A photo could not be stored.');
                    }
                    $stored[] = $path;
                    DB::table('public_listing_photos')->insert(['tenant_id' => $tenant->id, 'listing_id' => $listingId, 'storage_key' => $path,
                        'mime_type' => 'image/jpeg', 'caption' => null, 'sort_order' => $position, 'created_at' => now(), 'updated_at' => now()]);
                }
                DB::table('public_listing_audit_events')->insert(['tenant_id' => $tenant->id, 'listing_id' => $listingId, 'actor_id' => $this->actorId,
                    'action' => 'listing_imported', 'before_snapshot' => null,
                    'after_snapshot' => json_encode(['source' => $property['legacy_url'], 'partner_apartment_id' => $partnerId, 'photos' => count($photos)], JSON_THROW_ON_ERROR),
                    'created_at' => now()]);
            });
        } catch (\Throwable $exception) {
            foreach ($stored as $path) {
                Storage::disk('local')->delete($path);
            }
            $apartments->table('partner_apartments')->where('id', $partnerId)->delete();
            throw $exception;
        }
        foreach ($stored as $path) {
            app(ListingPhotoVariants::class)->make($path);
        }

        return ['published' => $publish, 'photos' => count($photos)];
    }

    /** The photo as a JPEG of at most 1,800 pixels, or null when it is missing, elsewhere or unreadable. */
    private function photo(string $url, WebsiteBlogSource $media, PublicImageEncoder $encoder): ?string
    {
        $bytes = $media->image($url);
        if ($bytes === null) {
            return null;
        }
        $temporary = tempnam(sys_get_temp_dir(), 'haven_property_');
        try {
            file_put_contents($temporary, $bytes);

            return $encoder->jpeg(new UploadedFile($temporary, basename((string) parse_url($url, PHP_URL_PATH)), null, null, true), 'photo', 1800);
        } catch (ValidationException) {
            return null;
        } finally {
            @unlink($temporary);
        }
    }

    /**
     * The website's city with menu labels tidied ("Nairobi Homes" → Nairobi), otherwise the town in the title
     * ("Panoramic views 4BR, Naivasha – 010" → Naivasha) or the city of a known area in the title or area.
     *
     * @param  array<string, mixed>  $property
     */
    private function city(array $property): ?string
    {
        $city = trim((string) $property['city']);
        if ($city === '' && preg_match('/,\s*([\pL][\pL .\']{1,40}?)\s*\.?\s*[-\x{2013}\x{2014}]\s*[a-z]{0,5}\s*\d/iu', $property['title'], $match)) {
            $city = trim($match[1]);
        }
        if ($city === '') {
            $words = mb_strtolower($property['title'].' '.$property['neighbourhood']);
            foreach (self::AREA_CITIES as $area => $areaCity) {
                if (preg_match('/\b'.$area.'\b/u', $words)) {
                    return $areaCity;
                }
            }

            return null;
        }

        return self::CITY_NAMES[mb_strtolower($city)] ?? $city;
    }

    private function propertyType(string $title, ?int $bedrooms): ?string
    {
        $title = mb_strtolower($title);
        foreach (['villa', 'cottage', 'townhouse', 'maisonette', 'penthouse', 'studio'] as $type) {
            if (str_contains($title, $type)) {
                return $type;
            }
        }

        return match (true) {
            $bedrooms === 0 => 'studio',
            str_contains($title, 'apartment') || str_contains($title, 'apt') => 'apartment',
            default => null,
        };
    }
}
