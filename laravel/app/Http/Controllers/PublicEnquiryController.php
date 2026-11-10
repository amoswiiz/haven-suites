<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Services\EnquiryAcknowledgements;
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

        $lead = DB::table('sales_leads')->where('tenant_id', $tenant->id)->where('id', $row->lead_id)->first(['phone']);
        $sms = app(EnquiryAcknowledgements::class)->status($tenant->id, (int) $row->lead_id);
        $phoneDigits = preg_replace('/\D+/', '', (string) ($lead->phone ?? ''));

        return response()->view('public_stays.received', ['tenant' => $tenant, 'site' => $site, 'reference' => $row->reference,
            'trackingUrl' => app(EnquiryTracking::class)->forLead($tenant, (int) $row->lead_id)['url'] ?? null,
            'smsSentTo' => $sms && in_array($sms->status, ['pending', 'processing', 'submitted', 'delivered'], true) && strlen($phoneDigits) >= 3 ? substr($phoneDigits, -3) : null,
            'responseHours' => (int) ($site->response_hours ?? 24), 'followUpWhatsapp' => $links->forEnquiry($tenant, $site, $row->reference),
            'websiteMenu' => $links->menu($tenant, $site), 'websiteContactLinks' => $links->forSite($tenant, $site)])
            ->header('Cache-Control', 'private, no-store')->header('X-Robots-Tag', 'noindex, nofollow')->header('Referrer-Policy', 'no-referrer');
    }

    public function lookupForm(string $company, PublicListingCatalog $catalog): Response
    {
        [$tenant, $site] = $this->publicCompany($company, $catalog);
        $links = app(PublicWebsiteLinks::class);

        return response()->view('public_stays.track_lookup', ['tenant' => $tenant, 'site' => $site,
            'pageTitle' => 'Track your enquiry | '.$tenant->name, 'pageDescription' => 'Check the progress of your enquiry with '.$tenant->name.'.',
            'robotsContent' => 'noindex, follow', 'example' => app(EnquiryTracking::class)->prefix($tenant).'-'.now($tenant->timezone)->format('ym').'-042',
            'websiteMenu' => $links->menu($tenant, $site), 'websiteContactLinks' => $links->forSite($tenant, $site)])
            ->header('Cache-Control', 'private, no-store');
    }

    public function lookup(Request $request, string $company, PublicListingCatalog $catalog, EnquiryTracking $tracking): RedirectResponse
    {
        [$tenant] = $this->publicCompany($company, $catalog);
        $data = $request->validate(['reference' => 'required|string|max:30', 'phone_last_digits' => 'required|string|max:10'],
            ['reference.required' => 'Enter the enquiry reference from your SMS.', 'phone_last_digits.required' => 'Enter the last 4 digits of your phone number.']);
        $digits = preg_replace('/\D+/', '', $data['phone_last_digits']);
        $url = strlen($digits) === 4 ? $tracking->lookup($tenant, $data['reference'], $digits) : null;
        if ($url === null) {
            return back()->withInput($request->only('reference'))->withErrors(['reference' => 'We could not find an enquiry with those details. Check the reference in your SMS and the last 4 digits of the phone number you used, or contact us.']);
        }

        return redirect()->away($url)->header('Cache-Control', 'private, no-store');
    }

    /** @return array{0: Tenant, 1: object} */
    private function publicCompany(string $company, PublicListingCatalog $catalog): array
    {
        $tenant = Tenant::where('slug', $company)->where('active', true)->firstOrFail();
        $site = $catalog->site($tenant);
        abort_unless($site, 404);

        return [$tenant, $site];
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
