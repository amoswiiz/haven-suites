<?php

namespace App\Http\Controllers;

use App\Models\CompanyInvitation;
use App\Models\Tenant;
use App\Notifications\CompanyInvitationNotification;
use App\Services\CompanyMembership;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CompanyInvitationController extends Controller
{
    public function index(): View
    {
        abort_unless(auth()->user()->inGroup('superadmin', 'admin'), 403);

        return view('saas.team', ['members' => app('currentTenant')->users()->get(), 'invitations' => CompanyInvitation::where('tenant_id', app('currentTenant')->id)->latest()->get()]);
    }

    public function store(Request $request, CompanyMembership $membership): RedirectResponse
    {
        abort_unless($request->user()->inGroup('superadmin', 'admin'), 403);
        $data = $request->validate(['email' => 'required|email|max:180', 'role' => ['required', Rule::in(config('saas.invitable_roles'))]]);
        abort_if($data['role'] === 'admin' && ! $request->user()->inGroup('superadmin'), 403);
        $data['email'] = strtolower($data['email']);
        $tenant = app('currentTenant');
        $token = bin2hex(random_bytes(32));
        $invitation = DB::transaction(function () use ($tenant, $data, $membership, $token): CompanyInvitation {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $pending = CompanyInvitation::where('tenant_id', $tenant->id)->where('email', '!=', $data['email'])->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now())->count();
            $membership->assertCapacity($tenant, $pending);
            if ($tenant->users()->where('email', $data['email'])->exists()) {
                throw ValidationException::withMessages(['email' => 'This person is already a company member.']);
            }
            CompanyInvitation::where('tenant_id', $tenant->id)->where('email', $data['email'])->whereNull('accepted_at')->update(['revoked_at' => now()]);

            return CompanyInvitation::create([...$data, 'tenant_id' => $tenant->id, 'invited_by' => auth()->id(), 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDays(7)]);
        });
        try {
            Notification::route('mail', $data['email'])->notify(new CompanyInvitationNotification($tenant->name, route('invitations.show', $token)));
        } catch (\Throwable $exception) {
            $invitation->update(['revoked_at' => now()]);
            report($exception);

            return back()->withErrors(['email' => 'Invitation delivery failed. Check mail configuration and try again.']);
        }

        return back()->with('success', 'Invitation sent. The recipient must sign in with the invited email address.');
    }

    public function revoke(string $tenant, int $id): RedirectResponse
    {
        abort_unless(auth()->user()->inGroup('superadmin', 'admin'), 403);
        CompanyInvitation::where('tenant_id', app('currentTenant')->id)->whereKey($id)->whereNull('accepted_at')->firstOrFail()->update(['revoked_at' => now()]);

        return back()->with('success', 'Invitation revoked.');
    }

    public function show(string $token): View
    {
        return view('saas.invitation', ['invitation' => $this->invitation($token), 'token' => $token]);
    }

    public function updateMember(string $tenant, int $id, Request $request): RedirectResponse
    {
        abort_unless($request->user()->inGroup('superadmin', 'admin'), 403);
        $data = $request->validate(['role' => ['required', Rule::in(config('saas.invitable_roles'))]]);
        $this->changeMember($id, $data['role']);

        return back()->with('success', 'Company role updated.');
    }

    public function removeMember(string $tenant, int $id): RedirectResponse
    {
        abort_unless(auth()->user()->inGroup('superadmin', 'admin'), 403);
        $this->changeMember($id, null);

        return back()->with('success', 'Company access removed. Historical records have been preserved.');
    }

    private function changeMember(int $id, ?string $role): void
    {
        abort_if($id === auth()->id(), 403, 'You cannot change your own access here.');
        DB::transaction(function () use ($id, $role): void {
            $tenant = Tenant::whereKey(app('currentTenant')->id)->lockForUpdate()->firstOrFail();
            $member = $tenant->users()->whereKey($id)->firstOrFail();
            $roles = json_decode($member->pivot->roles, true);
            abort_if(in_array('superadmin', $roles, true), 403, 'Company ownership cannot be removed here.');
            abort_if((in_array('admin', $roles, true) || $role === 'admin') && ! auth()->user()->inGroup('superadmin'), 403);
            DB::connection('tenant')->transaction(function () use ($tenant, $member, $role): void {
                DB::connection('tenant')->table('staff')->where('user_id', $member->pivot->legacy_user_id)->update($role ? ['role' => $role] : ['status' => 'inactive']);
                if ($role) {
                    $tenant->users()->updateExistingPivot($member->id, ['roles' => json_encode([$role])]);
                } else {
                    DB::connection('tenant')->table('users')->where('id', $member->pivot->legacy_user_id)->update(['active' => 0]);
                    $tenant->users()->detach($member->id);
                }
            });
        });
    }

    public function accept(string $token, Request $request, CompanyMembership $membership): RedirectResponse
    {
        $invitation = $this->invitation($token);
        abort_unless(strtolower($request->user()->email) === strtolower($invitation->email), 403, 'Sign in using the invited email address.');
        $tenant = $invitation->tenant;
        abort_unless($tenant->active, 403);
        abort_unless($tenant->modules['team'] ?? true, 403, 'Team management is not enabled for this company.');
        $original = config('database.connections.tenant');
        config(['database.connections.tenant.database' => $tenant->database_name]);
        DB::purge('tenant');
        try {
            DB::transaction(function () use ($invitation, $tenant, $request, $membership): void {
                Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
                $locked = CompanyInvitation::whereKey($invitation->id)->lockForUpdate()->firstOrFail();
                abort_if($locked->accepted_at || $locked->revoked_at || $locked->expires_at->isPast(), 410);
                if ($locked->investor_id) {
                    abort_unless($tenant->modules['investors'] ?? true, 403);
                    $profile = DB::table('investors')->where('tenant_id', $tenant->id)->where('id', $locked->investor_id)->where('active', true)->where('email', strtolower($request->user()->email))->firstOrFail();
                    abort_unless($request->user()->hasVerifiedEmail() && $locked->role === 'owner', 403);
                    DB::table('investors')->where('id', $profile->id)->update(['user_id' => $request->user()->id, 'updated_at' => now()]);
                }
                $membership->attach($tenant, $request->user(), $locked->role);
                $locked->update(['accepted_at' => now()]);
            });
        } finally {
            DB::purge('tenant');
            config(['database.connections.tenant' => $original]);
        }

        $destination = $invitation->investor_id ? '/investor-portal' : ($invitation->role === 'admin' && ! $tenant->onboarding_completed_at ? '/company/setup' : '/home');

        return redirect('/t/'.$tenant->slug.$destination)->with('success', 'You have joined '.$tenant->name.'.');
    }

    private function invitation(string $token): CompanyInvitation
    {
        abort_unless(preg_match('/^[a-f0-9]{64}$/D', $token), 404);
        $invitation = CompanyInvitation::with('tenant')->where('token_hash', hash('sha256', $token))->firstOrFail();
        abort_if($invitation->accepted_at || $invitation->revoked_at || $invitation->expires_at->isPast(), 410);

        return $invitation;
    }
}
