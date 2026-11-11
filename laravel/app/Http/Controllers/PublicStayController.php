<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Services\ListingAvailability;
use App\Services\PublicAboutPage;
use App\Services\PublicAreaGuides;
use App\Services\PublicBlog;
use App\Services\PublicEnquiries;
use App\Services\PublicGuestInfo;
use App\Services\PublicListingCatalog;
use App\Services\PublicReviews;
use App\Services\PublicWebsiteLinks;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMXPath;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PublicStayController extends Controller
{
    public function index(Request $request, string $company, PublicListingCatalog $catalog, ListingAvailability $availability): Response
    {
        [$tenant, $site] = $this->company($company, $catalog);
        $today = CarbonImmutable::now($tenant->timezone)->toDateString();
        $filters = $request->validate([
            'q' => 'nullable|string|max:100', 'city' => 'nullable|string|max:100', 'area' => 'nullable|string|max:150',
            'type' => ['nullable', Rule::in(array_keys(PublicListingCatalog::PROPERTY_TYPES))],
            'collection' => ['nullable', Rule::in(array_keys(PublicListingCatalog::COLLECTIONS))],
            'guests' => 'nullable|integer|min:1|max:100', 'bedrooms' => 'nullable|integer|min:0|max:20',
            'min_price' => 'nullable|numeric|min:0|max:999999999', 'max_price' => 'nullable|numeric|min:0|max:999999999',
            'amenities' => 'nullable|array|max:30', 'amenities.*' => [Rule::in(array_keys(PublicListingCatalog::AMENITIES))],
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
        $destinations = PublicListingCatalog::groupDestinations($listings);
        $propertyTypes = array_intersect_key(PublicListingCatalog::PROPERTY_TYPES, array_flip($listings->pluck('property_type')->filter()->unique()->all()));
        $nights = ! empty($filters['check_in']) && ! empty($filters['check_out'])
            ? (int) CarbonImmutable::parse($filters['check_in'])->diffInDays(CarbonImmutable::parse($filters['check_out'])) : null;
        if ($nights !== null && $nights > 90) {
            throw ValidationException::withMessages(['check_out' => 'Search up to 90 nights at a time. Contact our team for a longer stay.']);
        }
        $matchesFilters = function (array $listing, array $filters) use ($nights): bool {
            $words = preg_split('/\s+/u', mb_strtolower(trim($filters['q'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $searchable = $words === [] ? '' : mb_strtolower(implode(' ', [$listing['title'], $listing['city'], $listing['neighbourhood'] ?? '',
                $listing['listing_code'] ?? '', $listing['description'] ?? '', $listing['property_type_label'] ?? '',
                implode(' ', $listing['amenity_labels'] ?? []), implode(' ', $listing['collection_labels'] ?? [])]));

            return collect($words)->every(fn (string $word): bool => str_contains($searchable, $word))
                && (empty($filters['city']) || $listing['city'] === $filters['city'])
                && (empty($filters['area']) || $listing['neighbourhood'] === $filters['area'])
                && (empty($filters['type']) || ($listing['property_type'] ?? null) === $filters['type'])
                && (empty($filters['collection']) || in_array($filters['collection'], $listing['collections'] ?? [], true))
                && (empty($filters['guests']) || $listing['max_guests'] >= (int) $filters['guests'])
                && ((! isset($filters['min_price']) && ! isset($filters['max_price'])) || (float) $listing['starting_price'] > 0)
                && (! isset($filters['bedrooms']) || $filters['bedrooms'] === null || ($listing['bedrooms'] === (int) $filters['bedrooms'] || ((int) $filters['bedrooms'] >= 6 && $listing['bedrooms'] !== null && $listing['bedrooms'] >= 6)))
                && (! isset($filters['min_price']) || $filters['min_price'] === null || (float) $listing['starting_price'] >= (float) $filters['min_price'])
                && (! isset($filters['max_price']) || $filters['max_price'] === null || (float) $listing['starting_price'] <= (float) $filters['max_price'])
                && ! array_diff($filters['amenities'] ?? [], $listing['amenities'])
                && ($nights === null || $nights >= $listing['min_nights']);
        };
        // Collection counts reflect every other active filter, so a chip never promises more stays than it shows.
        $withoutCollection = array_diff_key($filters, ['collection' => true]);
        $collectionCounts = collect(PublicListingCatalog::COLLECTIONS)->map(fn (string $label, string $key): array => ['label' => $label,
            'count' => $listings->filter(fn (array $listing): bool => in_array($key, $listing['collections'] ?? [], true) && $matchesFilters($listing, $withoutCollection))->count()])
            ->filter(fn (array $collection, string $key): bool => $collection['count'] > 0 || ($filters['collection'] ?? null) === $key)->all();
        $listings = $listings->filter(fn (array $listing): bool => $matchesFilters($listing, $filters))->values();
        $states = [];
        if ($nights !== null && $listings->isNotEmpty()) {
            foreach ($listings->chunk(500) as $batch) {
                $states += $availability->availabilityForListings($tenant, $batch->all(), $filters['check_in'], $filters['check_out']);
            }
        }
        $ratings = app(PublicReviews::class)->summaries($tenant);
        $listings = $listings->map(function (array $listing) use ($states, $tenant, $site, $request, $ratings): array {
            $listing['availability'] = $states[$listing['id']] ?? null;
            $listing['rating'] = $ratings[$listing['id']] ?? null;
            $listing['links'] = $this->listingLinks($tenant, $listing, $site, $request);

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
        abort_if($page > $stays->lastPage(), 404);
        $filtered = collect($filters)->contains(fn ($value, $key): bool => match ($key) {
            'sort' => ! in_array($value, [null, '', 'recommended'], true),
            'available_only' => (bool) $value,
            default => ! in_array($value, [null, '', []], true),
        });
        $canonicalUrl = $this->publicUrl('public-stays.index', ['company' => $tenant->slug], ! $filtered && $page > 1 ? ['page' => $page] : []);
        $published = $catalog->published($tenant);
        $highlights = $filtered || $page > 1 ? collect() : app(PublicReviews::class)->highlights($tenant, $published->pluck('id')->all());

        return $this->page('public_stays.index', compact('tenant', 'site', 'stays', 'cities', 'filters', 'hero', 'destinations', 'propertyTypes', 'collectionCounts') + [
            'amenities' => PublicListingCatalog::AMENITIES, 'today' => $today,
            'guestInfo' => app(PublicGuestInfo::class)->content($tenant, $site), 'reviewHighlights' => $highlights,
            'listingTitles' => $published->mapWithKeys(fn (array $listing): array => [$listing['id'] => ['title' => $listing['title'], 'slug' => $listing['slug']]]),
            'pageTitle' => ($this->plainText($site->seo_title ?? '', 180) ?: $this->plainText($tenant->name, 100).' | Find your next stay').($page > 1 ? ' | Page '.$page : ''),
            'pageDescription' => $this->plainText($site->seo_description ?? '', 320) ?: $this->plainText($site->tagline ?: 'Discover comfortable stays, explore availability and enquire directly with '.$tenant->name.'.', 160),
            'canonicalUrl' => $canonicalUrl, 'robotsContent' => $filtered ? 'noindex, follow' : 'index, follow, max-image-preview:large',
            'socialImage' => $this->socialImage($hero['photos'][0] ?? null),
        ]);
    }

    public function show(Request $request, string $company, string $slug, PublicListingCatalog $catalog, ListingAvailability $availability, PublicEnquiries $enquiries): Response
    {
        [$tenant, $site] = $this->company($company, $catalog);
        $listing = $catalog->findPublished($tenant, $slug);

        return $this->listingPage($request, $tenant, $site, $listing, $availability, $enquiries);
    }

    public function preview(Request $request, string $tenant, int $id, PublicListingCatalog $catalog, ListingAvailability $availability, PublicEnquiries $enquiries): Response
    {
        abort_unless(auth()->user()?->active && auth()->user()->can('properties.manage'), 403);
        $company = app('currentTenant');
        $site = $catalog->settings($company) ?? (object) ['headline' => null, 'tagline' => null, 'contact_email' => null, 'contact_phone' => null];

        return $this->listingPage($request, $company, $site, $catalog->preview($company, $id), $availability, $enquiries, true);
    }

    public function calendar(Request $request, string $company, string $slug, PublicListingCatalog $catalog, ListingAvailability $availability): JsonResponse
    {
        [$tenant] = $this->company($company, $catalog);
        $listing = $catalog->findPublished($tenant, $slug);
        $data = $request->validate(['from' => 'required|date_format:Y-m-d', 'to' => 'required|date_format:Y-m-d|after:from']);

        return response()->json($availability->calendar($tenant, $listing, $data['from'], $data['to']))
            ->header('Cache-Control', 'private, no-store')->header('X-Content-Type-Options', 'nosniff');
    }

    public function photo(string $company, string $slug, int $photo, PublicListingCatalog $catalog): BinaryFileResponse
    {
        [$tenant] = $this->company($company, $catalog);
        $image = $catalog->image($tenant, $slug, $photo);

        return response()->file($image['path'], ['Content-Type' => $image['mime_type'], 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'public, max-age=60']);
    }

    public function about(string $company, PublicListingCatalog $catalog, PublicAboutPage $about): Response
    {
        [$tenant, $site] = $this->company($company, $catalog);
        $destinations = PublicListingCatalog::groupDestinations($catalog->published($tenant));
        $content = $about->content($tenant, $site, $destinations);
        $canonicalUrl = $this->publicUrl('public-stays.about', ['company' => $tenant->slug]);
        $areas = array_merge(array_column($content['main_locations'], 'name'), array_column($content['other_destinations'], 'city'));

        return $this->page('public_stays.about', [
            'tenant' => $tenant, 'site' => $site, 'about' => $content, 'destinations' => $destinations,
            'pageTitle' => 'About us | '.$this->plainText($tenant->name, 100),
            'pageDescription' => $this->plainText('Who we are, how we work and where we are: '.$tenant->name.' arranges serviced apartments and short stays in '
                .implode(' and ', array_column($content['main_locations'], 'name')).', and helps guests find stays in other areas.', 320),
            'canonicalUrl' => $canonicalUrl,
            'structuredData' => array_filter(['@context' => 'https://schema.org', '@type' => 'LodgingBusiness', 'name' => $tenant->name, 'url' => $canonicalUrl,
                'telephone' => $site->contact_phone ?? null, 'email' => $site->contact_email ?? null,
                'areaServed' => array_map(fn (string $area): array => ['@type' => 'City', 'name' => $area], array_values(array_unique($areas)))]),
        ]);
    }

    public function faq(string $company, PublicListingCatalog $catalog): Response
    {
        [$tenant, $site] = $this->company($company, $catalog);
        $info = app(PublicGuestInfo::class)->content($tenant, $site);

        return $this->page('public_stays.faq', [
            'tenant' => $tenant, 'site' => $site, 'info' => $info,
            'pageTitle' => 'Questions and policies | '.$this->plainText($tenant->name, 100),
            'pageDescription' => $this->plainText('Answers to common questions about booking, paying, check-in and cancellations with '.$tenant->name.'.', 320),
            'canonicalUrl' => $this->publicUrl('public-stays.faq', ['company' => $tenant->slug]),
            'structuredData' => ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => array_map(fn (array $item): array => [
                '@type' => 'Question', 'name' => $this->plainText($item['question'], 300),
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => $this->plainText($item['answer'], 1500)],
            ], $info['faq'])],
        ]);
    }

    public function blog(Request $request, string $company, PublicListingCatalog $catalog, PublicBlog $blog): Response
    {
        [$tenant, $site] = $this->company($company, $catalog);
        $posts = $blog->published($tenant);
        $page = LengthAwarePaginator::resolveCurrentPage();
        $paginator = new LengthAwarePaginator($posts->forPage($page, 9)->values(), $posts->count(), 9, $page, ['path' => route('public-stays.blog', $tenant->slug)]);
        abort_if($page > max(1, $paginator->lastPage()), 404);

        return $this->page('public_stays.blog', [
            'tenant' => $tenant, 'site' => $site, 'posts' => $paginator,
            'pageTitle' => 'Travel tips and guides | '.$this->plainText($tenant->name, 100).($page > 1 ? ' | Page '.$page : ''),
            'pageDescription' => $this->plainText('Neighbourhood guides, travel tips and news from '.$tenant->name.' to help you plan your stay in Kenya.', 320),
            'canonicalUrl' => $this->publicUrl('public-stays.blog', ['company' => $tenant->slug], $page > 1 ? ['page' => $page] : []),
            'socialImage' => ($first = $posts->first()) && $first->cover_url ? ['url' => $first->cover_url, 'caption' => $first->title] : null,
        ]);
    }

    public function post(string $company, string $slug, PublicListingCatalog $catalog, PublicBlog $blog): Response
    {
        [$tenant, $site] = $this->company($company, $catalog);
        $post = $blog->find($tenant, $slug);
        abort_unless($post, 404);
        $canonicalUrl = $this->publicUrl('public-stays.blog.show', ['company' => $tenant->slug, 'slug' => $post->slug]);
        $related = $blog->published($tenant, 4)->reject(fn (object $other): bool => $other->id === $post->id)->take(3)->values();
        $author = $post->author_id ? DB::table('users')->where('id', $post->author_id)->value('name') : null;

        return $this->page('public_stays.post', [
            'tenant' => $tenant, 'site' => $site, 'post' => $post, 'html' => $blog->html($post->body), 'related' => $related,
            'authorName' => $author ? PublicReviews::displayName($author) : null,
            'pageTitle' => $this->plainText($post->seo_title ?? '', 180) ?: $this->plainText($post->title, 180).' | '.$this->plainText($tenant->name, 100),
            'pageDescription' => $this->plainText($post->seo_description ?? '', 320) ?: $this->plainText($post->summary, 320),
            'canonicalUrl' => $canonicalUrl,
            'socialImage' => $post->cover_url ? ['url' => rtrim(config('app.url'), '/').parse_url($post->cover_url, PHP_URL_PATH), 'caption' => $post->cover_caption ?: $post->title] : null,
            'structuredData' => array_filter(['@context' => 'https://schema.org', '@type' => 'BlogPosting', 'headline' => $this->plainText($post->title, 110),
                'description' => $this->plainText($post->summary, 300), 'url' => $canonicalUrl, 'mainEntityOfPage' => $canonicalUrl,
                'datePublished' => CarbonImmutable::parse($post->published_at)->toAtomString(), 'dateModified' => CarbonImmutable::parse($post->updated_at)->toAtomString(),
                'image' => $post->cover_url ? rtrim(config('app.url'), '/').parse_url($post->cover_url, PHP_URL_PATH) : null,
                'author' => ['@type' => 'Organization', 'name' => $tenant->name], 'publisher' => ['@type' => 'Organization', 'name' => $tenant->name]]),
        ]);
    }

    public function blogCover(string $company, string $slug, PublicListingCatalog $catalog, PublicBlog $blog): BinaryFileResponse
    {
        [$tenant] = $this->company($company, $catalog);
        $post = $blog->find($tenant, $slug);
        $path = $post?->cover_path;
        abort_unless($path && preg_match('#^public-posts/'.(int) $tenant->id.'/[A-Za-z0-9]{32}\.jpg$#D', $path) && Storage::disk('local')->exists($path), 404);

        return response()->file(Storage::disk('local')->path($path), ['Content-Type' => 'image/jpeg', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'public, max-age=86400']);
    }

    public function area(Request $request, string $company, string $city, PublicListingCatalog $catalog, PublicAreaGuides $guides, ?string $area = null): Response
    {
        [$tenant, $site] = $this->company($company, $catalog);
        $published = $catalog->published($tenant);
        $destinations = PublicListingCatalog::groupDestinations($published);
        $place = $guides->resolve($destinations, $city, $area);
        abort_unless($place, 404);
        $ratings = app(PublicReviews::class)->summaries($tenant);
        $stays = $published->filter(fn (array $listing): bool => $listing['city'] === $place['city'] && ($place['area'] === null || $listing['neighbourhood'] === $place['area']))
            ->map(function (array $listing) use ($tenant, $site, $request, $ratings): array {
                $listing['links'] = $this->listingLinks($tenant, $listing, $site, $request);
                $listing['rating'] = $ratings[$listing['id']] ?? null;

                return $listing;
            })->values();
        $name = $place['area'] ? $place['area'].', '.$place['city'] : $place['city'];
        $canonicalUrl = rtrim(config('app.url'), '/').parse_url($guides->url($tenant, $place['city'], $place['area']), PHP_URL_PATH);

        return $this->page('public_stays.area', [
            'tenant' => $tenant, 'site' => $site, 'place' => $place, 'stays' => $stays, 'destinations' => $destinations,
            'guide' => $guides->content($tenant, $place['city'], $place['area'], $stays->count()), 'areaGuides' => $guides,
            'pageTitle' => 'Serviced apartments in '.$this->plainText($name, 150).' | '.$this->plainText($tenant->name, 100),
            'pageDescription' => $this->plainText('Browse '.$stays->count().' short-stay '.($stays->count() === 1 ? 'apartment' : 'apartments').' in '.$name.' with '.$tenant->name.'. See photos, amenities and prices, and enquire for your dates.', 320),
            'canonicalUrl' => $canonicalUrl, 'socialImage' => $this->socialImage($stays->first()['photos'][0] ?? null),
            'structuredData' => ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => array_values(array_filter([
                ['@type' => 'ListItem', 'position' => 1, 'name' => 'Find a stay', 'item' => $this->publicUrl('public-stays.index', ['company' => $tenant->slug])],
                ['@type' => 'ListItem', 'position' => 2, 'name' => $this->plainText($place['city'], 100), 'item' => $this->publicUrl('public-stays.city', ['company' => $tenant->slug, 'city' => $city])],
                $place['area'] ? ['@type' => 'ListItem', 'position' => 3, 'name' => $this->plainText($place['area'], 150), 'item' => $canonicalUrl] : null,
            ]))],
        ]);
    }

    public function logo(string $company, PublicListingCatalog $catalog): BinaryFileResponse
    {
        $tenant = Tenant::where('slug', $company)->where('active', true)->firstOrFail();
        $path = $catalog->settings($tenant)?->logo_path;
        abort_unless($path && str_starts_with($path, 'public-sites/'.$tenant->id.'/') && Storage::disk('local')->exists($path), 404);

        return response()->file(Storage::disk('local')->path($path), ['Content-Type' => 'image/webp', 'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'public, max-age=31536000, immutable']);
    }

    public function sitemap(string $company, PublicListingCatalog $catalog): Response
    {
        [$tenant] = $this->company($company, $catalog);
        $entries = [['url' => $this->publicUrl('public-stays.index', ['company' => $tenant->slug])], ['url' => $this->publicUrl('public-stays.about', ['company' => $tenant->slug])],
            ['url' => $this->publicUrl('public-stays.faq', ['company' => $tenant->slug])]];
        $published = $catalog->published($tenant);
        $guides = app(PublicAreaGuides::class);
        foreach (PublicListingCatalog::groupDestinations($published) as $destination) {
            $entries[] = ['url' => rtrim(config('app.url'), '/').parse_url($guides->url($tenant, $destination['city']), PHP_URL_PATH)];
            foreach ($destination['areas'] as $area) {
                if (Str::slug($area['name']) !== '') {
                    $entries[] = ['url' => rtrim(config('app.url'), '/').parse_url($guides->url($tenant, $destination['city'], $area['name']), PHP_URL_PATH)];
                }
            }
        }
        $posts = app(PublicBlog::class)->published($tenant);
        if ($posts->isNotEmpty()) {
            $entries[] = ['url' => $this->publicUrl('public-stays.blog', ['company' => $tenant->slug])];
            foreach ($posts as $post) {
                $entries[] = ['url' => $this->publicUrl('public-stays.blog.show', ['company' => $tenant->slug, 'slug' => $post->slug]),
                    'lastmod' => CarbonImmutable::parse($post->updated_at, config('app.timezone'))->min(now())->toAtomString(),
                    'photos' => $post->cover_url ? [rtrim(config('app.url'), '/').parse_url($post->cover_url, PHP_URL_PATH)] : []];
            }
        }
        foreach ($published as $listing) {
            $entry = ['url' => $this->publicUrl('public-stays.show', ['company' => $tenant->slug, 'slug' => $listing['slug']]), 'photos' => array_column($listing['photos'], 'url')];
            try {
                if (! empty($listing['updated_at'])) {
                    $modified = CarbonImmutable::parse($listing['updated_at'], config('app.timezone'));
                    if ($modified <= now()) {
                        $entry['lastmod'] = $modified->toAtomString();
                    }
                }
            } catch (\Throwable) {
                // Invalid legacy timestamps must not generate an invented modification date.
            }
            $entries[] = $entry;
        }
        $escape = fn (string $value): string => htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $xml = '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">';
        foreach ($entries as $entry) {
            $xml .= '<url><loc>'.$escape($entry['url']).'</loc>';
            if (isset($entry['lastmod'])) {
                $xml .= '<lastmod>'.$escape($entry['lastmod']).'</lastmod>';
            }
            foreach ($entry['photos'] ?? [] as $photo) {
                $xml .= '<image:image><image:loc>'.$escape($photo).'</image:loc></image:image>';
            }
            $xml .= '</url>';
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

    private function listingPage(Request $request, Tenant $tenant, object $site, array $listing, ListingAvailability $availability, PublicEnquiries $enquiries, bool $preview = false): Response
    {
        $today = CarbonImmutable::now($tenant->timezone)->startOfDay();
        $month = $today->startOfMonth();
        $calendar = $availability->calendar($tenant, $listing, $month->toDateString(), $month->addMonth()->toDateString());
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
        $ratings = app(PublicReviews::class)->summaries($tenant);
        $alternatives = $preview ? collect() : app(PublicListingCatalog::class)->published($tenant)
            ->filter(fn (array $other): bool => $other['id'] !== $listing['id'] && $other['city'] === $listing['city'])->take(3)
            ->map(function (array $other) use ($tenant, $site, $request, $ratings): array {
                $other['links'] = $this->listingLinks($tenant, $other, $site, $request);
                $other['rating'] = $ratings[$other['id']] ?? null;

                return $other;
            });
        $canonicalUrl = $this->publicUrl('public-stays.show', ['company' => $tenant->slug, 'slug' => $listing['slug']]);
        $listingTitle = $this->plainText($listing['title'], 180);
        $destination = $this->plainText(implode(', ', array_filter([$listing['neighbourhood'], $listing['city']])), 150);
        $pageTitle = $this->plainText($listing['seo_title'] ?? '', 180) ?: implode(' | ', array_unique(array_filter([$listingTitle, $destination, $this->plainText($tenant->name, 100)])));
        $pageDescription = $this->plainText($listing['seo_description'] ?? '', 320) ?: $this->plainText($listing['description'], 160);
        $structuredData = $preview ? null : $this->listingSchema($tenant, $listing, $canonicalUrl);
        $publicLinks = $this->listingLinks($tenant, $listing, $site, $request);
        $enquiryDefaults = $this->enquiryDefaults($request);
        $reviews = app(PublicReviews::class)->forListing($tenant, $listing['id']);
        $guestInfo = app(PublicGuestInfo::class)->content($tenant, $site);

        return $this->page('public_stays.show', compact('tenant', 'site', 'listing', 'calendar', 'month', 'weeks', 'canEnquire', 'formContext', 'alternatives', 'preview', 'canonicalUrl', 'structuredData', 'publicLinks', 'enquiryDefaults', 'reviews', 'guestInfo') + [
            'today' => $today->toDateString(), 'pageTitle' => $pageTitle, 'pageDescription' => $pageDescription,
            'robotsContent' => $preview ? 'noindex, nofollow' : 'index, follow, max-image-preview:large',
            'socialImage' => $preview ? null : $this->socialImage($listing['photos'][0] ?? null),
        ], $preview);
    }

    private function listingSchema(Tenant $tenant, array $listing, string $canonicalUrl): array
    {
        $images = [];
        foreach ($listing['photos'] as $index => $photo) {
            $images[] = ['@type' => 'ImageObject', '@id' => $canonicalUrl.'#photo-'.($index + 1),
                'contentUrl' => $photo['url'], 'caption' => $this->plainText($photo['caption'], 180)];
        }
        $accommodation = [
            '@type' => 'LodgingBusiness', '@id' => $canonicalUrl.'#accommodation',
            'name' => $this->plainText($listing['title'], 180),
            'description' => $this->plainText($listing['description'], 400), 'url' => $canonicalUrl,
            'image' => array_map(fn (array $image): array => ['@id' => $image['@id']], $images),
            'address' => ['@type' => 'PostalAddress', 'addressLocality' => $this->plainText($listing['city'], 100)],
            'amenityFeature' => array_map(fn (string $label): array => ['@type' => 'LocationFeatureSpecification', 'name' => $this->plainText($label, 100), 'value' => true], $listing['amenity_labels']),
        ];
        $breadcrumbs = ['@type' => 'BreadcrumbList', '@id' => $canonicalUrl.'#breadcrumbs', 'itemListElement' => [
            ['@type' => 'ListItem', 'position' => 1, 'name' => 'Find a stay', 'item' => $this->publicUrl('public-stays.index', ['company' => $tenant->slug])],
            ['@type' => 'ListItem', 'position' => 2, 'name' => $this->plainText($listing['title'], 180), 'item' => $canonicalUrl],
        ]];

        return ['@context' => 'https://schema.org', '@graph' => [$accommodation, $breadcrumbs, ...$images]];
    }

    private function publicUrl(string $route, array $parameters, array $query = []): string
    {
        $url = rtrim(config('app.url'), '/').route($route, $parameters, false);

        return $query === [] ? $url : $url.'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /** @return array{public_url: string, enquiry_url: string, whatsapp_url: ?string} */
    private function listingLinks(Tenant $tenant, array $listing, object $site, Request $request): array
    {
        $selection = $this->enquiryDefaults($request);
        $links = app(PublicWebsiteLinks::class)->forListing($tenant, $listing, $site, $selection);
        $links['enquiry_url'] = $links['public_url'].($selection === [] ? '' : '?'.http_build_query($selection, '', '&', PHP_QUERY_RFC3986)).'#enquire';

        return $links;
    }

    /** @return array<string, string|int> */
    private function enquiryDefaults(Request $request): array
    {
        $selection = [];
        foreach (['check_in', 'check_out'] as $field) {
            $value = $request->query($field);
            if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) {
                continue;
            }
            try {
                $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, 'UTC');
                if ($date && $date->format('Y-m-d') === $value) {
                    $selection[$field] = $value;
                }
            } catch (\Throwable) {
                continue;
            }
        }
        $guests = $request->query('guests');
        if ((is_string($guests) || is_int($guests)) && ctype_digit((string) $guests) && (int) $guests >= 1 && (int) $guests <= 100) {
            $selection['guests'] = (int) $guests;
        }

        return $selection;
    }

    private function plainText(string $html, int $limit): string
    {
        $document = new DOMDocument;
        $document->loadHTML('<!doctype html><html><head><meta charset="utf-8"></head><body>'.$html.'</body></html>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        foreach ((new DOMXPath($document))->query('//script|//style|//iframe|//object|//embed|//noscript|//template|//svg|//form') as $node) {
            $node->parentNode?->removeChild($node);
        }
        foreach ((new DOMXPath($document))->query('//p|//div|//li|//br|//h1|//h2|//h3|//h4|//h5|//h6') as $node) {
            $node->appendChild($document->createTextNode(' '));
        }
        $body = $document->getElementsByTagName('body')->item(0);
        $text = preg_replace('/\s+/u', ' ', $body?->textContent ?? '');

        return Str::limit(trim($text ?? ''), $limit, '…');
    }

    private function socialImage(?array $photo): ?array
    {
        return $photo ? ['url' => $photo['url'], 'caption' => $this->plainText($photo['caption'] ?? '', 180)] : null;
    }

    /**
     * Shortcuts shown to signed-in staff who manage this company's listings, like an editing bar on a website.
     *
     * @param  array<string, mixed>|null  $listing
     * @return array{edit_url: ?string, listings_url: string, settings_url: string, dashboard_url: string, status: ?string}|null
     */
    private function staffTools(Tenant $tenant, ?array $listing): ?array
    {
        $catalog = app(PublicListingCatalog::class);
        if (! $catalog->canManage(auth()->user(), $tenant)) {
            return null;
        }
        $callerEnquiry = null;
        if (isset($listing['id']) && $catalog->allows(auth()->user(), $tenant, 'sales.manage', 'sales')) {
            $callerEnquiry = route('sales.create', $tenant->slug).'?'.http_build_query(['listing' => $listing['id']] + $this->enquiryDefaults(request()), '', '&', PHP_QUERY_RFC3986);
        }

        $owner = isset($listing['id']) ? $catalog->ownerContact($tenant, $listing['unit_id'] ?? null, $listing['partner_apartment_id'] ?? null) : null;
        if ($owner) {
            $owner['whatsapp_url'] = app(PublicWebsiteLinks::class)->ownerWhatsappUrl($owner, $tenant, $listing['title'], $this->enquiryDefaults(request()), null,
                $this->publicUrl('public-stays.show', ['company' => $tenant->slug, 'slug' => $listing['slug']]));
        }

        return [
            'owner' => $owner,
            'caller_enquiry_url' => $callerEnquiry,
            'edit_url' => isset($listing['id']) ? route('public-listings.edit', ['tenant' => $tenant->slug, 'id' => $listing['id']]) : null,
            'listings_url' => route('public-listings.index', $tenant->slug),
            'settings_url' => route('public-listings.settings', $tenant->slug),
            'dashboard_url' => url('/t/'.rawurlencode($tenant->slug).'/dashboard'),
            'status' => isset($listing['id']) ? 'Published listing' : null,
        ];
    }

    private function page(string $view, array $data, bool $preview = false): Response
    {
        if (isset($data['tenant'])) {
            $links = app(PublicWebsiteLinks::class);
            $data += ['websiteMenu' => $links->menu($data['tenant'], $data['site'] ?? null),
                'websiteContactLinks' => $links->forSite($data['tenant'], $data['site'] ?? null)];
            $data['staffTools'] = ! $preview && auth()->check() ? $this->staffTools($data['tenant'], $data['listing'] ?? null) : null;
        }
        $response = response()->view($view, $data)->header('Cache-Control', 'private, no-store')
            ->header('Referrer-Policy', 'strict-origin-when-cross-origin')->header('X-Content-Type-Options', 'nosniff');
        if ($preview) {
            $response->header('X-Robots-Tag', 'noindex, nofollow');
        } elseif (($data['robotsContent'] ?? null) === 'noindex, follow') {
            $response->header('X-Robots-Tag', 'noindex, follow');
        }

        return $response;
    }
}
