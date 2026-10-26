<?php

namespace App\Http\Controllers;

use App\Support\InvoiceDefaults;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class InvoiceSettingsController extends Controller
{
    public function edit(): View
    {
        abort_unless(auth()->user()->inGroup('admin', 'superadmin'), 403);
        $settings = InvoiceDefaults::settings((array) DB::connection('tenant')->table('invoice_settings')->where('id', 1)->first());
        $defaults = InvoiceDefaults::forCompany($settings);
        if (app('currentTenant')->slug === 'haven' && ! app('currentTenant')->invoice_preferences) {
            $defaults['notes'] = trim(preg_replace('/^Mpesa Payment:.*$/m', '', $defaults['notes']));
            $settings['payment_instructions'] = $settings['payment_instructions'] ?: 'Mpesa Payment: Paybill 247 247 Account 898 694';
        }

        return view('invoices.settings', ['settings' => $settings, 'defaults' => $defaults]);
    }

    public function update(Request $request): RedirectResponse
    {
        abort_unless(auth()->user()->inGroup('admin', 'superadmin'), 403);
        $data = $request->validate([
            'business_name' => 'required|string|max:180', 'business_email' => 'nullable|email|max:180',
            'business_phone' => 'nullable|string|max:500', 'business_website' => 'nullable|url:http,https|max:180',
            'business_address' => 'nullable|string|max:1000', 'payment_instructions' => 'nullable|string|max:5000',
            'default_notes' => 'nullable|string|max:5000', 'default_terms' => 'nullable|string|max:5000',
        ]);
        app('currentTenant')->forceFill(['invoice_preferences' => array_map(static fn ($value): string => trim((string) $value), $data)])->save();

        return back()->with('success', 'Invoice settings saved for this company.');
    }
}
