<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Services\PublicEnquiries;
use App\Services\PublicListingCatalog;
use App\Services\UnitAvailability;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PublicStayController extends Controller
{
    public function index(Request $request, string $company, PublicListingCatalog $catalog, UnitAvailability $availability): Response
    {
        [$tenant, $site] = $this->company($company, $catalog);
        $today = CarbonImmutable::now($tenant->timezone)->toDateString();
        $filters = $request->validate([
            'q' => 'nullable|string|max:100', 'city' => 'nullable|string|max:100',
            'guests' => 'nullable|integer|min:1|max:100', 'bedrooms' => 'nullable|integer|min:0|max:20',
            'min_price' => 'nullable|numeric|min:0|max:999999999', 'max_price' => 'nullable|numeric|min:0|max:999999999',
            'amenities' => 'nullable|array|max:14', 'amenities.*' => [Rule::in(array_keys(PublicListingCatalog::AMENITIES))],
            'check_in' => ['nullable', 'required_with:check_out', 'date_format:Y-m-d', 'after_or_equal:'.$today, 'before_or_equal:'.CarbonImmutable::parse($today)->addYears(2)->toDateString()],
            'check_out' => ['nullable', 'required_with:check_in', 'date_format:Y-m-d', 'after:check_in', 'before_or_equal:'.CarbonImmutable::parse($today)->addYears(2)->toDateString()],
            'available_only' => 'nullable|boolean', 'sort' => ['nullable', Rule::in(['recommended', 'price_asc', 'price_desc'])],
        ]);
        if (isset($filters['min_price'], $filters['max_price']) && (float) $filters['max_price'] < (float) $filters['min_price']) {
            throw ValidationException::withMessages(['max_price' => 'The maximum price must be at least the minimum price.']);
        }
        $listings = $catalog->published($tenant);
        $hero = $listings->first();
        $cities = $listings->pluck('city')->unique()->sort()->values();
        $nights = ! empty($filters['check_in']) && ! empty($filters['check_out'])
            ? (int) CarbonImmutable::parse($filters['check_in'])->diffInDays(CarbonImmutable::parse($filters['check_out'])) : null;
        if ($nights !== null && $nights > 90) {
            throw ValidationException::withMessages(['check_out' => 'Search up to 90 nights at a time. Contact our team for a longer stay.']);
        }
        $listings = $listings->filter(function (array $listing) use ($filters, $nights): bool {
            $query = mb_strtolower(trim($filters['q'] ?? ''));

            return ($query === '' || str_contains(mb_strtolower($listing['title'].' '.$listing['city'].' '.$listing['neighbourhood']), $query))
                && (empty($filters['city']) || $listing['city'] === $filters['city'])
                && (empty($filters['guests']) || $listing['max_guests'] >= (int) $filters['guests'])
                && ((! isset($filters['min_price']) && ! isset($filters['max_price'])) || (float) $listing['starting_price'] > 0)
                && (! isset($filters['bedrooms']) || $filters['bedrooms'] === null || $listing['bedrooms'] === (int) $filters['bedrooms'])
                && (! isset($filters['min_price']) || $filters['min_price'] === null || (float) $listing['starting_price'] >= (float) $filters['min_price'])
                && (! isset($filters['max_price']) || $filters['max_price'] === null || (float) $listing['starting_price'] <= (float) $filters['max_price'])
                && ! array_diff($filters['amenities'] ?? [], $listing['amenities'])
                && ($nights === null || $nights >= $listing['min_nights']);
        })->values();
        $states = [];
        if ($nights !== null && $listings->isNotEmpty()) {
            foreach ($listings->pluck('unit_id')->chunk(500) as $unitIds) {
                $states += $availability->availabilityForUnits($tenant, $unitIds->all(), $filters['check_in'], $filters['check_out']);
            }
        }
        $listings = $listings->map(function (array $listing) use ($states): array {
            $listing['availability'] = $states[$listing['unit_id']] ?? null;

            return $listing;
        })->filter(fn (array $listing): bool => empty($filters['available_only']) || $nights === null
            || (($listing['availability']['available'] ?? false) && ($listing['availability']['fresh'] ?? false)))->values();
        if (($filters['sort'] ?? '') === 'price_asc') {
            $listings = $listings->sortBy(fn (array $listing): float => (float) $listing['starting_price'])->values();
        } elseif (($filters['sort'] ?? '') === 'price_desc') {
            $listings = $listings->sortByDesc(fn (array $listing): float => (float) $listing['starting_price'])->values();
        }
        $page = LengthAwarePaginator::resolveCurrentPage();
        $stays = new LengthAwarePaginator($listings->forPage($page, 12)->values(), $listings->count(), 12, $page,
            ['path' => route('public-stays.index', $tenant->slug), 'query' => $request->query()]);

        return $this->page('public_stays.index', compact('tenant', 'site', 'stays', 'cities', 'filters', 'hero') + [
            'amenities' => PublicListingCatalog::AMENITIES, 'today' => $today,
            'pageTitle' => $tenant->name.' | Find your next stay',
            'pageDescription' => $site->tagline ?: 'Discover comfortable stays, explore availability and enquire directly with '.$tenant->name.'.',
            'canonicalUrl' => route('public-stays.index', $tenant->slug),
        ]);
    }

    public function show(Request $request, string $company, string $slug, PublicListingCatalog $catalog, UnitAvailability $availability, PublicEnquiries $enquiries): Response
    {
        [$tenant, $site] = $this->company($company, $catalog);
        $listing = $catalog->findPublished($tenant, $slug);

        return $this->listingPage($request, $tenant, $site, $listing, $availability, $enquiries);
    }

    public function preview(Request $request, string $tenant, int $id, PublicListingCatalog $catalog, UnitAvailability $availability, PublicEnquiries $enquiries): Response
    {
        abort_unless(auth()->user()?->active && auth()->user()->can('properties.manage'), 403);
        $company = app('currentTenant');
        $site = $catalog->settings($company) ?? (object) ['headline' => null, 'tagline' => null, 'contact_email' => null, 'contact_phone' => null];

        return $this->listingPage($request, $company, $site, $catalog->preview($company, $id), $availability, $enquiries, true);
    }

    public function calendar(Request $request, string $company, string $slug, PublicListingCatalog $catalog, UnitAvailability $availability): JsonResponse
    {
        [$tenant] = $this->company($company, $catalog);
        $listing = $catalog->findPublished($tenant, $slug);
        $data = $request->validate(['from' => 'required|date_format:Y-m-d', 'to' => 'required|date_format:Y-m-d|after:from']);

        return response()->json($availability->calendar($tenant, $listing['unit_id'], $data['from'], $data['to']))
            ->header('Cache-Control', 'private, no-store')->header('X-Content-Type-Options', 'nosniff');
    }

    public function photo(string $company, string $slug, int $photo, PublicListingCatalog $catalog): BinaryFileResponse
    {
        [$tenant] = $this->company($company, $catalog);
        $image = $catalog->image($tenant, $slug, $photo);

        return response()->file($image['path'], ['Content-Type' => $image['mime_type'], 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'public, max-age=60']);
    }

    public function sitemap(string $company, PublicListingCatalog $catalog): Response
    {
        [$tenant] = $this->company($company, $catalog);
        $urls = [route('public-stays.index', $tenant->slug)];
        foreach ($catalog->published($tenant) as $listing) {
            $urls[] = route('public-stays.show', [$tenant->slug, $listing['slug']]);
        }
        $xml = '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
        foreach ($urls as $url) {
            $xml .= '<url><loc>'.htmlspecialchars($url, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</loc></url>';
        }

        return response($xml.'</urlset>', 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }

    private function company(string $slug, PublicListingCatalog $catalog): array
    {
        $tenant = Tenant::where('slug', $slug)->where('active', true)->firstOrFail();
        $site = $catalog->site($tenant);
        abort_unless($site, 404);

        return [$tenant, $site];
    }

    private function listingPage(Request $request, Tenant $tenant, object $site, array $listing, UnitAvailability $availability, PublicEnquiries $enquiries, bool $preview = false): Response
    {
        $today = CarbonImmutable::now($tenant->timezone)->startOfDay();
        $month = $today->startOfMonth();
        $calendar = $availability->calendar($tenant, $listing['unit_id'], $month->toDateString(), $month->addMonth()->toDateString());
        $days = [];
        for ($date = $month; $date < $month->addMonth(); $date = $date->addDay()) {
            $blocked = collect($calendar['blocked'])->contains(fn (array $range): bool => $date->toDateString() >= $range['start'] && $date->toDateString() < $range['end']);
            $days[] = ['date' => $date->toDateString(), 'day' => $date->day, 'past' => $date < $today, 'blocked' => $blocked];
        }
        $cells = array_merge(array_fill(0, $month->dayOfWeekIso - 1, null), $days);
        while (count($cells) % 7 !== 0) {
            $cells[] = null;
        }
        $weeks = array_chunk($cells, 7);
        $canEnquire = ! $preview && ($tenant->modules['sales'] ?? true) !== false;
        $formContext = $canEnquire ? $enquiries->formContext($tenant, $listing) : [];
        $alternatives = $preview ? collect() : app(PublicListingCatalog::class)->published($tenant)
            ->filter(fn (array $other): bool => $other['id'] !== $listing['id'] && $other['city'] === $listing['city'])->take(3);
        $canonicalUrl = route('public-stays.show', [$tenant->slug, $listing['slug']]);
        $structuredData = [
            '@context' => 'https://schema.org', '@type' => 'LodgingBusiness', 'name' => $listing['title'],
            'description' => Str::limit($listing['description'], 400), 'url' => $canonicalUrl,
            'image' => array_column($listing['photos'], 'url'),
            'address' => ['@type' => 'PostalAddress', 'addressLocality' => $listing['city']],
        ];

        return $this->page('public_stays.show', compact('tenant', 'site', 'listing', 'calendar', 'month', 'weeks', 'canEnquire', 'formContext', 'alternatives', 'preview', 'canonicalUrl', 'structuredData') + [
            'today' => $today->toDateString(), 'pageTitle' => $listing['title'].' | '.$tenant->name,
            'pageDescription' => Str::limit($listing['description'], 155),
        ], $preview);
    }

    private function page(string $view, array $data, bool $preview = false): Response
    {
        $response = response()->view($view, $data)->header('Cache-Control', 'private, no-store')
            ->header('Referrer-Policy', 'strict-origin-when-cross-origin')->header('X-Content-Type-Options', 'nosniff');
        if ($preview) {
            $response->header('X-Robots-Tag', 'noindex, nofollow');
        }

        return $response;
    }
}
