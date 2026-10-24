<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class CompanySettingsController extends Controller
{
    public function edit(): View
    {
        abort_unless(auth()->user()->inGroup('superadmin', 'admin'), 403);

        return view('saas.company-settings');
    }

    public function update(Request $request): RedirectResponse
    {
        abort_unless($request->user()->inGroup('superadmin', 'admin'), 403);
        $data = $request->validate(['name' => 'required|string|max:150', 'contact_email' => 'nullable|email|max:180', 'contact_phone' => 'nullable|string|max:40',
            'report_logo' => 'nullable|image|mimes:png,jpg,jpeg|max:2048|dimensions:max_width=4000,max_height=4000',
            'report_primary_color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'report_accent_color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ]);
        unset($data['report_logo']);
        if ($request->hasFile('report_logo')) {
            $data['report_logo_path'] = $request->file('report_logo')->store('company-branding/'.app('currentTenant')->id, 'local');
        }
        $oldLogo = app('currentTenant')->report_logo_path;
        app('currentTenant')->update($data);
        if (isset($data['report_logo_path']) && $oldLogo && str_starts_with($oldLogo, 'company-branding/'.app('currentTenant')->id.'/')) {
            Storage::disk('local')->delete($oldLogo);
        }

        return back()->with('success', 'Company settings updated.');
    }
}
