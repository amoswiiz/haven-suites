<?php

namespace App\Http\Controllers;

use App\Services\PartnerAvailabilityRequests;
use App\Services\PublicListingCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

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

        return response()->view('partners.request', [
            'tenant' => $tenant, 'site' => $catalog->site($tenant), 'availability' => $availability, 'code' => $code,
            'pageTitle' => 'Availability request | '.$tenant->name, 'robotsContent' => 'noindex, nofollow', 'websiteMenu' => [],
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

    private function notFound(): Response
    {
        return response('This availability request link is invalid or has expired. Please reply to the WhatsApp message instead.', 404)
            ->header('Cache-Control', 'private, no-store')->header('X-Robots-Tag', 'noindex, nofollow');
    }
}
