<?php

namespace App\Services;

use App\Models\Tenant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Listing quality for staff: the editor checklist plus what only the whole catalogue shows (whose owner is still
 * unknown, which imported listings nobody has checked, and which listings may describe the same apartment).
 */
final class ListingQuality
{
    /** Words too common in listing names to suggest two listings are the same apartment. */
    private const COMMON_WORDS = ['apartment', 'apartments', 'bedroom', 'bedrooms', 'studio', 'suite', 'suites', 'house', 'villa', 'villas', 'cottage',
        'home', 'homes', 'views', 'beach', 'beachfront', 'ocean', 'luxury', 'modern', 'cozy', 'cosy', 'spacious', 'haven', 'nairobi', 'mombasa',
        'diani', 'nyali', 'naivasha', 'nanyuki', 'watamu', 'malindi', 'kilifi', 'kenya', 'serviced', 'furnished', 'penthouse', 'maisonette',
        'garden', 'family', 'executive', 'classic', 'elegant', 'stylish', 'comfortable', 'unit', 'towers', 'tower', 'court', 'heights', 'residence',
        'residences', 'place', 'estate', 'resort', 'retreat', 'escape', 'getaway', 'stay', 'stays', 'swimming', 'balcony', 'lake', 'lakeview', 'seaview'];

    public function __construct(private PublicListingCatalog $catalog) {}

    /**
     * Checklist, score and possible duplicate for each listing.
     *
     * @param  Collection<int, object>  $listings  Rows from public_listings.
     * @return array<int, array{checks: list<array{label: string, done: bool, tip: string}>, score: int, todo: list<string>, duplicate_of: ?object}>
     */
    public function forListings(Tenant $tenant, Collection $listings): array
    {
        $photos = DB::table('public_listing_photos')->where('tenant_id', $tenant->id)->whereIn('listing_id', $listings->pluck('id')->all() ?: [0])
            ->orderBy('sort_order')->orderBy('id')->get()->groupBy('listing_id');
        $owners = $this->catalog->partnerOwners($tenant);
        $imports = $this->imports($tenant);
        $duplicates = $this->duplicates($listings);
        $results = [];
        foreach ($listings as $listing) {
            $checks = ListingDetails::checklist($listing, $photos->get($listing->id, collect()), $this->contextFor($listing, $owners, $imports));
            $done = collect($checks)->where('done', true)->count();
            $results[(int) $listing->id] = [
                'checks' => $checks, 'score' => (int) round($done / max(1, count($checks)) * 100),
                'todo' => collect($checks)->where('done', false)->pluck('label')->values()->all(),
                'duplicate_of' => $duplicates[(int) $listing->id] ?? null,
            ];
        }

        return $results;
    }

    /**
     * The clean-up list for the dashboard: listings still to optimise (weakest first), then those staff marked done.
     *
     * @return array{rows: list<array{listing: object, score: int, todo: list<string>, duplicate_of: ?object, optimised_by: ?string}>, optimised: int, total: int}
     */
    public function cleanup(Tenant $tenant): array
    {
        $listings = DB::table('public_listings')->where('tenant_id', $tenant->id)->where('status', '!=', 'archived')->get();
        $quality = $this->forListings($tenant, $listings);
        $names = DB::table('users')->whereIn('id', $listings->pluck('optimised_by')->filter()->unique()->all() ?: [0])->pluck('name', 'id');
        $rows = $listings->map(fn (object $listing): array => [
            'listing' => $listing,
            'score' => $quality[(int) $listing->id]['score'],
            'todo' => $quality[(int) $listing->id]['todo'],
            'duplicate_of' => $quality[(int) $listing->id]['duplicate_of'],
            'optimised_by' => $listing->optimised_by !== null ? ($names[(int) $listing->optimised_by] ?? 'A former team member') : null,
        ]);
        [$done, $todo] = $rows->partition(fn (array $row): bool => $row['listing']->optimised_at !== null);

        return [
            'rows' => $todo->sortBy([['score', 'asc'], fn (array $a, array $b): int => strcasecmp((string) $a['listing']->title, (string) $b['listing']->title)])
                ->concat($done->sortByDesc(fn (array $row): string => (string) $row['listing']->optimised_at))->values()->all(),
            'optimised' => $done->count(),
            'total' => $rows->count(),
        ];
    }

