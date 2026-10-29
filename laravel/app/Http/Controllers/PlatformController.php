<?php

namespace App\Http\Controllers;

use App\Models\CompanyInvitation;
use App\Models\SubscriptionPayment;
use App\Models\Tenant;
use App\Notifications\CompanyInvitationNotification;
use App\Services\TenantProvisioner;
use App\Support\CompanyModules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PlatformController extends Controller
{
    public function create(): View
    {
        return view('saas.platform-company-create');
    }

    public function store(Request $request, TenantProvisioner $provisioner): RedirectResponse
    {
        $request->merge(['admin_email' => strtolower(trim((string) $request->input('admin_email')))]);
        $data = $request->validate([
            'name' => 'required|string|max:150',
            'slug' => 'required|string|min:3|max:50|regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/|unique:tenants,slug',
            'admin_email' => 'required|email|max:180',
            'contact_email' => 'nullable|email|max:180', 'contact_phone' => 'nullable|string|max:40',
            'plan' => ['required', Rule::in(array_keys(config('saas.plans')))],
            'modules' => 'required|array', 'modules.*' => ['string', Rule::in(array_keys(CompanyModules::LABELS))],
        ]);
        if (! in_array('team', $data['modules'], true)) {
            throw ValidationException::withMessages(['modules' => 'Enable Team management so the administrator can accept the invitation.']);
        }
        $modules = [];
        foreach (CompanyModules::LABELS as $key => $label) {
            $modules[$key] = in_array($key, $data['modules'], true);
        }
        $token = bin2hex(random_bytes(32));
        try {
            $tenant = $provisioner->provision(null, $data['name'], $data['slug'], [...$data, 'modules' => $modules, 'contact_email' => ($data['contact_email'] ?? null) ?: $data['admin_email'], 'invited_by' => $request->user()->id, 'token_hash' => hash('sha256', $token)]);
        } catch (\Throwable $exception) {
            report($exception);

            return back()->withInput()->withErrors(['company' => 'Company setup could not finish. Please retry; existing companies were not changed.']);
        }

        return $this->sendAdministratorInvitation($tenant, $data['admin_email'], $token);
    }

    public function resendInvitation(int $id): RedirectResponse
    {
        $token = bin2hex(random_bytes(32));
        $invitation = DB::transaction(function () use ($id, $token): CompanyInvitation {
            $invitation = CompanyInvitation::whereKey($id)->where('role', 'admin')->whereNull('accepted_at')->lockForUpdate()->firstOrFail();
            abort_unless($invitation->tenant->active && ($invitation->tenant->modules['team'] ?? true), 422, 'Enable company access and Team management before resending.');
            $invitation->update(['token_hash' => hash('sha256', $token), 'revoked_at' => null, 'expires_at' => now()->addDays(7)]);
            DB::table('platform_audit_events')->insert(['tenant_id' => $invitation->tenant_id, 'actor_id' => auth()->id(), 'action' => 'company.administrator.reinvited', 'details' => json_encode(['invitation_id' => $invitation->id]), 'created_at' => now(), 'updated_at' => now()]);

            return $invitation;
        });

        return $this->sendAdministratorInvitation($invitation->tenant, $invitation->email, $token);
    }

    private function sendAdministratorInvitation(Tenant $tenant, string $email, string $token): RedirectResponse
    {
        try {
            Notification::route('mail', $email)->notify(new CompanyInvitationNotification($tenant->name, route('invitations.show', $token)));
        } catch (\Throwable $exception) {
            report($exception);

            return redirect()->route('platform.index')->withErrors(['invitation' => 'Company saved, but the administrator invitation could not be sent. Check email settings, then use Resend invitation.']);
        }

        return redirect()->route('platform.index')->with('success', 'Company ready. Administrator invitation requested; delivery requires a configured email service. The invitation expires in 7 days.');
    }

    public function modules(int $id, Request $request): RedirectResponse
    {
        $data = $request->validate(['modules' => 'nullable|array', 'modules.*' => ['string', Rule::in(array_keys(CompanyModules::LABELS))], 'reason' => 'required|string|max:500']);
        DB::transaction(function () use ($id, $data): void {
            $tenant = Tenant::whereKey($id)->lockForUpdate()->firstOrFail();
            $before = $tenant->modules;
            $modules = [];
            foreach (CompanyModules::LABELS as $key => $label) {
                $modules[$key] = in_array($key, $data['modules'] ?? [], true);
            }
            $tenant->modules = $modules;
            $tenant->save();
            DB::table('platform_audit_events')->insert(['tenant_id' => $id, 'actor_id' => auth()->id(), 'action' => 'company.modules.updated', 'details' => json_encode(['before' => $before, 'after' => $modules, 'reason' => $data['reason']]), 'created_at' => now(), 'updated_at' => now()]);
        });

        return back()->with('success', 'Company modules updated. Existing records have been preserved.');
    }

    public function index(): View
    {
        return view('saas.platform', ['administratorInvitations' => CompanyInvitation::where('role', 'admin')->whereNull('accepted_at')->get(), 'companies' => Tenant::withCount('users')->latest()->paginate(20), 'moduleEvents' => DB::table('platform_audit_events')->leftJoin('users', 'users.id', '=', 'platform_audit_events.actor_id')->where('action', 'company.modules.updated')->select('platform_audit_events.*', 'users.name as actor_name')->latest('platform_audit_events.id')->limit(100)->get(), 'payments' => SubscriptionPayment::with('tenant')->where('provider', 'manual')->where('status', 'pending')->latest()->limit(50)->get()]);
    }

    public function update(int $id, Request $request): RedirectResponse
    {
        $tenant = Tenant::findOrFail($id);
        $data = $request->validate([
            'active' => 'required|boolean', 'plan' => ['required', Rule::in(['legacy', ...array_keys(config('saas.plans'))])],
            'subscription_status' => ['required', Rule::in(['legacy', 'trial', 'active', 'expired'])],
            'subscription_ends_at' => 'exclude_unless:subscription_status,active|required|date|after:now',
            'trial_ends_at' => 'exclude_unless:subscription_status,trial|required|date|after:now',
        ]);
        DB::transaction(function () use ($tenant, $data): void {
            $tenant = Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $before = $tenant->only(array_keys($data));
            $tenant->update([...$data, 'requested_plan' => null]);
            DB::table('platform_audit_events')->insert(['tenant_id' => $tenant->id, 'actor_id' => auth()->id(), 'action' => 'company.updated', 'details' => json_encode(['before' => $before, 'after' => $data]), 'created_at' => now(), 'updated_at' => now()]);
        });

        return back()->with('success', 'Company access and subscription updated.');
    }
}
