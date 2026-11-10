<?php

namespace App\Http\Controllers;

use App\Services\PartnerAvailabilityRequests;
use App\Services\PublicListingCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** The page a partner owner opens from a WhatsApp availability request to answer it. */
class PartnerRequestController extends Controller
{
    public function show(string $code, PartnerAvailabilityRequests $requests, PublicListingCatalog $catalog): Response
    {
        $found = $requests->find($code);
        if (! $found) {
            return $this->notFound();
        }
        ['request' => $availability, 'tenant' => $tenant] = $found;
        $site = $catalog->site($tenant);
        $details = $requests->unitDetails($tenant, $availability);
        $listingUrl = $site && $details['listing']?->status === 'published'
            ? rtrim(config('app.url'), '/').route('public-stays.show', ['company' => $tenant->slug, 'slug' => $details['listing']->slug], false) : null;

        return response()->view('partners.request', [
            'tenant' => $tenant, 'site' => $site, 'availability' => $availability, 'code' => $code,
            'unit' => $details['unit'], 'photos' => $details['photos'], 'listingUrl' => $listingUrl, 'hasFeatured' => $details['featured'],
            'pageTitle' => 'Availability request | '.$tenant->name, 'pageDescription' => $this->preview($availability),
            'robotsContent' => 'noindex, nofollow', 'websiteMenu' => [],
        ])->header('Cache-Control', 'private, no-store')->header('X-Robots-Tag', 'noindex, nofollow')->header('Referrer-Policy', 'no-referrer');
    }

    public function respond(string $code, Request $request, PartnerAvailabilityRequests $requests): RedirectResponse|Response
    {
        $found = $requests->find($code);
        if (! $found) {
            return $this->notFound();
        }
        $data = $request->validate([
            'response' => ['required', Rule::in(array_keys(PartnerAvailabilityRequests::RESPONSES))],
            'nightly_price' => 'nullable|numeric|min:0|max:99999999', 'note' => 'nullable|string|max:500',
        ]);
        $requests->respond($found['request'], $data['response'], isset($data['nightly_price']) ? (float) $data['nightly_price'] : null, $data['note'] ?? null);

        return redirect()->route('partner-request.show', $code)->with('success', 'Thank you. Your reply has been sent to '.$found['tenant']->name.'.');
    }

    /** The unit's featured photo, served only through the owner's private link. */
    public function featured(string $code, PartnerAvailabilityRequests $requests): BinaryFileResponse|Response
    {
        $found = $requests->find($code);
        $path = $found ? $requests->featuredPhotoPath($found['tenant'], (int) $found['request']->partner_apartment_id) : null;
        if (! $path) {
            return $this->notFound();
        }

        return response()->file(Storage::disk('local')->path($path), ['Content-Type' => 'image/jpeg', 'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=3600', 'X-Robots-Tag' => 'noindex, nofollow']);
    }

    /** A photo of the unit being asked about, served only through the owner's private link. */
    public function photo(string $code, int $photo, PartnerAvailabilityRequests $requests, PublicListingCatalog $catalog): BinaryFileResponse|Response
    {
        $found = $requests->find($code);
        $listing = $found ? $requests->listing($found['tenant'], $found['request']) : null;
        if (! $listing) {
            return $this->notFound();
        }
        $image = $catalog->imageForListing($found['tenant'], (int) $listing->id, $photo);

        return response()->file($image['path'], ['Content-Type' => $image['mime_type'], 'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=3600', 'X-Robots-Tag' => 'noindex, nofollow']);
    }

    /** Link preview text, e.g. "Availability request for Third Avenue beach apt (1BR): Thu 26 Nov – Sun 29 Nov 2026, 3 nights". */
    private function preview(object $availability): string
    {
        $in = $availability->check_in ? Carbon::parse($availability->check_in) : null;
        $out = $availability->check_out ? Carbon::parse($availability->check_out) : null;
        $dates = match (true) {
            $in && $out => $in->format('D j M').' – '.$out->format('D j M Y').', '.(int) $in->diffInDays($out).' night'.((int) $in->diffInDays($out) === 1 ? '' : 's'),
            $in !== null => 'check-in '.$in->format('D j M Y'),
            default => 'dates to be confirmed',
        };

        return 'Availability request for '.$availability->apartment_label.': '.$dates.'. Tap to see the unit and reply.';
    }

    private function notFound(): Response
    {
        return response('This availability request link is invalid or has expired. Please reply to the WhatsApp message instead.', 404)
            ->header('Cache-Control', 'private, no-store')->header('X-Robots-Tag', 'noindex, nofollow');
    }
}
