<?php

namespace App\Services;

use App\Models\Tenant;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Private links that let a partner owner see a staff availability request and answer it with one tap.
 */
class PartnerAvailabilityRequests
{
    public const CODE_PATTERN = '[A-Za-z0-9_-]{12}';

    public const VALID_DAYS = 14;

    /** @var array<string, string> */
    public const RESPONSES = ['available' => 'Available', 'unavailable' => 'Not available'];

    /**
     * Return the private link for this request, reusing one created for the same apartment, dates and guests in the
     * last day so repeated clicks do not create duplicates.
     *
     * @param  array{check_in?: ?string, check_out?: ?string, guests?: int|string|null}  $stay
     * @return array{id: int, url: string}
     */
    public function issue(Tenant $tenant, object $partner, string $label, array $stay, ?int $userId): array
    {
        $checkIn = $stay['check_in'] ?? null ?: null;
        $checkOut = $stay['check_out'] ?? null ?: null;
        $guests = (int) ($stay['guests'] ?? 0) ?: null;
        $existing = DB::table('partner_availability_requests')->where('tenant_id', $tenant->id)->where('partner_apartment_id', $partner->id)
            ->where('check_in', $checkIn)->where('check_out', $checkOut)->where('guests', $guests)
            ->whereNull('response')->where('created_at', '>=', now()->subDay())->where('expires_at', '>', now())->latest('id')->first();
        if ($existing && ($url = $this->url($existing))) {
            return ['id' => (int) $existing->id, 'url' => $url];
        }
        // 9 random bytes encode to exactly 12 URL-safe characters (72 bits).
        $code = strtr(base64_encode(random_bytes(9)), '+/', '-_');
        $id = DB::table('partner_availability_requests')->insertGetId([
            'tenant_id' => $tenant->id, 'partner_apartment_id' => $partner->id, 'apartment_label' => mb_substr($label, 0, 255),
            'check_in' => $checkIn, 'check_out' => $checkOut, 'guests' => $guests,
            'code_hash' => hash('sha256', $code), 'code_encrypted' => Crypt::encryptString($code), 'requested_by' => $userId,
            'expires_at' => now()->addDays(self::VALID_DAYS), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return ['id' => $id, 'url' => $this->linkFor($code)];
    }

    /** @return array{request: object, tenant: Tenant}|null */
    public function find(string $code): ?array
    {
        if (! preg_match('/^'.self::CODE_PATTERN.'$/D', $code)) {
            return null;
        }
        $request = DB::table('partner_availability_requests')->where('code_hash', hash('sha256', $code))->where('expires_at', '>', now())->first();
        $tenant = $request ? Tenant::whereKey($request->tenant_id)->where('active', true)->first() : null;

        return $tenant ? ['request' => $request, 'tenant' => $tenant] : null;
    }

    /**
     * What the owner sees about the unit being asked about: its description and, when it has a website listing
     * (published or not), up to eight photos. Owner contact details are never included.
     *
     * @return array{unit: ?object, listing: ?object, photos: Collection<int, object>}
     */
    public function unitDetails(Tenant $tenant, object $request): array
    {
        $config = config('database.connections.tenant');
        $config['database'] = $tenant->database_name;
        $db = DB::build($config);
        try {
            $unit = $db->getSchemaBuilder()->hasTable('partner_apartments')
                ? $db->table('partner_apartments')->where('id', $request->partner_apartment_id)
                    ->first(['apartment_name', 'building_name', 'unit_number', 'location', 'bedrooms', 'bathrooms'])
                : null;
        } finally {
            $db->disconnect();
        }
        $listing = $this->listing($tenant, $request);

        return ['unit' => $unit, 'listing' => $listing, 'featured' => $this->featuredPhotoPath($tenant, (int) $request->partner_apartment_id) !== null, 'photos' => $listing
            ? DB::table('public_listing_photos')->where('tenant_id', $tenant->id)->where('listing_id', $listing->id)
                ->orderBy('sort_order')->orderBy('id')->limit(8)->get(['id', 'caption'])
            : collect()];
    }

    /**
     * Best link to show owners for each partner apartment: the published website listing when the site is on,
     * otherwise the outside listing link staff recorded (WordPress, Airbnb and so on).
     *
     * @param  list<int>  $partnerIds
     * @return array<int, string>
     */
    public function listingLinks(Tenant $tenant, array $partnerIds): array
    {
        if ($partnerIds === []) {
            return [];
        }
        $links = DB::table('partner_apartment_links')->where('tenant_id', $tenant->id)->whereIn('partner_apartment_id', $partnerIds)
            ->pluck('listing_url', 'partner_apartment_id')->mapWithKeys(fn ($url, $id): array => [(int) $id => (string) $url])->all();
        if (app(PublicListingCatalog::class)->site($tenant) !== null) {
            $published = DB::table('public_listings')->where('tenant_id', $tenant->id)->whereIn('partner_apartment_id', $partnerIds)
                ->where('status', 'published')->pluck('slug', 'partner_apartment_id');
            foreach ($published as $id => $slug) {
                $links[(int) $id] = rtrim(config('app.url'), '/').route('public-stays.show', ['company' => $tenant->slug, 'slug' => $slug], false);
            }
        }

        return $links;
    }

    /** The outside listing link recorded for a partner apartment, if any. */
    public function externalLink(Tenant $tenant, int $partnerId): ?string
    {
        return DB::table('partner_apartment_links')->where('tenant_id', $tenant->id)->where('partner_apartment_id', $partnerId)->value('listing_url');
    }

    public function saveExternalLink(Tenant $tenant, int $partnerId, ?string $url): void
    {
        if (blank($url)) {
            DB::table('partner_apartment_links')->where('tenant_id', $tenant->id)->where('partner_apartment_id', $partnerId)->delete();

            return;
        }
        $existing = DB::table('partner_apartment_links')->where('tenant_id', $tenant->id)->where('partner_apartment_id', $partnerId)->first();
        DB::table('partner_apartment_links')->updateOrInsert(['tenant_id' => $tenant->id, 'partner_apartment_id' => $partnerId],
            ['listing_url' => $url, 'updated_at' => now(), 'created_at' => $existing->created_at ?? now()]);
    }

    /** Storage path of the partner apartment's featured photo, if it has one. */
    public function featuredPhotoPath(Tenant $tenant, int $partnerId): ?string
    {
        $path = DB::table('partner_apartment_photos')->where('tenant_id', $tenant->id)->where('partner_apartment_id', $partnerId)->value('storage_key');

        return is_string($path) && preg_match('#^partner-photos/'.(int) $tenant->id.'/'.$partnerId.'-[A-Za-z0-9]{24}\.jpg$#D', $path)
            && Storage::disk('local')->exists($path) ? $path : null;
    }

    /**
     * Partner apartments (of those given) that have a featured photo.
     *
     * @param  list<int>  $partnerIds
     * @return list<int>
     */
    public function partnersWithPhotos(Tenant $tenant, array $partnerIds): array
    {
        return DB::table('partner_apartment_photos')->where('tenant_id', $tenant->id)->whereIn('partner_apartment_id', $partnerIds)
            ->pluck('partner_apartment_id')->map(fn ($id): int => (int) $id)->all();
    }

    /** The unit's website listing, if any, excluding archived ones. */
    public function listing(Tenant $tenant, object $request): ?object
    {
        return DB::table('public_listings')->where('tenant_id', $tenant->id)->where('partner_apartment_id', $request->partner_apartment_id)
            ->where('status', '!=', 'archived')->orderByRaw("CASE WHEN status = 'published' THEN 0 ELSE 1 END")->first(['id', 'slug', 'status', 'title']);
    }

    public function respond(object $request, string $response, ?float $nightlyPrice, ?string $note): void
    {
        DB::table('partner_availability_requests')->where('id', $request->id)->update([
            'response' => $response, 'nightly_price' => $response === 'available' ? $nightlyPrice : null,
            'response_note' => filled($note) ? mb_substr(trim($note), 0, 500) : null, 'responded_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * Latest request per partner apartment for these dates (or any dates when none are given), for the staff list.
     *
     * @param  list<int>  $partnerIds
     * @return Collection<int, object>
     */
    public function latestFor(Tenant $tenant, array $partnerIds, ?string $checkIn, ?string $checkOut): Collection
    {
        if ($partnerIds === []) {
            return collect();
        }

        return DB::table('partner_availability_requests')->where('tenant_id', $tenant->id)->whereIn('partner_apartment_id', $partnerIds)
            ->when($checkIn, fn ($query) => $query->where('check_in', $checkIn))->when($checkOut, fn ($query) => $query->where('check_out', $checkOut))
            ->where('created_at', '>=', now()->subDays(self::VALID_DAYS))->orderByDesc('id')->get()
            ->unique('partner_apartment_id')->keyBy('partner_apartment_id');
    }

    private function url(object $request): ?string
    {
        try {
            return $this->linkFor(Crypt::decryptString($request->code_encrypted));
        } catch (DecryptException) {
            return null;
        }
    }

    private function linkFor(string $code): string
    {
        return rtrim(config('app.url'), '/').route('partner-request.show', ['code' => $code], false);
    }
}
