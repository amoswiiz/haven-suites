<?php

namespace App\Services;

use App\Models\Tenant;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

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

        return ['unit' => $unit, 'listing' => $listing, 'photos' => $listing
            ? DB::table('public_listing_photos')->where('tenant_id', $tenant->id)->where('listing_id', $listing->id)
                ->orderBy('sort_order')->orderBy('id')->limit(8)->get(['id', 'caption'])
            : collect()];
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
