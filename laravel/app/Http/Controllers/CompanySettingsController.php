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

        return view('saas.company-settings', ['emailSettings' => auth()->user()->inGroup('superadmin') ? app(\App\Services\CompanyMail::class)->settings(app('currentTenant')->id) : null]);
    }

    public function update(Request $request): RedirectResponse
    {
        abort_unless($request->user()->inGroup('superadmin', 'admin'), 403);
        if ($request->has('email_action')) {
            return $this->emailSettings($request);
        }
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
    private function emailSettings(Request $request): RedirectResponse
    {
        abort_unless($request->user()->inGroup('superadmin'), 403);
        $request->validate(['email_action' => 'required|in:save,test']);
        $tenant = app('currentTenant');
        $mail = app(\App\Services\CompanyMail::class);
        if ($request->input('email_action') === 'test') {
            $key = 'company-mail-test:'.$tenant->id;
            abort_if(\Illuminate\Support\Facades\RateLimiter::tooManyAttempts($key, 3), 429, 'Please wait before sending another test.');
            \Illuminate\Support\Facades\RateLimiter::hit($key, 60);
            try {
                $mail->send($tenant, $request->user()->email, 'Haven email delivery test', 'This test was requested from '.$tenant->name.' email settings.', true);
            } catch (\Throwable $exception) {
                // SMTP exceptions can contain credentials or protocol transcripts.
                return back()->withErrors(['email_delivery' => 'Test failed or delivery is uncertain. Check the saved server, credentials and sender with your provider before retrying.']);
            }

            return back()->with('success', 'Mail server accepted the test for your sign-in email. Check your inbox and spam folder.');
        }
        $data = $request->validate([
            'provider' => 'required|in:cpanel,google,zoho,custom',
            'host' => 'required|string|max:253', 'port' => 'required|integer|in:465,587',
            'username' => 'required|string|max:255', 'password' => 'nullable|string|max:1024',
            'from_address' => 'required|email|max:255', 'from_name' => 'required|string|max:150',
            'enabled' => 'required|boolean',
        ]);
        $data['host'] = strtolower(trim($data['host']));
        $mail->assertPublicHost($data['host']);
        \Illuminate\Support\Facades\DB::transaction(function () use ($data, $tenant, $request): void {
            \App\Models\Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $old = \Illuminate\Support\Facades\DB::table('company_mail_settings')->where('tenant_id', $tenant->id)->first();
            $password = $data['password'] ?? null;
            if (! $password && (! $old || $old->host !== $data['host'] || $old->username !== $data['username'] || $old->provider !== $data['provider'])) {
                throw \Illuminate\Validation\ValidationException::withMessages(['password' => 'Enter the password when setting up or changing the provider, server or username.']);
            }
            unset($data['password']);
            $data['password_encrypted'] = $password ? \Illuminate\Support\Facades\Crypt::encryptString($password) : $old->password_encrypted;
            $data['updated_by'] = $request->user()->id;
            $data['updated_at'] = now();
            $data['created_at'] = $old->created_at ?? now();
            \Illuminate\Support\Facades\DB::table('company_mail_settings')->updateOrInsert(['tenant_id' => $tenant->id], $data);
        });

        return back()->with('success', 'Email settings saved. Send a test email to check delivery.');
    }
}
