<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Guest reviews for the public website. Verified reviews come from a private link sent to a guest after a stay;
 * staff may also copy reviews from other platforms, labelled with their source. Nothing is shown until approved.
 */
class PublicReviews
{
    /** @var array<string, string> */
    public const SOURCES = ['direct' => 'Verified stay', 'airbnb' => 'Airbnb', 'booking' => 'Booking.com', 'google' => 'Google', 'other' => 'Other'];

    public const LINK_DAYS = 60;

    /**
     * Approved reviews for one listing, newest first, with the average rating.
     *
     * @return array{average: ?float, count: int, reviews: Collection<int, object>}
     */
    public function forListing(Tenant $tenant, int $listingId, int $limit = 12): array
    {
        $query = DB::table('public_reviews')->where('tenant_id', $tenant->id)->where('listing_id', $listingId)->where('status', 'approved');
        $count = (clone $query)->count();

        return [
            'average' => $count ? round((float) (clone $query)->avg('rating'), 1) : null, 'count' => $count,
            'reviews' => $query->orderByDesc('submitted_at')->orderByDesc('id')->limit($limit)->get()->map(fn (object $review): object => $this->present($review)),
        ];
    }

    /** @return array<int, array{average: float, count: int}> Approved rating summary keyed by listing id. */
    public function summaries(Tenant $tenant): array
    {
        return DB::table('public_reviews')->where('tenant_id', $tenant->id)->where('status', 'approved')->whereNotNull('listing_id')
            ->groupBy('listing_id')->selectRaw('listing_id, AVG(rating) as average, COUNT(*) as reviews')->get()
            ->mapWithKeys(fn (object $row): array => [(int) $row->listing_id => ['average' => round((float) $row->average, 1), 'count' => (int) $row->reviews]])->all();
    }

    /**
     * Recent approved reviews with a comment, for the home page.
     *
     * @param  list<int>  $listingIds  published listings, so a review never links to a hidden apartment
     * @return Collection<int, object>
     */
    public function highlights(Tenant $tenant, array $listingIds, int $limit = 3): Collection
    {
        return DB::table('public_reviews')->where('tenant_id', $tenant->id)->where('status', 'approved')->where('rating', '>=', 4)
            ->whereNotNull('comment')->where('comment', '!=', '')
            ->where(fn ($query) => $query->whereNull('listing_id')->orWhereIn('listing_id', $listingIds ?: [0]))
            ->orderByDesc('submitted_at')->orderByDesc('id')->limit($limit)->get()->map(fn (object $review): object => $this->present($review));
    }

    /**
     * Create a private review link for a guest. The link is returned once; only its hash is stored.
     *
     * @return array{id: int, url: string}
     */
    public function request(Tenant $tenant, ?int $listingId, string $guestName, ?string $phone, ?string $stayedOn, User $actor): array
    {
        $token = Str::random(40);
        $id = DB::table('public_reviews')->insertGetId([
            'tenant_id' => $tenant->id, 'listing_id' => $listingId, 'token_hash' => hash('sha256', $token), 'source' => 'direct', 'status' => 'requested',
            'guest_name' => $guestName, 'guest_phone' => $phone, 'stayed_on' => $stayedOn, 'requested_by' => $actor->id,
            'expires_at' => now()->addDays(self::LINK_DAYS), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return ['id' => $id, 'url' => rtrim(config('app.url'), '/').route('public-stays.review', ['company' => $tenant->slug, 'token' => $token], false)];
    }

    /** The open review request for a link, or null when it is unknown, used or expired. */
    public function findRequest(Tenant $tenant, string $token): ?object
    {
        return DB::table('public_reviews')->where('tenant_id', $tenant->id)->where('token_hash', hash('sha256', $token))
            ->where('status', 'requested')->where('expires_at', '>', now())->first();
    }

    /** Short guest-friendly SMS or WhatsApp text inviting the guest to review. */
    public function invitation(Tenant $tenant, string $guestName, string $url): string
    {
        $first = Str::of($guestName)->trim()->explode(' ')->first() ?: 'there';

        return 'Hi '.$first.', thank you for staying with '.$tenant->name.'. Please take a minute to rate your stay: '.$url;
    }

    /** Public name: first name and last initial, e.g. "Grace A.". */
    public static function displayName(string $name): string
    {
        $parts = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($parts === []) {
            return 'Guest';
        }
        $first = mb_convert_case(mb_strtolower($parts[0]), MB_CASE_TITLE);

        return count($parts) > 1 ? $first.' '.mb_strtoupper(mb_substr(end($parts), 0, 1)).'.' : $first;
    }

    private function present(object $review): object
    {
        $review->display_name = self::displayName($review->guest_name);
        $review->source_label = self::SOURCES[$review->source] ?? 'Guest review';
        $review->rating = (int) $review->rating;

        return $review;
    }
}
