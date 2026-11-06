<?php

namespace App\Http\Controllers;

use App\Services\PublicListingCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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

        return view('public_listings.index', [
            'listings' => DB::table('public_listings')->where('tenant_id', $tenant->id)->orderBy('title')->get(),
            'site' => $catalog->settings($tenant), 'members' => $catalog->assignees($tenant),
            'units' => $catalog->units($tenant)->keyBy('id'),
        ]);
    }

    public function create(PublicListingCatalog $catalog): View
    {
        $this->authorizeAccess();

        return view('public_listings.form', ['listing' => null, 'units' => $catalog->units(app('currentTenant')), 'photos' => collect(), 'amenities' => PublicListingCatalog::AMENITIES]);
    }

    public function edit(string $tenant, int $id, PublicListingCatalog $catalog): View
    {
        $this->authorizeAccess();
        $listing = $this->listing($id);

        return view('public_listings.form', [
            'listing' => $listing, 'units' => $catalog->units(app('currentTenant')),
            'photos' => DB::table('public_listing_photos')->where('tenant_id', app('currentTenant')->id)->where('listing_id', $id)->orderBy('sort_order')->orderBy('id')->get(),
            'amenities' => PublicListingCatalog::AMENITIES,
        ]);
    }

    public function store(Request $request, PublicListingCatalog $catalog): RedirectResponse
    {
        return $this->save($request, $catalog);
    }

    public function update(string $tenant, int $id, Request $request, PublicListingCatalog $catalog): RedirectResponse
    {
        return $this->save($request, $catalog, $id);
    }

    public function site(Request $request, PublicListingCatalog $catalog): RedirectResponse
    {
        $this->authorizeAccess();
        $tenant = app('currentTenant');
        $data = $request->validate([
            'version' => 'required|integer|min:0', 'enabled' => 'sometimes|boolean',
            'headline' => 'nullable|string|max:180', 'tagline' => 'nullable|string|max:500',
            'contact_email' => 'nullable|email|max:180', 'contact_phone' => 'nullable|string|max:40',
            'enquiry_assignee_id' => ['nullable', 'integer', Rule::in($catalog->assignees($tenant)->pluck('id')->all())],
            'response_hours' => 'required|integer|min:1|max:168',
        ]);
        $data['enabled'] = $request->boolean('enabled');
        if ($data['enabled'] && (empty($data['enquiry_assignee_id']) || ($tenant->modules['sales'] ?? true) === false)) {
            throw ValidationException::withMessages(['enquiry_assignee_id' => 'Choose an active salesperson and enable the Sales module before opening the public site.']);
        }
        DB::transaction(function () use ($tenant, $data): void {
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
        });

        return redirect()->route('public-listings.index', $tenant->slug)->with('success', 'Public site settings saved.');
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

    private function save(Request $request, PublicListingCatalog $catalog, ?int $id = null): RedirectResponse
    {
        $this->authorizeAccess();
        $tenant = app('currentTenant');
        $before = $id ? $this->listing($id) : null;
        $request->merge(['amenities' => $request->input('amenities', [])]);
        $data = $request->validate([
            'version' => $id ? 'required|integer|min:1' : 'nullable|integer',
            'unit_id' => 'required|integer|min:1',
            'slug' => ['required', 'string', 'max:160', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', Rule::unique('public_listings')->where('tenant_id', $tenant->id)->ignore($id)],
            'title' => 'required|string|min:3|max:180', 'description' => 'required|string|min:30|max:15000',
            'city' => 'required|string|max:100', 'neighbourhood' => 'nullable|string|max:150', 'public_location' => 'nullable|string|max:255',
            'max_guests' => 'required|integer|min:1|max:100', 'min_nights' => 'required|integer|min:1|max:365',
            'check_in_time' => 'required|date_format:H:i', 'check_out_time' => 'required|date_format:H:i',
            'starting_price' => ['nullable', 'numeric', 'min:0.01', 'max:9999999999.99', 'regex:/^\d+(?:\.\d{1,2})?$/D'],
            'amenities' => 'nullable|array|max:30', 'amenities.*' => ['string', Rule::in(array_keys(PublicListingCatalog::AMENITIES))],
            'house_rules' => 'nullable|string|max:5000', 'status' => 'required|in:draft,published,paused', 'featured' => 'sometimes|boolean',
            'photos' => 'nullable|array|max:10', 'photos.*' => 'required|file|mimes:jpg,jpeg,png,webp|max:5120',
            'remove_photos' => 'nullable|array|max:30', 'remove_photos.*' => 'required|integer',
            'photo_order' => 'nullable|array|max:30', 'photo_order.*' => 'required|integer|min:0|max:1000',
            'photo_captions' => 'nullable|array|max:30', 'photo_captions.*' => 'nullable|string|max:180',
        ]);
        $unit = $catalog->units($tenant)->firstWhere('id', (int) $data['unit_id']);
        if (! $unit || ($unit->status !== 'active' && (int) $unit->id !== (int) ($before->unit_id ?? 0))) {
            throw ValidationException::withMessages(['unit_id' => 'Choose an active apartment belonging to this company.']);
        }
        if ($data['status'] === 'published' && (float) ($data['starting_price'] ?? $unit->nightly_rate) <= 0) {
            throw ValidationException::withMessages(['starting_price' => 'Enter a positive public starting price before publishing. This apartment does not have a positive nightly rate to use.']);
        }
        if (DB::table('public_listings')->where('tenant_id', $tenant->id)->where('unit_id', $data['unit_id'])->when($id, fn ($query) => $query->where('id', '!=', $id))->exists()) {
            throw ValidationException::withMessages(['unit_id' => 'This apartment already has a public listing. Edit that listing instead.']);
        }
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
                $changes = array_intersect_key($data, array_flip(['unit_id', 'slug', 'title', 'description', 'city', 'neighbourhood', 'public_location', 'max_guests', 'min_nights', 'check_in_time', 'check_out_time', 'starting_price', 'house_rules', 'status']));
                $changes += ['neighbourhood' => null, 'public_location' => null, 'starting_price' => null, 'house_rules' => null];
                $changes['amenities'] = json_encode(array_values(array_unique($data['amenities'] ?? [])), JSON_THROW_ON_ERROR);
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
                        'caption' => $data['photo_captions'][$photo->id] ?? $photo->caption, 'updated_at' => now(),
                    ]);
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
            } catch (\Throwable $exception) {
                report($exception);
            }
        }

        return redirect()->route('public-listings.edit', ['tenant' => $tenant->slug, 'id' => $id])->with('success', 'Listing saved. Only published listings appear on an enabled public site.');
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
