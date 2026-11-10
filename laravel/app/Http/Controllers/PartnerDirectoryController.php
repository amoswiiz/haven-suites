<?php

namespace App\Http\Controllers;

use App\Services\PartnerAvailabilityRequests;
use App\Services\PublicListingCatalog;
use App\Services\PublicWebsiteLinks;
use App\Support\CompanyModules;
use App\Support\ImageResizer;
use App\Support\PhoneNumber;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Directory of partner apartments and their owners, for finding a unit that matches a client's request and asking
 * the owner about availability on WhatsApp.
 */
class PartnerDirectoryController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'q' => 'nullable|string|max:100', 'area' => 'nullable|string|max:150', 'bedrooms' => 'nullable|in:0,1,2,3,4,4+',
            'status' => 'nullable|in:active,inactive,all', 'check_in' => 'nullable|date_format:Y-m-d',
            'check_out' => 'nullable|date_format:Y-m-d|after:check_in', 'guests' => 'nullable|integer|min:1|max:100',
        ]);
        $tenant = app('currentTenant');
        $db = DB::connection('tenant');
        $status = $filters['status'] ?? 'active';
        $partners = $db->table('partner_apartments')
            ->when($status !== 'all', fn ($query) => $query->where('status', $status))
            ->when(filled($filters['area'] ?? null), fn ($query) => $query->where('location', 'like', '%'.addcslashes($filters['area'], '%_\\').'%'))
            ->when(isset($filters['bedrooms']), fn ($query) => $filters['bedrooms'] === '4+'
                ? $query->where('bedrooms', '>=', 4) : $query->where('bedrooms', (int) $filters['bedrooms']))
            ->when(filled($filters['q'] ?? null), function ($query) use ($filters): void {
                $term = '%'.addcslashes($filters['q'], '%_\\').'%';
                $query->where(fn ($inner) => $inner->where('owner_name', 'like', $term)->orWhere('apartment_name', 'like', $term)
                    ->orWhere('building_name', 'like', $term)->orWhere('unit_number', 'like', $term)->orWhere('location', 'like', $term));
            })
            ->orderBy('location')->orderBy('apartment_name')->orderBy('unit_number')->limit(300)->get();

        $booked = $this->bookedPartnerIds($partners->pluck('id')->all(), $filters['check_in'] ?? null, $filters['check_out'] ?? null);
        $listings = DB::table('public_listings')->where('tenant_id', $tenant->id)->whereIn('partner_apartment_id', $partners->pluck('id'))
            ->where('status', '!=', 'archived')->get(['id', 'partner_apartment_id', 'slug', 'status'])->keyBy('partner_apartment_id');
        $stay = array_filter(['check_in' => $filters['check_in'] ?? null, 'check_out' => $filters['check_out'] ?? null, 'guests' => $filters['guests'] ?? null]);
        $links = app(PublicWebsiteLinks::class);
        $latest = app(PartnerAvailabilityRequests::class)->latestFor($tenant, $partners->pluck('id')->map(fn ($id): int => (int) $id)->all(), $filters['check_in'] ?? null, $filters['check_out'] ?? null);
        $withPhotos = app(PartnerAvailabilityRequests::class)->partnersWithPhotos($tenant, $partners->pluck('id')->map(fn ($id): int => (int) $id)->all());
        $bestLinks = app(PartnerAvailabilityRequests::class)->listingLinks($tenant, $partners->pluck('id')->map(fn ($id): int => (int) $id)->all());
        $rows = $partners->map(function (object $partner) use ($booked, $listings, $stay, $links, $tenant, $latest, $withPhotos, $bestLinks): array {
            $mobile = PhoneNumber::normalize($partner->owner_phone);
            $label = $this->label($partner);
            $listing = $listings->get($partner->id);
            $link = $bestLinks[(int) $partner->id] ?? null;
            $owner = ['name' => $partner->owner_name, 'whatsapp' => $mobile ? substr($mobile, 1) : null];

            return [
                'partner' => $partner, 'label' => $label, 'tel' => PhoneNumber::normalize($partner->owner_phone, mobileOnly: false),
                'whatsapp_url' => $links->ownerWhatsappUrl($owner, $tenant, $label, $stay, null, $link),
                'whatsapp_number' => $owner['whatsapp'], 'greeting' => $links->ownerGreeting($owner, $tenant, $label), 'link' => $link,
                'booked' => in_array((int) $partner->id, $booked, true), 'listing' => $listing, 'latest' => $latest->get($partner->id), 'has_photo' => in_array((int) $partner->id, $withPhotos, true),
            ];
        });
        $agents = CompanyModules::enabled('agents') && auth()->user()->can('agents.view')
            ? DB::table('sales_agents')->where('tenant_id', $tenant->id)->where('active', true)->orderBy('name')->get()
                ->map(function (object $agent) use ($links, $tenant, $stay, $filters): array {
                    $mobile = PhoneNumber::normalize($agent->phone);
                    $owner = ['name' => $agent->name, 'whatsapp' => $mobile ? substr($mobile, 1) : null];
                    $question = $this->requestQuestion($filters);

                    return ['agent' => $agent, 'tel' => PhoneNumber::normalize($agent->phone, mobileOnly: false),
                        'whatsapp_url' => $links->ownerWhatsappUrl($owner, $tenant, '', $stay, $question),
                        'whatsapp_number' => $owner['whatsapp'], 'greeting' => $links->ownerGreeting($owner, $tenant, '', $question), 'link' => null];
                })
            : collect();

        return view('partners.index', [
            'rows' => $rows, 'agents' => $agents, 'filters' => $filters + ['status' => $status],
            'areas' => $db->table('partner_apartments')->whereNotNull('location')->distinct()->orderBy('location')->pluck('location'),
            'canEdit' => auth()->user()->can('reservations.manage'),
        ]);
    }

    /**
     * Create (or reuse) a private availability request for the owner and open WhatsApp with it, the stay details,
     * the listing link when published, and the one-tap reply link.
     */
    public function ask(string $tenant, int $id, Request $request): RedirectResponse
    {
        $stay = array_filter($request->validate(['check_in' => 'nullable|date_format:Y-m-d', 'check_out' => 'nullable|date_format:Y-m-d|after:check_in',
            'guests' => 'nullable|integer|min:1|max:100']));
        $company = app('currentTenant');
        $partner = DB::connection('tenant')->table('partner_apartments')->where('id', $id)->firstOrFail();
        $mobile = PhoneNumber::normalize($partner->owner_phone);
        if (! $mobile) {
            return back()->withErrors(['partner' => 'This owner has no WhatsApp number. Add a mobile number to the partner apartment first.']);
        }
        $label = $this->label($partner);
        $availability = app(PartnerAvailabilityRequests::class)->issue($company, $partner, $label, $stay, auth()->id());
        $links = app(PublicWebsiteLinks::class);
        $where = filled($partner->location) ? $label.' in '.$partner->location : $label;
        $message = implode("\n", [
            $links->ownerGreeting(['name' => $partner->owner_name], $company, $where),
            'See the unit and reply: '.$availability['url'],
            ...PublicWebsiteLinks::ownerRequestLines($stay, app(PartnerAvailabilityRequests::class)->listingLinks($company, [(int) $partner->id])[(int) $partner->id] ?? null),
        ]);

        return redirect()->away('https://wa.me/'.substr($mobile, 1).'?text='.rawurlencode($message));
    }

    public function create(): View
    {
        return view('partners.form', ['partner' => null]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $photo = $request->hasFile('photo') ? ImageResizer::toJpeg($request->file('photo'), 'photo') : null;
        $id = DB::connection('tenant')->table('partner_apartments')->insertGetId($data + ['created_at' => now(), 'updated_at' => now()]);
        $this->savePhoto($id, $request, $photo);
        app(PartnerAvailabilityRequests::class)->saveExternalLink(app('currentTenant'), (int) $id, $request->input('listing_url'));

        return redirect()->route('partners.index', app('currentTenant')->slug)->with('success', 'Partner apartment added: '.$this->label((object) ($data + ['id' => $id])).'.');
    }

    public function edit(string $tenant, int $id): View
    {
        $partner = DB::connection('tenant')->table('partner_apartments')->where('id', $id)->firstOrFail();
        $photo = DB::table('partner_apartment_photos')->where('tenant_id', app('currentTenant')->id)->where('partner_apartment_id', $id)->first();

        $tenantModel = app('currentTenant');
        $listing = DB::table('public_listings')->where('tenant_id', $tenantModel->id)->where('partner_apartment_id', $id)->where('status', '!=', 'archived')
            ->orderByRaw("CASE WHEN status = 'published' THEN 0 ELSE 1 END")->first(['id', 'slug', 'title', 'status']);

        return view('partners.form', ['partner' => $partner, 'hasPhoto' => $photo !== null, 'photoVersion' => $photo ? strtotime((string) $photo->updated_at) : null,
            'appListing' => $listing, 'siteEnabled' => app(PublicListingCatalog::class)->site($tenantModel) !== null,
            'listingUrl' => app(PartnerAvailabilityRequests::class)->externalLink($tenantModel, $id), 'canManageListings' => auth()->user()->can('properties.manage')]);
    }

    public function update(string $tenant, int $id, Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $photo = $request->hasFile('photo') ? ImageResizer::toJpeg($request->file('photo'), 'photo') : null;
        $updated = DB::connection('tenant')->table('partner_apartments')->where('id', $id)->update($data + ['updated_at' => now()]);
        abort_if($updated === 0 && ! DB::connection('tenant')->table('partner_apartments')->where('id', $id)->exists(), 404);
        $this->savePhoto($id, $request, $photo);
        app(PartnerAvailabilityRequests::class)->saveExternalLink(app('currentTenant'), (int) $id, $request->input('listing_url'));

        return redirect()->route('partners.index', $tenant)->with('success', 'Partner apartment updated.');
    }

    /** The partner apartment's featured photo, for staff. */
    public function photo(string $tenant, int $id): BinaryFileResponse
    {
        $path = app(PartnerAvailabilityRequests::class)->featuredPhotoPath(app('currentTenant'), $id);
        abort_unless($path, 404);

        return response()->file(Storage::disk('local')->path($path), ['Content-Type' => 'image/jpeg', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, max-age=600']);
    }

    /** Store, replace or remove the featured photo after the partner apartment is saved. */
    private function savePhoto(int $partnerId, Request $request, ?string $photo): void
    {
        $tenant = app('currentTenant');
        $current = DB::table('partner_apartment_photos')->where('tenant_id', $tenant->id)->where('partner_apartment_id', $partnerId)->first();
        if ($photo !== null) {
            $path = 'partner-photos/'.$tenant->id.'/'.$partnerId.'-'.Str::random(24).'.jpg';
            Storage::disk('local')->put($path, $photo);
            DB::table('partner_apartment_photos')->updateOrInsert(['tenant_id' => $tenant->id, 'partner_apartment_id' => $partnerId],
                ['storage_key' => $path, 'updated_at' => now(), 'created_at' => $current->created_at ?? now()]);
        } elseif ($request->boolean('remove_photo') && $current) {
            DB::table('partner_apartment_photos')->where('id', $current->id)->delete();
        } else {
            return;
        }
        if ($current && str_starts_with($current->storage_key, 'partner-photos/'.$tenant->id.'/')) {
            Storage::disk('local')->delete($current->storage_key);
        }
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'owner_name' => 'required|string|max:150', 'owner_phone' => 'nullable|string|max:40', 'owner_email' => 'nullable|email|max:150',
            'apartment_name' => 'required|string|max:150', 'building_name' => 'nullable|string|max:150', 'unit_number' => 'nullable|string|max:50',
            'location' => 'required|string|max:150', 'bedrooms' => 'required|integer|min:0|max:20', 'bathrooms' => 'nullable|integer|min:0|max:20',
            'notes' => 'nullable|string|max:5000', 'status' => 'required|in:active,inactive',
            'listing_url' => ['nullable', 'string', 'max:500', 'url:https'],
            'photo' => 'nullable|file|mimes:jpg,jpeg,png,webp|max:8192', 'remove_photo' => 'sometimes|boolean',
        ], ['photo.mimes' => 'Upload the photo as a JPG, PNG or WebP image.', 'photo.max' => 'Keep the photo under 8 MB.']);
        unset($data['photo'], $data['remove_photo'], $data['listing_url']);
        if (PhoneNumber::isBlank($data['owner_phone'] ?? null)) {
            $data['owner_phone'] = null;
        } elseif (! ($data['owner_phone'] = PhoneNumber::normalize($data['owner_phone'], mobileOnly: false))) {
            throw ValidationException::withMessages(['owner_phone' => 'Enter a valid phone number, such as 0712 345 678 or +254 712 345 678.']);
        }
        $data['owner_email'] = filled($data['owner_email'] ?? null) ? strtolower($data['owner_email']) : null;

        return $data;
    }

    /** "Amalia Apartments, Block A, A1.9 (2BR)" */
    private function label(object $partner): string
    {
        $bedrooms = (int) $partner->bedrooms === 0 ? 'studio' : (int) $partner->bedrooms.'BR';

        $parts = [];
        foreach ([$partner->apartment_name, $partner->building_name ?? null, $partner->unit_number ?? null] as $part) {
            $part = trim((string) $part);
            if ($part !== '' && ! in_array(mb_strtolower($part), array_map('mb_strtolower', $parts), true)) {
                $parts[] = $part;
            }
        }

        return implode(', ', $parts).' ('.$bedrooms.')';
    }

    /** The client's request as a question for agents, e.g. "Do you have a 2BR in Diani available?". */
    private function requestQuestion(array $filters): string
    {
        $size = match ($filters['bedrooms'] ?? null) {
            null => 'an apartment', '0' => 'a studio', '4+' => 'a 4-bedroom or larger home', default => 'a '.$filters['bedrooms'].'BR',
        };

        return 'Do you have '.$size.(filled($filters['area'] ?? null) ? ' in '.$filters['area'] : '').' available?';
    }

    /**
     * Partner apartments with a confirmed or checked-in booking overlapping the dates.
     *
     * @param  list<int>  $ids
     * @return list<int>
     */
    private function bookedPartnerIds(array $ids, ?string $checkIn, ?string $checkOut): array
    {
        if ($ids === [] || ! $checkIn || ! $checkOut || ! CarbonImmutable::hasFormat($checkIn, 'Y-m-d')) {
            return [];
        }

        return DB::connection('tenant')->table('reservations')->whereIn('partner_apartment_id', $ids)
            ->whereIn('status', ['confirmed', 'checked_in'])->where('check_in', '<', $checkOut)->where('check_out', '>', $checkIn)
            ->distinct()->pluck('partner_apartment_id')->map(fn ($id): int => (int) $id)->all();
    }
}