    /**
     * Owner and import facts for one listing in the editor, and a listing it may duplicate.
     *
     * @return array{context: array{owner_recorded: ?bool, unchecked_import: ?bool}, duplicate_of: ?object}
     */
    public function forEditor(Tenant $tenant, object $listing): array
    {
        $others = DB::table('public_listings')->where('tenant_id', $tenant->id)->where('status', '!=', 'archived')->get();

        return [
            'context' => $this->contextFor($listing, $this->catalog->partnerOwners($tenant), $this->imports($tenant)),
            'duplicate_of' => $this->duplicates($others->push($listing)->unique('id'))[(int) $listing->id] ?? null,
        ];
    }

    /**
     * @param  array<int, bool>  $owners
     * @param  array{imported: array<int, true>, unchecked: array<int, true>}  $imports
     * @return array{owner_recorded: ?bool, unchecked_import: ?bool}
     */
    private function contextFor(object $listing, array $owners, array $imports): array
    {
        $id = (int) $listing->id;
        $partnerId = ($listing->partner_apartment_id ?? null) !== null ? (int) $listing->partner_apartment_id : null;

        return [
            'owner_recorded' => $partnerId !== null ? ($owners[$partnerId] ?? false) : null,
            'unchecked_import' => isset($imports['imported'][$id]) ? isset($imports['unchecked'][$id]) : null,
        ];
    }

    /**
     * Listings imported from the old website, and those nobody has saved in the editor since.
     *
     * @return array{imported: array<int, true>, unchecked: array<int, true>}
     */
    private function imports(Tenant $tenant): array
    {
        $events = DB::table('public_listing_audit_events')->where('tenant_id', $tenant->id)->whereNotNull('listing_id')
            ->whereIn('action', ['listing_imported', 'listing_updated'])->orderBy('id')->get(['listing_id', 'action']);
        $imported = [];
        $unchecked = [];
        foreach ($events as $event) {
            $id = (int) $event->listing_id;
            if ($event->action === 'listing_imported') {
                $imported[$id] = $unchecked[$id] = true;
            } else {
                unset($unchecked[$id]);
            }
        }

        return ['imported' => $imported, 'unchecked' => $unchecked];
    }

    /**
     * Listings that may describe the same apartment: same city and bedrooms, and the same listing code or a shared
     * distinctive word in the name (such as a building name). Each listing points to the first match by name.
     *
     * @param  Collection<int, object>  $listings
     * @return array<int, object>
     */
    private function duplicates(Collection $listings): array
    {
        // Place names are shared by many different apartments, so they never count as a match.
        $placeWords = $listings->flatMap(fn (object $listing): array => $this->words((string) ($listing->city ?? '').' '.($listing->neighbourhood ?? '')))->unique()->all();
        $prepared = $listings->filter(fn (object $listing): bool => ($listing->status ?? '') !== 'archived')->map(fn (object $listing): array => [
            'listing' => $listing,
            'city' => mb_strtolower(trim((string) ($listing->city ?? ''))),
            'bedrooms' => ($listing->bedrooms ?? null) !== null ? (int) $listing->bedrooms : null,
            'code' => mb_strtolower(trim((string) ($listing->listing_code ?? ''))),
            'words' => array_values(array_diff($this->words((string) ($listing->title ?? '')), self::COMMON_WORDS, $placeWords)),
        ])->sortBy(fn (array $row): string => mb_strtolower((string) $row['listing']->title))->values();
        $matches = [];
        foreach ($prepared as $row) {
            foreach ($prepared as $other) {
                if ($other['listing']->id === $row['listing']->id || $row['city'] === '' || $row['city'] !== $other['city']
                    || ($row['bedrooms'] !== null && $other['bedrooms'] !== null && $row['bedrooms'] !== $other['bedrooms'])) {
                    continue;
                }
                if (($row['code'] !== '' && $row['code'] === $other['code']) || array_intersect($row['words'], $other['words']) !== []) {
                    $matches[(int) $row['listing']->id] = $other['listing'];
                    break;
                }
            }
        }

        return $matches;
    }

    /** @return list<string> Lower-case words of five or more letters. */
    private function words(string $text): array
    {
        preg_match_all('/\p{L}{5,}/u', mb_strtolower($text), $found);

        return array_values(array_unique($found[0]));
    }
}
