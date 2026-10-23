<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthController
{
    public function form()
    {
        return view('auth.login');
    }

    public function login(Request $request)
    {
        $request->merge(['email' => strtolower(trim((string) $request->input('email')))]);
        $credentials = $request->validate(['email' => 'required|email', 'password' => 'required|string']);
        if (! Auth::attempt([...$credentials, 'active' => true], $request->boolean('remember'))) {
            return back()->withErrors(['email' => 'The supplied login details are incorrect.'])->onlyInput('email');
        }
        $request->session()->regenerate();
        $intended = $request->session()->pull('url.intended');
        if (is_string($intended) && preg_match('#^/invitations/([a-f0-9]{64})$#', (string) parse_url($intended, PHP_URL_PATH), $match)) {
            $request->session()->put('saas.invitation_return', route('invitations.show', $match[1]));
        }
        if ($request->user()->requires_email_verification && ! $request->user()->hasVerifiedEmail()) {
            return redirect()->route('verification.notice');
        }

        return redirect($request->session()->pull('saas.invitation_return', '/'));
    }

    public function home(Request $request)
    {
        if ($request->user()->requires_email_verification && ! $request->user()->hasVerifiedEmail()) {
            return redirect()->route('verification.notice');
        }
        if ($request->user()->is_platform_admin) {
            return redirect()->route('platform.index');
        }
        $tenants = $request->user()->tenants()->where('active', true)->get();
        if (! $request->user()->tenants()->exists()) {
            return redirect()->route('onboarding.company');
        }
        if ($tenants->count() === 1) {
            app()->instance('currentTenant', $tenants->first());
            $landing = haven_landing();
            app()->forgetInstance('currentTenant');

            return redirect($landing);
        }

        return view('auth.tenants', compact('tenants'));
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }
}
