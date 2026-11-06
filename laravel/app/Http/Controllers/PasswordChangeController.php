<?php

namespace App\Http\Controllers;

use App\Models\User;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password as PasswordBroker;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class PasswordChangeController extends Controller
{
    public function edit(Request $request): Response
    {
        $this->authorizeAccount($request);

        return response()->view('saas.password-change')->header('Cache-Control', 'private, no-store');
    }

    public function update(Request $request): RedirectResponse
    {
        $this->authorizeAccount($request);
        $data = $request->validate([
            'current_password' => ['required', 'string', 'max:128', 'current_password'],
            'password' => ['required', 'string', 'max:128', 'confirmed', 'different:current_password', Password::min(12)->mixedCase()->numbers(), function (string $attribute, mixed $value, Closure $fail): void {
                if (config('hashing.driver', 'bcrypt') === 'bcrypt' && is_string($value) && strlen($value) > 72) {
                    $fail('Choose a shorter password; accented characters and emoji count toward the limit.');
                }
            }],
        ]);
        $account = $request->user();
        $updated = $account->getConnection()->transaction(function () use ($account, $data, $request): User {
            $user = User::whereKey($account->id)->lockForUpdate()->firstOrFail();
            abort_unless($user->active, 403);
            if (! Hash::check($data['current_password'], $user->password)) {
                throw ValidationException::withMessages(['current_password' => 'Your current password has changed. Enter the latest password and try again.']);
            }
            $user->forceFill(['password' => $data['password'], 'remember_token' => Str::random(60)])->save();
            PasswordBroker::broker()->deleteToken($user);
            if (config('session.driver') === 'database') {
                DB::connection(config('session.connection'))->table(config('session.table', 'sessions'))
                    ->where('user_id', $user->id)->where('id', '!=', $request->session()->getId())->delete();
            }

            return $user;
        });
        Auth::guard()->setUser($updated);
        $request->session()->regenerate();

        return redirect()->route('account.password.edit')->with('success', 'Your password has been changed. You are still signed in on this device.');
    }

    private function authorizeAccount(Request $request): void
    {
        abort_unless($request->user()?->active, 403);
        abort_if($request->session()->has('haven.impersonation'), 403, 'Return to your own account before changing your password.');
    }
}
