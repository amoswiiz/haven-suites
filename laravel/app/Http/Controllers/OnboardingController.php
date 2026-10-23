<?php

namespace App\Http\Controllers;

use App\Services\TenantProvisioner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class OnboardingController extends Controller
{
    public function company(Request $request): View|RedirectResponse
    {
        if ($request->user()->tenants()->exists()) {
            return redirect('/');
        }

        return view('saas.company-create');
    }

    public function provision(Request $request, TenantProvisioner $provisioner): RedirectResponse
    {
        $data = $request->validate(['name' => 'required|string|max:150', 'slug' => 'required|string|min:3|max:50|regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/|unique:tenants,slug']);
        if ($request->user()->tenants()->exists()) {
            return redirect('/');
        }
        try {
            $tenant = $provisioner->provision($request->user(), $data['name'], $data['slug']);
        } catch (\Throwable $exception) {
            report($exception);

            return back()->withInput()->withErrors(['company' => 'Company setup could not finish. No existing records were changed. Please try again.']);
        }

        return redirect('/t/'.$tenant->slug.'/onboarding');
    }

    public function property(): View
    {
        abort_unless(auth()->user()->inGroup('superadmin', 'admin'), 403);

        return view('saas.onboarding', ['hasUnits' => DB::connection('tenant')->table('units')->exists()]);
    }

    public function finish(Request $request): RedirectResponse
    {
        abort_unless($request->user()->inGroup('superadmin', 'admin'), 403);
        $data = $request->validate(['property_name' => 'required|string|max:150', 'location' => 'required|string|max:150', 'unit_number' => 'required|string|max:50', 'bedrooms' => 'required|integer|min:0|max:50', 'bathrooms' => 'required|integer|min:0|max:50', 'nightly_rate' => 'required|numeric|min:0|max:10000000']);
        DB::connection('tenant')->transaction(function () use ($data): void {
            $propertyId = DB::connection('tenant')->table('properties')->insertGetId(['name' => $data['property_name'], 'location' => $data['location'], 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
            DB::connection('tenant')->table('units')->insert(['property_id' => $propertyId, 'unit_number' => $data['unit_number'], 'bedrooms' => $data['bedrooms'], 'bathrooms' => $data['bathrooms'], 'nightly_rate' => $data['nightly_rate'], 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
            app('currentTenant')->update(['onboarding_completed_at' => now()]);
        });

        return redirect(site_url('dashboard'))->with('success', 'Your first apartment is ready. You can now create a reservation or invite your team.');
    }
}
