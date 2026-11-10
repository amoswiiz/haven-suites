<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Services\CompanyMail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CompanySettingsController extends Controller
{
    public function edit(): View
    {
        abort_unless(auth()->user()->inGroup('superadmin', 'admin'), 403);

        return view('saas.company-settings', ['emailSettings' => auth()->user()->inGroup('superadmin') ? app(CompanyMail::class)->settings(app('currentTenant')->id) : null]);
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
            'reference_prefix' => ['nullable', 'string', 'regex:/^\s*[A-Za-z]{2,5}\s*$/D'],
        ], ['reference_prefix.regex' => 'Use 2 to 5 letters for the enquiry reference prefix, such as HS.']);
        if (array_key_exists('reference_prefix', $data)) {
            $data['reference_prefix'] = $data['reference_prefix'] === null ? null : strtoupper(trim($data['reference_prefix']));
        }
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
        $mail = app(CompanyMail::class);
        if ($request->input('email_action') === 'test') {
            $key = 'company-mail-test:'.$tenant->id;
            abort_if(RateLimiter::tooManyAttempts($key, 3), 429, 'Please wait before sending another test.');
            RateLimiter::hit($key, 60);
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
        DB::transaction(function () use ($data, $tenant, $request): void {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $old = DB::table('company_mail_settings')->where('tenant_id', $tenant->id)->first();
            $password = $data['password'] ?? null;
            if (! $password && (! $old || $old->host !== $data['host'] || $old->username !== $data['username'] || $old->provider !== $data['provider'])) {
                throw ValidationException::withMessages(['password' => 'Enter the password when setting up or changing the provider, server or username.']);
            }
            unset($data['password']);
            $data['password_encrypted'] = $password ? Crypt::encryptString($password) : $old->password_encrypted;
            $data['updated_by'] = $request->user()->id;
            $data['updated_at'] = now();
            $data['created_at'] = $old->created_at ?? now();
            DB::table('company_mail_settings')->updateOrInsert(['tenant_id' => $tenant->id], $data);
        });

        return back()->with('success', 'Email settings saved. Send a test email to check delivery.');
    }
}
