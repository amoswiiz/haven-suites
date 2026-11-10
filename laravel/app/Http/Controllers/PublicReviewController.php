<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Services\PublicListingCatalog;
use App\Services\PublicReviews;
use App\Services\PublicWebsiteLinks;
use App\Services\RoyceTalkSms;
use App\Support\PhoneNumber;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** Staff collect and moderate guest reviews; guests leave a review through a private link. */
class PublicReviewController extends Controller
{
    public function index(Request $request): View
    {
        $tenant = app('currentTenant');
        $status = $request->validate(['status' => ['nullable', Rule::in(['pending', 'approved', 'hidden', 'requested'])]])['status'] ?? 'pending';
        $listings = DB::table('public_listings')->where('tenant_id', $tenant->id)->where('status', '!=', 'archived')->orderBy('title')->get(['id', 'title', 'listing_code']);
        $counts = DB::table('public_reviews')->where('tenant_id', $tenant->id)->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');
        $reviews = DB::table('public_reviews')->where('tenant_id', $tenant->id)->where('status', $status)
            ->orderByDesc($status === 'requested' ? 'created_at' : 'submitted_at')->orderByDesc('id')->paginate(25)->withQueryString();

        return view('public_listings.reviews', [
            'reviews' => $reviews, 'status' => $status, 'counts' => $counts, 'listings' => $listings->keyBy('id'),
            'sources' => PublicReviews::SOURCES, 'smsAvailable' => $this->smsAvailable($tenant),
            'newLink' => session('review_link'),
        ]);
    }

    /** Create a private review link for a guest and optionally text it to them. */
    public function request(Request $request, PublicReviews $reviews): RedirectResponse
    {
        $tenant = app('currentTenant');
        $data = $request->validate([
            'listing_id' => ['nullable', 'integer', Rule::exists('public_listings', 'id')->where('tenant_id', $tenant->id)],
            'guest_name' => 'required|string|min:2|max:100', 'guest_phone' => $this->phoneRule(),
            'stayed_on' => 'nullable|date_format:Y-m-d|before_or_equal:today', 'send_sms' => 'sometimes|boolean',
        ]);
        $phone = PhoneNumber::normalize($data['guest_phone'] ?? null);
        if (! PhoneNumber::isBlank($data['guest_phone'] ?? null) && $phone === null) {
            throw ValidationException::withMessages(['guest_phone' => 'Enter a valid mobile number, e.g. +254 712 345 678.']);
        }
        if ($request->boolean('send_sms') && $phone === null) {
            throw ValidationException::withMessages(['guest_phone' => 'Add the guest mobile number to send the link by SMS.']);
        }
        $link = $reviews->request($tenant, $data['listing_id'] ?? null, trim($data['guest_name']), $phone, $data['stayed_on'] ?? null, auth()->user());
        $message = $reviews->invitation($tenant, $data['guest_name'], $link['url']);
        $smsStatus = null;
        if ($request->boolean('send_sms')) {
            $smsStatus = $this->sendSms($tenant, $phone, $message, $link['id']);
            DB::table('public_reviews')->where('id', $link['id'])->update(['sms_status' => $smsStatus, 'updated_at' => now()]);
        }
        $notice = match ($smsStatus) {
            'submitted' => 'Review link created and sent by SMS.',
            'failed' => 'Review link created, but the SMS could not be sent. Share it on WhatsApp or copy the link instead.',
            'unavailable' => 'Review link created. SMS is not available, so share it on WhatsApp or copy the link.',
            default => 'Review link created. Share it with the guest.',
        };

        return redirect()->route('public-listings.reviews', $tenant->slug)->with('success', $notice)->with('review_link', [
            'url' => $link['url'], 'message' => $message,
            'whatsapp_url' => $phone ? 'https://wa.me/'.substr($phone, 1).'?text='.rawurlencode($message) : 'https://wa.me/?text='.rawurlencode($message),
        ]);
    }

