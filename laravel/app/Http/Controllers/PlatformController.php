<?php

namespace App\Http\Controllers;

use App\Models\SubscriptionPayment;
use App\Models\Tenant;
use App\Support\CompanyModules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PlatformController extends Controller
{
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
        return view('saas.platform', ['companies' => Tenant::withCount('users')->latest()->paginate(20), 'moduleEvents' => DB::table('platform_audit_events')->leftJoin('users', 'users.id', '=', 'platform_audit_events.actor_id')->where('action', 'company.modules.updated')->select('platform_audit_events.*', 'users.name as actor_name')->latest('platform_audit_events.id')->limit(100)->get(), 'payments' => SubscriptionPayment::with('tenant')->where('provider', 'manual')->where('status', 'pending')->latest()->limit(50)->get()]);
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
