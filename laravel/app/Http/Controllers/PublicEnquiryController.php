<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Services\PublicEnquiries;
use App\Services\PublicListingCatalog;
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

        return response()->view('public_stays.received', ['tenant' => $tenant, 'site' => $site, 'reference' => $row->reference])
            ->header('Cache-Control', 'private, no-store')->header('X-Robots-Tag', 'noindex, nofollow')->header('Referrer-Policy', 'no-referrer');
    }
}
