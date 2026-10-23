<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class SaasAccountController extends Controller
{
    public function create(): View
    {
        return view('saas.register');
    }

    public function store(Request $request): RedirectResponse
    {
        $request->merge(['email' => strtolower(trim((string) $request->input('email')))]);
        $data = $request->validate(['name' => 'required|string|max:150', 'email' => 'required|email|max:180|unique:users,email', 'password' => ['required', 'confirmed', Password::min(12)->mixedCase()->numbers()]]);
        $user = new User(['name' => $data['name'], 'username' => 'host_'.strtolower(Str::random(18)), 'email' => strtolower($data['email']), 'password' => $data['password'], 'active' => true]);
        $user->requires_email_verification = true;
        $user->save();
        Auth::login($user);
        $request->session()->regenerate();
        event(new Registered($user));

        return redirect()->route('verification.notice');
    }

    public function notice(): View
    {
        return view('saas.verify');
    }

    public function verify(Request $request, string $id, string $hash): RedirectResponse
    {
        abort_unless((string) $request->user()->id === $id && hash_equals(sha1($request->user()->getEmailForVerification()), $hash), 403);
        if (! $request->user()->hasVerifiedEmail()) {
            $request->user()->markEmailAsVerified();
            event(new Verified($request->user()));
        }
        $intended = $request->session()->pull('saas.invitation_return', $request->session()->pull('url.intended'));
        if (is_string($intended) && preg_match('#^/invitations/([a-f0-9]{64})$#', (string) parse_url($intended, PHP_URL_PATH), $match)) {
            return redirect()->route('invitations.show', $match[1]);
        }

        return redirect()->route('onboarding.company');
    }

    public function resend(Request $request): RedirectResponse
    {
        if (! $request->user()->hasVerifiedEmail()) {
            $request->user()->sendEmailVerificationNotification();
        }

        return back()->with('success', 'A new verification link has been sent.');
    }
}
