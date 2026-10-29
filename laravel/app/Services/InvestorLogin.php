<?php

namespace App\Services;

use App\Models\User;
use App\Models\CompanyInvitation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InvestorLogin
{
    // Called inside the controller's central and tenant transactions.
    public function createOrLink(string $name, string $email, Request $request): User
    {
        abort_unless($request->user()->can('investors.manage'), 403);
        $company = app('currentTenant');
        abort_unless($company->modules['team'] ?? true, 403);
        $user = User::whereRaw('LOWER(email) = ?', [$email])->lockForUpdate()->first();
        if ($user) {
            $member = $company->users()->whereKey($user->id)->first();
            $roles = $member ? (json_decode($member->pivot->roles, true) ?: []) : [];
            if (! $member || ! $user->active || $user->is_platform_admin || ! $user->hasVerifiedEmail() || ! in_array('owner', $roles, true)) {
                throw ValidationException::withMessages(['email' => 'This email already has an account. It must be an active, verified Owner in this company before it can be linked. Its password will not be changed.']);
            }
            if (DB::table('investors')->where('tenant_id', $company->id)->where('user_id', $user->id)->exists()) {
                throw ValidationException::withMessages(['email' => 'This login is already linked to an investor in this company.']);
            }

            return $user;
        }

        $data = $request->validate([
            'password' => 'required|string|min:12|max:128|confirmed',
            'email_confirmed' => 'accepted',
        ]);
        $pending = CompanyInvitation::where('tenant_id', $company->id)->where('email', '!=', $email)
            ->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now())->count();
        app(CompanyMembership::class)->assertCapacity($company, $pending);
        $user = new User;
        $user->name = $name;
        $user->username = 'owner_'.Str::lower(Str::random(20));
        $user->email = $email;
        $user->password = $data['password'];
        $user->active = true;
        $user->requires_email_verification = false;
        $user->email_verified_at = now();
        $user->save();
        app(CompanyMembership::class)->attach($company, $user, 'owner');

        return $user;
    }
}
