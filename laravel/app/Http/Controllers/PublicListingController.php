<?php

namespace App\Http\Controllers;

use App\Services\ListingDetails;
use App\Services\ListingPhotoVariants;
use App\Services\PublicAboutPage;
use App\Services\PublicGuestInfo;
use App\Services\PublicListingCatalog;
use App\Services\PublicWebsiteLinks;
use App\Services\YouTubeListingVideos;
use App\Support\PhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PublicListingController extends Controller
{
    private function authorizeAccess(): void
    {
        abort_unless(auth()->user()?->active && auth()->user()->can('properties.manage'), 403);
    }

    public function index(PublicListingCatalog $catalog): View
    {
        $this->authorizeAccess();
        $tenant = app('currentTenant');
        $site = $catalog->settings($tenant);
        $showArchived = request()->boolean('archived');
        $archivedCount = DB::table('public_listings')->where('tenant_id', $tenant->id)->where('status', 'archived')->count();
        $listings = DB::table('public_listings')->where('tenant_id', $tenant->id)
            ->when($showArchived, fn ($query) => $query->where('status', 'archived'), fn ($query) => $query->where('status', '!=', 'archived'))
            ->orderBy('title')->get();
        $publicLinks = $catalog->published($tenant)->mapWithKeys(fn (array $listing): array => [$listing['id'] => app(PublicWebsiteLinks::class)->forListing($tenant, $listing, $site)]);

        return view('public_listings.index', [
            'listings' => $listings, 'site' => $site, 'publicLinks' => $publicLinks, 'showArchived' => $showArchived, 'archivedCount' => $archivedCount,
            'photoCounts' => DB::table('public_listing_photos')->where('tenant_id', $tenant->id)->selectRaw('listing_id, COUNT(*) as photos')->groupBy('listing_id')->pluck('photos', 'listing_id'),
            'apartments' => $catalog->apartments($tenant)->keyBy('reference'),
        ]);
    }

    public function settings(Request $request, PublicListingCatalog $catalog): View
    {
        $this->authorizeAccess();
        $tenant = app('currentTenant');
        $site = $catalog->settings($tenant);

        return view('public_listings.settings', ['site' => $site, 'members' => $catalog->assignees($tenant), 'aboutDefaults' => app(PublicAboutPage::class), 'guestInfo' => app(PublicGuestInfo::class),
            'navigation' => app(PublicWebsiteLinks::class)->navigationForForm($site), 'websiteLinks' => app(PublicWebsiteLinks::class)->forSite($tenant, $site)]);
    }

    public function create(PublicListingCatalog $catalog): View
    {
        $this->authorizeAccess();

        $preset = request()->query('apartment');

        return view('public_listings.form', ['listing' => null, 'apartments' => $catalog->apartments(app('currentTenant')), 'photos' => collect(), 'videos' => [], 'amenities' => PublicListingCatalog::AMENITIES,
            'presetReference' => is_string($preset) && preg_match('/^(unit|partner):\d+$/D', $preset) ? $preset : null, ...$this->placeSuggestions()]);
    }

    /** @return array{knownCities: list<string>, knownAreas: list<string>} */
    private function placeSuggestions(): array
    {
        $places = DB::table('public_listings')->where('tenant_id', app('currentTenant')->id)->get(['city', 'neighbourhood']);

        return ['knownCities' => $places->pluck('city')->filter()->unique()->sort()->values()->all(),
            'knownAreas' => $places->pluck('neighbourhood')->filter()->unique()->sort()->values()->all()];
    }

    public function edit(string $tenant, int $id, PublicListingCatalog $catalog): View
    {
        $this->authorizeAccess();
        $listing = $this->listing($id);

        return view('public_listings.form', [
            'listing' => $listing, 'apartments' => $catalog->apartments(app('currentTenant')),
            'photos' => DB::table('public_listing_photos')->where('tenant_id', app('currentTenant')->id)->where('listing_id', $id)->orderBy('sort_order')->orderBy('id')->get(),
            'amenities' => PublicListingCatalog::AMENITIES,
            'videos' => app(YouTubeListingVideos::class)->forForm($listing->youtube_videos ?? null),
            'owner' => $catalog->ownerContact(app('currentTenant'), $listing->unit_id !== null ? (int) $listing->unit_id : null,
                ($listing->partner_apartment_id ?? null) !== null ? (int) $listing->partner_apartment_id : null),
            ...$this->placeSuggestions(),
        ]);
    }

    public function store(Request $request, PublicListingCatalog $catalog): JsonResponse|RedirectResponse
    {
        return $this->save($request, $catalog);
    }

    public function update(string $tenant, int $id, Request $request, PublicListingCatalog $catalog): JsonResponse|RedirectResponse
    {
        return $this->save($request, $catalog, $id);
    }

    public function prepare(Request $request): RedirectResponse
    {
        $this->authorizeAccess();
        $tenant = app('currentTenant');
        $exitCode = Artisan::call('haven:prepare-public-catalogue', ['tenant' => $tenant->slug, '--actor' => (string) auth()->id()]);
        if ($exitCode !== 0) {
            return redirect()->route('public-listings.index', $tenant->slug)->withErrors(['listings' => 'Drafts could not be prepared. Check that your company is active and you are an administrator for it. No public site settings were changed.']);
        }

        return redirect()->route('public-listings.index', $tenant->slug)->with('success', 'Missing apartment listings have been prepared as private drafts. Existing listings remain unchanged. Add the guest details and photos before publishing.');
    }

    public function site(Request $request, PublicListingCatalog $catalog): JsonResponse|RedirectResponse
    {
        $this->authorizeAccess();
        $tenant = app('currentTenant');
        $data = $request->validate([
            'version' => 'required|integer|min:0', 'enabled' => 'sometimes|boolean',
            'settings_page' => 'sometimes|boolean',
            'headline' => 'nullable|string|max:180', 'tagline' => 'nullable|string|max:500',
            'contact_email' => 'nullable|email|max:180', 'contact_phone' => $this->phoneRule(mobileOnly: false),
            'enquiry_assignee_id' => ['nullable', 'integer', Rule::in($catalog->assignees($tenant)->pluck('id')->all())],
            'response_hours' => 'required|integer|min:1|max:168',
            ...$this->websiteRules(),
            'navigation_links' => 'sometimes|array|max:8', 'navigation_links.*' => 'required|array:label,target',
            'navigation_links.*.label' => 'nullable|string|max:60', 'navigation_links.*.target' => ['nullable', Rule::in(array_keys(PublicWebsiteLinks::TARGETS))],
            'logo' => 'nullable|file|mimes:webp,png|max:2048', 'remove_logo' => 'sometimes|boolean',
            'about_intro' => 'nullable|string|max:5000', 'about_how' => 'nullable|string|max:3000',
            'about_main_locations' => 'nullable|string|max:255', 'about_other_areas' => 'nullable|string|max:2000',
            'cancellation_policy' => 'nullable|string|max:3000', 'payment_policy' => 'nullable|string|max:3000', 'faq' => 'nullable|string|max:15000',
            'host_intro' => 'nullable|string|max:1500', 'host_reply_time' => 'nullable|string|max:80|not_regex:/[<>]/', 'host_languages' => 'nullable|string|max:120|not_regex:/[<>]/',
        ], ['logo.mimes' => 'Upload the logo as a WebP or PNG image.', 'logo.max' => 'Keep the logo file under 2 MB.']);
        if (array_key_exists('navigation_links', $data)) {
            $data['navigation_links'] = json_encode(app(PublicWebsiteLinks::class)->normalizeNavigation($data['navigation_links']), JSON_THROW_ON_ERROR);
        }
        $data['enabled'] = $request->boolean('enabled');
        if ($data['enabled'] && (empty($data['enquiry_assignee_id']) || ($tenant->modules['sales'] ?? true) === false)) {
            throw ValidationException::withMessages(['enquiry_assignee_id' => 'Choose an active salesperson and enable the Sales module before opening the public site.']);
        }
        $newLogo = null;
        if ($request->hasFile('logo')) {
            $newLogo = 'public-sites/'.$tenant->id.'/logo-'.Str::random(16).'.webp';
            Storage::disk('local')->put($newLogo, $this->encodeLogo($request->file('logo')));
            $data['logo_path'] = $newLogo;
            $data['logo_updated_at'] = now();
        } elseif ($request->boolean('remove_logo')) {
            $data['logo_path'] = null;
            $data['logo_updated_at'] = now();
        }
        unset($data['settings_page'], $data['logo'], $data['remove_logo']);
        $data = $this->normalizePhones($data, ['whatsapp_number']);
        $data = $this->normalizePhones($data, ['contact_phone'], mobileOnly: false);
        try {
            $previousLogo = DB::transaction(function () use ($tenant, $data): ?string {
                $before = DB::table('public_sites')->where('tenant_id', $tenant->id)->lockForUpdate()->first();
                if ((int) $data['version'] !== (int) ($before->version ?? 0)) {
                    throw ValidationException::withMessages(['site' => 'These site settings changed. Reload the page before saving.']);
                }
                $changes = $data;
                $changes['version'] = (int) $data['version'] + 1;
                $changes['updated_at'] = now();
                if ($before) {
                    DB::table('public_sites')->where('id', $before->id)->update($changes);
                } else {
                    DB::table('public_sites')->insert($changes + ['tenant_id' => $tenant->id, 'created_at' => now()]);
                }
                $this->audit(null, 'site_updated', $before, DB::table('public_sites')->where('tenant_id', $tenant->id)->first());

                return array_key_exists('logo_path', $data) ? ($before->logo_path ?? null) : null;
            });
        } catch (\Throwable $exception) {
            if ($newLogo !== null) {
                Storage::disk('local')->delete($newLogo);
            }
            throw $exception;
        }
        if ($previousLogo !== null && str_starts_with($previousLogo, 'public-sites/'.$tenant->id.'/')) {
            Storage::disk('local')->delete($previousLogo);
        }

        return $this->saved($request, route($request->boolean('settings_page') ? 'public-listings.settings' : 'public-listings.index', $tenant->slug), 'Public site settings saved.');
    }

    /** Hide a listing from the website and the main listings view, keeping it and its history for later. */
    public function archive(string $tenant, int $id): RedirectResponse
    {
        $this->authorizeAccess();
        $this->changeStatus($id, 'archived', 'archived');

        return redirect()->route('public-listings.index', $tenant)->with('success', 'Listing archived. It is hidden from the website and can be restored from the archived listings.');
    }

    /** Bring an archived listing back as a private draft for review before publishing again. */
    public function restore(string $tenant, int $id): RedirectResponse
    {
        $this->authorizeAccess();
        $this->changeStatus($id, 'draft', 'restored');

        return redirect()->route('public-listings.edit', ['tenant' => $tenant, 'id' => $id])->with('success', 'Listing restored as a private draft. Review it and publish when ready.');
    }

    /** Permanently delete a listing that no enquiry refers to, with its photos. */
    public function destroy(string $tenant, int $id, Request $request): RedirectResponse
    {
        $this->authorizeAccess();
        $request->validate(['confirm_delete' => 'accepted'], ['confirm_delete.accepted' => 'Tick the box to confirm you want to delete this listing permanently.']);
        $company = app('currentTenant');
        $photos = DB::transaction(function () use ($company, $id): array {
            $listing = DB::table('public_listings')->where('tenant_id', $company->id)->where('id', $id)->lockForUpdate()->firstOrFail();
            if (DB::table('sales_leads')->where('tenant_id', $company->id)->where('public_listing_id', $id)->exists()) {
                throw ValidationException::withMessages(['confirm_delete' => 'Enquiries refer to this listing, so it cannot be deleted. Archive it instead to hide it and keep the enquiry history.']);
            }
            $photos = DB::table('public_listing_photos')->where('tenant_id', $company->id)->where('listing_id', $id)->pluck('storage_key')->all();
            DB::table('public_listing_photos')->where('tenant_id', $company->id)->where('listing_id', $id)->delete();
            DB::table('public_listings')->where('tenant_id', $company->id)->where('id', $id)->delete();
            $this->audit($id, 'deleted', $listing, (object) ['deleted' => true]);

            return $photos;
        });
        foreach ($photos as $path) {
            if (is_string($path) && str_starts_with($path, 'listing-photos/'.$company->id.'/')) {
                Storage::disk('local')->delete($path);
                app(ListingPhotoVariants::class)->delete($path);
            }
        }

        return redirect()->route('public-listings.index', $tenant)->with('success', 'Listing deleted permanently.');
    }

    private function changeStatus(int $id, string $status, string $action): void
    {
        DB::transaction(function () use ($id, $status, $action): void {
            $before = DB::table('public_listings')->where('tenant_id', app('currentTenant')->id)->where('id', $id)->lockForUpdate()->firstOrFail();
            DB::table('public_listings')->where('id', $before->id)->update(['status' => $status, 'version' => (int) $before->version + 1, 'updated_at' => now()]);
            $this->audit($id, $action, $before, DB::table('public_listings')->find($before->id));
        });
    }

    public function photo(string $tenant, int $id, int $photo, PublicListingCatalog $catalog): BinaryFileResponse
    {
        $this->authorizeAccess();
        $this->listing($id);
        $image = $catalog->imageForListing(app('currentTenant'), $id, $photo);

        return response()->file($image['path'], ['Content-Type' => $image['mime_type'], 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'no-store'])->setPrivate();
    }

    private function listing(int $id): object
    {
        return DB::table('public_listings')->where('tenant_id', app('currentTenant')->id)->where('id', $id)->firstOrFail();
    }

    private function save(Request $request, PublicListingCatalog $catalog, ?int $id = null): JsonResponse|RedirectResponse
    {
        $this->authorizeAccess();
        $tenant = app('currentTenant');
        $before = $id ? $this->listing($id) : null;
        $request->merge(['amenities' => $request->input('amenities', []), 'collections' => $request->input('collections', []),
            'listing_code' => is_string($request->input('listing_code')) ? strtoupper(trim($request->input('listing_code'))) : $request->input('listing_code')]);
        if ($request->filled('apartment_ref')) {
            $reference = $request->input('apartment_ref');
            if (! is_string($reference) || ! preg_match('/^(unit|partner):([1-9][0-9]{0,9})$/D', $reference, $parts) || (int) $parts[2] > 4294967295) {
                throw ValidationException::withMessages(['apartment_ref' => 'Choose an existing managed or partner apartment.']);
            }
            $source = $parts[1] === 'unit' ? 'unit_id' : 'partner_apartment_id';
            $other = $parts[1] === 'unit' ? 'partner_apartment_id' : 'unit_id';
            if ($request->filled($other) || ($request->filled($source) && (string) $request->input($source) !== $parts[2])) {
                throw ValidationException::withMessages(['apartment_ref' => 'Choose one apartment. Conflicting apartment references cannot be saved.']);
            }
            $request->merge([$source => (int) $parts[2], $other => null]);
        }
        $data = $request->validate([
            'version' => $id ? 'required|integer|min:1' : 'nullable|integer',
            'unit_id' => 'nullable|integer|min:1|max:4294967295',
            'partner_apartment_id' => 'nullable|integer|min:1|max:4294967295',
            'slug' => ['required', 'string', 'max:160', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', Rule::notIn(PublicListingCatalog::RESERVED_SLUGS), Rule::unique('public_listings')->where('tenant_id', $tenant->id)->ignore($id)],
            'title' => 'required|string|min:3|max:180', 'description' => 'required|string|min:30|max:15000',
            'city' => 'required|string|max:100', 'neighbourhood' => 'nullable|string|max:150', 'public_location' => 'nullable|string|max:255',
            'max_guests' => 'required|integer|min:1|max:100', 'min_nights' => 'required|integer|min:1|max:365',
            'check_in_time' => 'required|date_format:H:i', 'check_out_time' => 'required|date_format:H:i',
            'starting_price' => ['nullable', 'numeric', 'min:0.01', 'max:9999999999.99', 'regex:/^\d+(?:\.\d{1,2})?$/D'],
            'amenities' => 'nullable|array|max:30', 'amenities.*' => ['string', Rule::in(array_keys(PublicListingCatalog::AMENITIES))],
            'property_type' => ['nullable', Rule::in(array_keys(PublicListingCatalog::PROPERTY_TYPES))],
            'bedrooms' => ['nullable', 'integer', Rule::in(array_keys(PublicListingCatalog::LAYOUTS))],
            'bathrooms' => 'nullable|integer|min:0|max:20', 'beds' => 'nullable|integer|min:1|max:60',
            'bed_setup' => 'nullable|string|max:160|not_regex:/[<>]/',
            'collections' => 'nullable|array|max:6', 'collections.*' => ['string', Rule::in(array_keys(PublicListingCatalog::COLLECTIONS))],
            'listing_code' => ['nullable', 'string', 'max:20', 'regex:/^[A-Z0-9][A-Z0-9-]*$/D', Rule::unique('public_listings')->where('tenant_id', $tenant->id)->ignore($id)],
            'house_rules' => 'nullable|string|max:5000', 'status' => 'required|in:draft,published,paused', 'featured' => 'sometimes|boolean',
            'cleaning_fee' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99', 'regex:/^\d+(?:\.\d{1,2})?$/D'],
            'weekly_discount_percent' => 'nullable|integer|min:0|max:90', 'monthly_discount_percent' => 'nullable|integer|min:0|max:90',
            'photos' => 'nullable|array|max:10', 'photos.*' => 'required|file|mimes:jpg,jpeg,png,webp|max:5120',
            'remove_photos' => 'nullable|array|max:30', 'remove_photos.*' => 'required|integer',
            'photo_order' => 'nullable|array|max:30', 'photo_order.*' => 'required|integer|min:0|max:1000',
            'cover_photo' => 'nullable|integer|min:1',
            'photo_rooms' => 'nullable|array|max:30', 'photo_rooms.*' => ['nullable', Rule::in(array_keys(ListingDetails::ROOM_TAGS))],
            'location' => 'nullable|string|max:1000',
            'nearby' => 'nullable|array|max:'.ListingDetails::MAX_NEARBY, 'nearby.*.name' => 'nullable|string|max:60',
            'nearby.*.minutes' => 'nullable|integer|min:1|max:600', 'nearby.*.mode' => ['nullable', Rule::in(array_keys(ListingDetails::TRAVEL_MODES))],
            'rooms' => 'nullable|array|max:'.ListingDetails::MAX_ROOMS, 'rooms.*.name' => 'nullable|string|max:40',
            'rooms.*.beds' => 'nullable|string|max:80|not_regex:/[<>]/', 'rooms.*.photo_id' => 'nullable|integer', 'rooms.*.ensuite' => 'sometimes|boolean',
            'size_sqm' => 'nullable|integer|min:10|max:5000', 'floor_level' => 'nullable|string|max:30|not_regex:/[<>]/',
            'parking_spaces' => 'nullable|integer|min:0|max:20', 'virtual_tour_url' => 'nullable|string|max:500',
            'photos_checked' => 'sometimes|boolean', 'check_in_guide' => 'nullable|string|max:5000',
            'weekend_price' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99', 'regex:/^\d+(?:\.\d{1,2})?$/D'],
            'seasonal_rates' => 'nullable|array|max:'.ListingDetails::MAX_SEASONS, 'seasonal_rates.*.name' => 'nullable|string|max:40',
            'seasonal_rates.*.from' => 'nullable|date_format:Y-m-d', 'seasonal_rates.*.to' => 'nullable|date_format:Y-m-d|after_or_equal:seasonal_rates.*.from',
            'seasonal_rates.*.price' => 'nullable|numeric|min:1|max:9999999999',
            'photo_captions' => 'nullable|array|max:30', 'photo_captions.*' => 'nullable|string|max:180',
            'youtube_videos' => 'sometimes|array|max:2', 'youtube_videos.*' => 'required|array:url,title',
            'youtube_videos.*.url' => 'nullable|string|max:2048', 'youtube_videos.*.title' => 'nullable|string|max:120',
            ...$this->websiteRules(),
        ]);
        $data = $this->normalizePhones($data, ['whatsapp_number']);
        if (array_key_exists('youtube_videos', $data)) {
            $data['youtube_videos'] = app(YouTubeListingVideos::class)->normalize($data['youtube_videos']);
        }
        if (empty($data['unit_id']) === empty($data['partner_apartment_id'])) {
            throw ValidationException::withMessages(['unit_id' => 'Choose exactly one existing managed or partner apartment.', 'apartment_ref' => 'Choose exactly one existing managed or partner apartment.']);
        }
        $source = ! empty($data['unit_id']) ? 'unit_id' : 'partner_apartment_id';
        $data += ['unit_id' => null, 'partner_apartment_id' => null];
        $reference = ($source === 'unit_id' ? 'unit:' : 'partner:').$data[$source];
        $errorField = $request->filled('apartment_ref') ? 'apartment_ref' : $source;
        $unit = $catalog->apartments($tenant)->firstWhere('reference', $reference);
        $sameApartment = $before && (int) ($before->{$source} ?? 0) === (int) $data[$source];
        if (! $unit || ($unit->status !== 'active' && ! $sameApartment)) {
            throw ValidationException::withMessages([$errorField => 'Choose an active apartment belonging to this company.']);
        }
        if ($data['status'] === 'published' && $unit->kind === 'managed' && (float) ($data['starting_price'] ?? $unit->nightly_rate) <= 0) {
            throw ValidationException::withMessages(['starting_price' => 'Enter a positive public starting price before publishing. This apartment does not have a positive nightly rate to use.']);
        }
        if (DB::table('public_listings')->where('tenant_id', $tenant->id)->where($source, $data[$source])->when($id, fn ($query) => $query->where('id', '!=', $id))->exists()) {
            throw ValidationException::withMessages([$errorField => 'This apartment already has a public listing. Edit that listing instead.']);
        }
        $location = ListingDetails::parseLocation($data['location'] ?? null);
        if (trim((string) ($data['location'] ?? '')) !== '' && $location === null) {
            throw ValidationException::withMessages(['location' => 'Paste a Google Maps link or coordinates such as -4.0435, 39.7123.']);
        }
        if (trim((string) ($data['virtual_tour_url'] ?? '')) !== '' && ListingDetails::tourEmbed($data['virtual_tour_url']) === null) {
            throw ValidationException::withMessages(['virtual_tour_url' => 'Use a Kuula share link (https://kuula.co/share/...) or a Matterport link (https://my.matterport.com/show/?m=...).']);
        }
        $data['latitude'] = $location[0] ?? null;
        $data['longitude'] = $location[1] ?? null;
        $encoded = array_map(fn (UploadedFile $file): array => $this->encode($file), $request->file('photos', []));
        $stored = [];
        $remove = [];
        try {
            $id = DB::transaction(function () use ($tenant, $before, $data, $unit, $encoded, &$stored, &$remove): int {
                $latest = $before ? DB::table('public_listings')->where('tenant_id', $tenant->id)->where('id', $before->id)->lockForUpdate()->firstOrFail() : null;
                if ($latest && (int) $data['version'] !== (int) $latest->version) {
                    throw ValidationException::withMessages(['listing' => 'This listing changed. Reload the page before saving.']);
                }
                $currentPhotos = $latest ? DB::table('public_listing_photos')->where('tenant_id', $tenant->id)->where('listing_id', $latest->id)->lockForUpdate()->get()->keyBy('id') : collect();
                $requestedIds = array_unique(array_merge($data['remove_photos'] ?? [], array_keys($data['photo_order'] ?? []), array_keys($data['photo_captions'] ?? [])));
                foreach ($requestedIds as $photoId) {
                    if (! $currentPhotos->has($photoId)) {
                        throw ValidationException::withMessages(['photos' => 'Choose photographs belonging to this listing.']);
                    }
                }
                $deletedIds = array_unique($data['remove_photos'] ?? []);
                $photoCount = $currentPhotos->count() - count($deletedIds) + count($encoded);
                if ($photoCount > 30 || ($data['status'] === 'published' && ($photoCount < 1 || $unit->status !== 'active'))) {
                    throw ValidationException::withMessages(['photos' => 'A published listing needs an active apartment and at least one photo. Keep at most 30 photos.']);
                }
                $changes = array_intersect_key($data, array_flip(['unit_id', 'partner_apartment_id', 'slug', 'title', 'description', 'city', 'neighbourhood', 'public_location', 'max_guests', 'min_nights', 'check_in_time', 'check_out_time', 'starting_price', 'house_rules', 'status', 'seo_title', 'seo_description', 'whatsapp_number', 'whatsapp_message', 'cleaning_fee', 'weekly_discount_percent', 'monthly_discount_percent', 'bedrooms', 'bathrooms', 'beds', 'bed_setup']));
                $changes += ['neighbourhood' => null, 'public_location' => null, 'starting_price' => null, 'house_rules' => null, 'cleaning_fee' => null, 'weekly_discount_percent' => null, 'monthly_discount_percent' => null,
                    'bedrooms' => null, 'bathrooms' => null, 'beds' => null, 'bed_setup' => null];
                $keptPhotoIds = array_values(array_diff($currentPhotos->keys()->map(fn ($photoId): int => (int) $photoId)->all(), array_map('intval', $deletedIds)));
                $changes += [
                    'latitude' => $data['latitude'], 'longitude' => $data['longitude'],
                    'nearby' => json_encode(ListingDetails::nearby($data['nearby'] ?? []), JSON_THROW_ON_ERROR),
                    'rooms' => json_encode(ListingDetails::rooms($data['rooms'] ?? [], $keptPhotoIds), JSON_THROW_ON_ERROR),
                    'size_sqm' => $data['size_sqm'] ?? null, 'floor_level' => trim((string) ($data['floor_level'] ?? '')) ?: null,
                    'parking_spaces' => $data['parking_spaces'] ?? null, 'virtual_tour_url' => trim((string) ($data['virtual_tour_url'] ?? '')) ?: null,
                    'weekend_price' => ($data['weekend_price'] ?? null) ?: null,
                    'seasonal_rates' => json_encode(ListingDetails::seasons($data['seasonal_rates'] ?? []), JSON_THROW_ON_ERROR),
                    'check_in_guide' => trim((string) ($data['check_in_guide'] ?? '')) ?: null,
                    'photos_verified_at' => ! empty($data['photos_checked']) ? now()->toDateString() : ($latest->photos_verified_at ?? null),
                ];
                $changes['property_type'] = $data['property_type'] ?? null;
                $changes['listing_code'] = ($data['listing_code'] ?? null) ?: null;
                $changes['collections'] = json_encode(array_values(array_unique($data['collections'] ?? [])), JSON_THROW_ON_ERROR);
                $changes['amenities'] = json_encode(array_values(array_unique($data['amenities'] ?? [])), JSON_THROW_ON_ERROR);
                if (array_key_exists('youtube_videos', $data)) {
                    $changes['youtube_videos'] = json_encode($data['youtube_videos'], JSON_THROW_ON_ERROR);
                }
                $changes['featured'] = (bool) ($data['featured'] ?? false);
                $changes['version'] = (int) ($latest->version ?? 0) + 1;
                $changes['updated_at'] = now();
                if ($latest) {
                    DB::table('public_listings')->where('id', $latest->id)->update($changes);
                    $listingId = (int) $latest->id;
                } else {
                    $listingId = DB::table('public_listings')->insertGetId($changes + ['tenant_id' => $tenant->id, 'created_at' => now()]);
                }
                foreach ($deletedIds as $photoId) {
                    $remove[] = $currentPhotos->get($photoId)->storage_key;
                    DB::table('public_listing_photos')->where('tenant_id', $tenant->id)->where('listing_id', $listingId)->where('id', $photoId)->delete();
                }
                foreach ($currentPhotos as $photo) {
                    if (in_array($photo->id, $deletedIds)) {
                        continue;
                    }
                    DB::table('public_listing_photos')->where('id', $photo->id)->update([
                        'sort_order' => $data['photo_order'][$photo->id] ?? $photo->sort_order,
                        'caption' => $data['photo_captions'][$photo->id] ?? $photo->caption,
                        'room' => array_key_exists($photo->id, $data['photo_rooms'] ?? []) ? ($data['photo_rooms'][$photo->id] ?: null) : $photo->room,
                        'updated_at' => now(),
                    ]);
                }
                $coverId = (int) ($data['cover_photo'] ?? 0);
                if ($coverId > 0 && $currentPhotos->has($coverId) && ! in_array($coverId, array_map('intval', $deletedIds), true)) {
                    // The chosen cover goes first; the others keep their order behind it.
                    $ordered = DB::table('public_listing_photos')->where('tenant_id', $tenant->id)->where('listing_id', $listingId)
                        ->orderBy('sort_order')->orderBy('id')->pluck('id')->map(fn ($photoId): int => (int) $photoId)->all();
                    foreach (array_values(array_unique([$coverId, ...$ordered])) as $position => $photoId) {
                        DB::table('public_listing_photos')->where('id', $photoId)->update(['sort_order' => $position, 'updated_at' => now()]);
                    }
                }
                $sort = (int) $currentPhotos->max('sort_order') + 1;
                foreach ($encoded as $photo) {
                    $path = 'listing-photos/'.$tenant->id.'/'.$listingId.'/'.bin2hex(random_bytes(24)).'.'.$photo['extension'];
                    if (! Storage::disk('local')->put($path, $photo['contents'])) {
                        throw new \RuntimeException('The photo could not be stored.');
                    }
                    $stored[] = $path;
                    DB::table('public_listing_photos')->insert([
                        'tenant_id' => $tenant->id, 'listing_id' => $listingId, 'storage_key' => $path,
                        'mime_type' => $photo['mime_type'], 'caption' => null, 'sort_order' => $sort++,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
                $this->audit($listingId, $latest ? 'listing_updated' : 'listing_created', $latest ? (object) ['listing' => $latest, 'photos' => $currentPhotos->values()->all()] : null,
                    (object) ['listing' => DB::table('public_listings')->find($listingId), 'photos' => DB::table('public_listing_photos')->where('listing_id', $listingId)->orderBy('sort_order')->orderBy('id')->get()->all()]);

                return $listingId;
            });
        } catch (\Throwable $exception) {
            foreach ($stored as $path) {
                Storage::disk('local')->delete($path);
            }
            throw $exception;
        }
        foreach ($remove as $path) {
            try {
                Storage::disk('local')->delete($path);
                app(ListingPhotoVariants::class)->delete($path);
            } catch (\Throwable $exception) {
                report($exception);
            }
        }
        foreach ($stored as $path) {
            app(ListingPhotoVariants::class)->make($path);
        }

        return $this->saved($request, route('public-listings.edit', ['tenant' => $tenant->slug, 'id' => $id]), 'Listing saved. Only published listings appear on an enabled public site.');
    }

    private function websiteRules(): array
    {
        return ['seo_title' => 'nullable|string|max:180|not_regex:/[<>]/',
            'seo_description' => 'nullable|string|max:320|not_regex:/[<>]/',
            'whatsapp_number' => $this->phoneRule(),
            'whatsapp_message' => 'nullable|string|max:1000'];
    }

    /** @return list<mixed> */
    private function phoneRule(bool $mobileOnly = true): array
    {
        return ['nullable', 'string', 'max:40', function (string $attribute, mixed $value, \Closure $fail) use ($mobileOnly): void {
            if (is_string($value) && ! PhoneNumber::isBlank($value) && PhoneNumber::normalize($value, $mobileOnly) === null) {
                $fail($mobileOnly
                    ? 'Enter a valid mobile number, such as 0712 345 678 or +254 712 345 678. Kenyan numbers need 9 digits after +254.'
                    : 'Enter a valid phone number, such as 0712 345 678 or +254 20 123 4567. Kenyan numbers need 9 digits after +254.');
            }
        }];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $fields
     * @return array<string, mixed>
     */
    private function normalizePhones(array $data, array $fields, bool $mobileOnly = true): array
    {
        foreach ($fields as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = is_string($data[$field]) ? PhoneNumber::normalize($data[$field], $mobileOnly) : null;
            }
        }

        return $data;
    }

    private function saved(Request $request, string $url, string $message): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            $request->session()->flash('success', $message);

            return response()->json(['redirect' => $url, 'message' => $message]);
        }

        return redirect()->to($url)->with('success', $message);
    }

    /** Re-encode an uploaded logo as WebP, keeping transparency and fitting it within 800 × 400 pixels. */
    private function encodeLogo(UploadedFile $file): string
    {
        if (! function_exists('imagecreatefromstring') || ! function_exists('imagewebp')) {
            throw ValidationException::withMessages(['logo' => 'Image processing is unavailable. Ask an administrator to enable WebP support in the PHP GD extension.']);
        }
        $dimensions = @getimagesize($file->getPathname());
        if (! $dimensions || $dimensions[0] < 16 || $dimensions[1] < 16 || $dimensions[0] > 4000 || $dimensions[1] > 4000) {
            throw ValidationException::withMessages(['logo' => 'Use a logo between 16 and 4,000 pixels per side.']);
        }
        $image = @imagecreatefromstring((string) file_get_contents($file->getPathname()));
        if (! $image) {
            throw ValidationException::withMessages(['logo' => 'The logo could not be read. Upload a WebP or PNG image.']);
        }
        try {
            $ratio = min(1, 800 / imagesx($image), 400 / imagesy($image));
            $width = max(1, (int) round(imagesx($image) * $ratio));
            $height = max(1, (int) round(imagesy($image) * $ratio));
            $canvas = imagecreatetruecolor($width, $height);
            imagealphablending($canvas, false);
            imagesavealpha($canvas, true);
            imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 0, 0, 0, 127));
            imagecopyresampled($canvas, $image, 0, 0, 0, 0, $width, $height, imagesx($image), imagesy($image));
            ob_start();
            $written = imagewebp($canvas, null, 90);
            $encoded = (string) ob_get_clean();
            imagedestroy($canvas);
            if (! $written || $encoded === '') {
                throw ValidationException::withMessages(['logo' => 'The logo could not be processed. Try another WebP or PNG file.']);
            }

            return $encoded;
        } finally {
            imagedestroy($image);
        }
    }

    private function encode(UploadedFile $file): array
    {
        if (! function_exists('imagecreatefromstring')) {
            throw ValidationException::withMessages(['photos' => 'Image processing is unavailable. Ask an administrator to enable the PHP GD extension.']);
        }
        $dimensions = @getimagesize($file->getPathname());
        if (! $dimensions || $dimensions[0] < 10 || $dimensions[1] < 10 || $dimensions[0] > 6000 || $dimensions[1] > 6000 || $dimensions[0] * $dimensions[1] > 16000000) {
            throw ValidationException::withMessages(['photos' => 'Use a clear photo between 10 and 6,000 pixels per side, with at most 16 megapixels.']);
        }
        $memoryLimit = trim((string) ini_get('memory_limit'));
        $multiplier = match (strtolower(substr($memoryLimit, -1))) {
            'g' => 1073741824, 'm' => 1048576, 'k' => 1024, default => 1
        };
        $memoryBytes = (int) $memoryLimit * $multiplier;
        $estimatedBytes = memory_get_usage(true) + $dimensions[0] * $dimensions[1] * 6 + min($dimensions[0], 2400) * min($dimensions[1], 2400) * 5;
        if ($memoryBytes > 0 && $estimatedBytes > $memoryBytes * 0.9) {
            throw ValidationException::withMessages(['photos' => 'This photo is too large to process. Resize it to 2,400 pixels on its longest side and upload it again.']);
        }
        $image = @imagecreatefromstring(file_get_contents($file->getPathname()));
        if (! $image) {
            throw ValidationException::withMessages(['photos' => 'The photo could not be read. Upload a JPG, PNG or WEBP image.']);
        }
        try {
            if (max(imagesx($image), imagesy($image)) > 2400) {
                $ratio = 2400 / max(imagesx($image), imagesy($image));
                $scaled = imagescale($image, max(1, (int) round(imagesx($image) * $ratio)), max(1, (int) round(imagesy($image) * $ratio)), IMG_BICUBIC_FIXED);
                if (! $scaled) {
                    throw new \RuntimeException('Image resizing failed.');
                }
                imagedestroy($image);
                $image = $scaled;
            }
            if ($dimensions['mime'] === 'image/jpeg' && function_exists('exif_read_data')) {
                $orientation = (int) (@exif_read_data($file->getPathname())['Orientation'] ?? 1);
                $angle = match ($orientation) {
                    3 => 180, 5, 6 => -90, 7, 8 => 90, default => 0
                };
                if ($angle !== 0 && ($rotated = imagerotate($image, $angle, 0))) {
                    imagedestroy($image);
                    $image = $rotated;
                }
                if (in_array($orientation, [2, 5, 7], true)) {
                    imageflip($image, IMG_FLIP_HORIZONTAL);
                } elseif ($orientation === 4) {
                    imageflip($image, IMG_FLIP_VERTICAL);
                }
            }
            $png = $dimensions['mime'] === 'image/png';
            $extension = $png ? 'png' : 'jpg';
            if ($png) {
                imagealphablending($image, false);
                imagesavealpha($image, true);
            }
            ob_start();
            try {
                $saved = $png ? imagepng($image, null, 7) : imagejpeg($image, null, 85);
                $contents = ob_get_contents();
            } finally {
                ob_end_clean();
            }
            if (! $saved || ! is_string($contents)) {
                throw new \RuntimeException('Image processing failed.');
            }

            return ['contents' => $contents, 'extension' => $extension, 'mime_type' => $png ? 'image/png' : 'image/jpeg'];
        } finally {
            imagedestroy($image);
        }
    }

    private function audit(?int $listingId, string $action, ?object $before, object $after): void
    {
        DB::table('public_listing_audit_events')->insert([
            'tenant_id' => app('currentTenant')->id, 'listing_id' => $listingId, 'actor_id' => auth()->id(),
            'action' => $action, 'before_snapshot' => $before ? json_encode((array) $before, JSON_THROW_ON_ERROR) : null,
            'after_snapshot' => json_encode((array) $after, JSON_THROW_ON_ERROR), 'created_at' => now(),
        ]);
    }
}
