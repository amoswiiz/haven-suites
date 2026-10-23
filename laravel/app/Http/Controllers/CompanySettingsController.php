<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
        $data = $request->validate(['name' => 'required|string|max:150', 'contact_email' => 'nullable|email|max:180', 'contact_phone' => 'nullable|string|max:40']);
        app('currentTenant')->update($data);

        return back()->with('success', 'Company settings updated.');
    }
}