    /** Add a review the guest left on another platform, shown with its source. */
    public function store(Request $request): RedirectResponse
    {
        $tenant = app('currentTenant');
        $data = $request->validate([
            'listing_id' => ['nullable', 'integer', Rule::exists('public_listings', 'id')->where('tenant_id', $tenant->id)],
            'source' => ['required', Rule::in(array_diff(array_keys(PublicReviews::SOURCES), ['direct']))],
            'guest_name' => 'required|string|min:2|max:100', 'rating' => 'required|integer|min:1|max:5',
            'comment' => 'required|string|min:10|max:2000', 'stayed_on' => 'nullable|date_format:Y-m-d|before_or_equal:today',
        ]);
        DB::table('public_reviews')->insert([
            'tenant_id' => $tenant->id, 'listing_id' => $data['listing_id'] ?? null, 'source' => $data['source'], 'status' => 'approved',
            'guest_name' => trim($data['guest_name']), 'rating' => $data['rating'], 'comment' => trim($data['comment']),
            'stayed_on' => $data['stayed_on'] ?? null, 'requested_by' => auth()->id(), 'moderated_by' => auth()->id(),
            'submitted_at' => $data['stayed_on'] ?? now(), 'moderated_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return redirect()->route('public-listings.reviews', [$tenant->slug, 'status' => 'approved'])->with('success', 'Review added and shown on the website.');
    }

    /** Approve, hide, reply to or delete a review. */
    public function moderate(Request $request, string $tenant, int $id): RedirectResponse
    {
        $company = app('currentTenant');
        $data = $request->validate(['action' => 'required|in:approve,hide,reply,delete', 'reply' => 'nullable|string|max:1000']);
        $review = DB::table('public_reviews')->where('tenant_id', $company->id)->where('id', $id)->firstOrFail();
        if ($data['action'] === 'delete') {
            DB::table('public_reviews')->where('id', $review->id)->delete();

            return back()->with('success', 'Review deleted.');
        }
        if ($review->status === 'requested' && $data['action'] !== 'hide') {
            throw ValidationException::withMessages(['review' => 'The guest has not left this review yet.']);
        }
        $changes = match ($data['action']) {
            'approve' => ['status' => 'approved'],
            'hide' => ['status' => 'hidden'],
            'reply' => ['reply' => trim((string) ($data['reply'] ?? '')) ?: null],
        };
        DB::table('public_reviews')->where('id', $review->id)->update($changes + ['moderated_by' => auth()->id(), 'moderated_at' => now(), 'updated_at' => now()]);

        return back()->with('success', match ($data['action']) {
            'approve' => 'Review approved. It now shows on the website.', 'hide' => 'Review hidden from the website.', 'reply' => 'Reply saved.',
        });
    }

    /** Public review form opened from the private link. */
    public function show(string $company, string $token, PublicListingCatalog $catalog, PublicReviews $reviews): Response
    {
        [$tenant, $site] = $this->company($company, $catalog);
        $review = $reviews->findRequest($tenant, $token);

        return $this->page($tenant, $site, $review, $token, $review ? 200 : 404);
    }

    public function submit(Request $request, string $company, string $token, PublicListingCatalog $catalog, PublicReviews $reviews): Response|RedirectResponse
    {
        [$tenant, $site] = $this->company($company, $catalog);
        if (filled($request->input('website'))) {
            return $this->page($tenant, $site, null, $token, 200, true);
        }
        $data = $request->validate([
            'rating' => 'required|integer|min:1|max:5', 'comment' => 'required|string|min:10|max:2000',
            'display_name' => 'required|string|min:2|max:100', 'consent' => 'accepted',
        ], ['rating.required' => 'Choose a star rating.', 'consent.accepted' => 'Tick the box to let us show your review.']);
        $updated = DB::transaction(function () use ($tenant, $token, $data, $reviews): bool {
            $review = $reviews->findRequest($tenant, $token);
            if (! $review) {
                return false;
            }

            return (bool) DB::table('public_reviews')->where('id', $review->id)->where('status', 'requested')->update([
                'rating' => $data['rating'], 'comment' => trim($data['comment']), 'guest_name' => trim($data['display_name']),
                'status' => 'pending', 'submitted_at' => now(), 'token_hash' => null, 'updated_at' => now(),
            ]);
        });

        return $this->page($tenant, $site, null, $token, $updated ? 200 : 404, $updated);
    }

    private function page(Tenant $tenant, object $site, ?object $review, string $token, int $status, bool $submitted = false): Response
    {
        $listing = $review?->listing_id ? DB::table('public_listings')->where('tenant_id', $tenant->id)->where('id', $review->listing_id)->first(['title']) : null;

        return response()->view('public_stays.review', [
            'tenant' => $tenant, 'site' => $site, 'review' => $review, 'token' => $token, 'submitted' => $submitted, 'listingTitle' => $listing?->title,
            'pageTitle' => 'Rate your stay | '.$tenant->name, 'robotsContent' => 'noindex, nofollow',
            'websiteMenu' => app(PublicWebsiteLinks::class)->menu($tenant, $site), 'websiteContactLinks' => app(PublicWebsiteLinks::class)->forSite($tenant, $site),
        ], $status)->header('Cache-Control', 'private, no-store')->header('X-Robots-Tag', 'noindex, nofollow')->header('Referrer-Policy', 'no-referrer');
    }

    private function company(string $slug, PublicListingCatalog $catalog): array
    {
        $tenant = Tenant::where('slug', $slug)->where('active', true)->firstOrFail();
        $site = $catalog->site($tenant);
        abort_unless($site, 404);

        return [$tenant, $site];
    }

    private function smsAvailable(Tenant $tenant): bool
    {
        return ($tenant->modules['messaging'] ?? true) !== false && config('services.roycetalk.manual_live') && app(RoyceTalkSms::class)->configured();
    }

    private function sendSms(Tenant $tenant, string $phone, string $message, int $reviewId): string
    {
        if (! $this->smsAvailable($tenant)) {
            return 'unavailable';
        }
        try {
            $response = app(RoyceTalkSms::class)->send($phone, $message, 'review-'.$tenant->id.'-'.$reviewId);

            return $response->successful() && $response->json('success') === true ? 'submitted' : 'failed';
        } catch (\Throwable) {
            return 'failed';
        }
    }

    /** @return list<mixed> */
    private function phoneRule(): array
    {
        return ['nullable', 'string', 'max:40'];
    }

    /** Date shown on a review: the month of the stay. */
    public static function stayMonth(?string $date): ?string
    {
        try {
            return $date ? CarbonImmutable::parse($date)->format('F Y') : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
