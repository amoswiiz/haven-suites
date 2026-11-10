<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Services\EnquiryTracking;
use App\Services\PublicEnquiries;
use App\Services\PublicListingCatalog;
use App\Services\PublicWebsiteLinks;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class PublicEnquiryController extends Controller
{
    public function store(Request $request, string $company, string $listing, PublicEnquiries $enquiries, PublicListingCatalog $catalog): RedirectResponse
    {
        $tenant = Tenant::where('slug', $company)->where('active', true)->firstOrFail();

        return $enquiries->create($tenant, $catalog->findPublished($tenant, $listing), $request);
    }

    public function received(string $company, string $receipt, PublicListingCatalog $catalog): Response
    {
        $tenant = Tenant::where('slug', $company)->where('active', true)->firstOrFail();
        $site = $catalog->site($tenant);
        abort_unless($site && preg_match('/^[a-f0-9]{64}$/D', $receipt), 404);
        $row = DB::table('public_enquiry_receipts')->where('tenant_id', $tenant->id)->where('token_hash', hash('sha256', $receipt))->firstOrFail();

        $links = app(PublicWebsiteLinks::class);

        return response()->view('public_stays.received', ['tenant' => $tenant, 'site' => $site, 'reference' => $row->reference,
            'trackingUrl' => app(EnquiryTracking::class)->forLead($tenant, (int) $row->lead_id)['url'] ?? null,
            'websiteMenu' => $links->menu($tenant, $site), 'websiteContactLinks' => $links->forSite($tenant, $site)])
            ->header('Cache-Control', 'private, no-store')->header('X-Robots-Tag', 'noindex, nofollow')->header('Referrer-Policy', 'no-referrer');
    }

    public function track(string $code, EnquiryTracking $tracking, PublicListingCatalog $catalog): Response
    {
        $found = $tracking->find($code);
        if (! $found) {
            return response('This enquiry link is invalid or has expired. Please contact us for an update on your enquiry.', 404)
                ->header('Cache-Control', 'private, no-store')->header('X-Robots-Tag', 'noindex, nofollow')->header('Referrer-Policy', 'no-referrer');
        }
        ['tenant' => $tenant, 'lead' => $lead, 'reference' => $reference] = $found;
        $site = $catalog->site($tenant);
        $links = app(PublicWebsiteLinks::class);
        $firstName = trim(explode(' ', trim((string) $lead->name))[0] ?? '');

        return response()->view('public_stays.track', [
            'tenant' => $tenant, 'site' => $site, 'reference' => $reference, 'firstName' => $firstName,
            'status' => $tracking->status((string) $lead->stage), 'steps' => EnquiryTracking::STEPS,
            'stay' => ['apartment' => $lead->unit_preference, 'location' => $lead->location, 'check_in' => $lead->check_in,
                'check_out' => $lead->check_out, 'guests' => (int) $lead->guests],
            'updatedAt' => $lead->updated_at, 'pageTitle' => 'Enquiry '.$reference.' | '.$tenant->name,
            'pageDescription' => 'Track the progress of your enquiry with '.$tenant->name.'.', 'robotsContent' => 'noindex, nofollow',
            'websiteMenu' => $site ? $links->menu($tenant, $site) : [], 'websiteContactLinks' => $site ? $links->forSite($tenant, $site) : null,
        ])->header('Cache-Control', 'private, no-store')->header('X-Robots-Tag', 'noindex, nofollow')->header('Referrer-Policy', 'no-referrer');
    }
}
