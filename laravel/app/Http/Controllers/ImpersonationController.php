<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ImpersonationController extends Controller
{
    private function companies(Request $request)
    {
        abort_unless($request->user()->active, 403);
        if ($request->user()->is_platform_admin) {
            return Tenant::where('active', true)->get();
        }
        return $request->user()->tenants()->where('active', true)->get()->filter(
            fn ($company) => in_array('superadmin', json_decode($company->pivot->roles, true) ?: [], true)
        );
    }

    public function index(Request $request)
    {
        $companies = $this->companies($request);
        abort_if($companies->isEmpty(), 403);
        $selected = $companies->firstWhere('id', (int) $request->input('company', $companies->first()->id));
        abort_unless($selected, 403);
        return view('impersonation.index', [
            'companies' => $companies,
            'selected' => $selected,
            'members' => $selected->users()->where('users.active', true)->where('is_platform_admin', false)->where('users.id', '!=', $request->user()->id)->get(),
        ]);
    }

    public function start(Request $request)
    {
        abort_if($request->session()->has('haven.impersonation'), 403);
        $data = $request->validate(['company' => 'required|integer', 'user' => 'required|integer', 'reason' => 'required|string|max:300']);
        $company = $this->companies($request)->firstWhere('id', (int) $data['company']);
        abort_unless($company, 403);
        $target = $company->users()->whereKey($data['user'])->where('users.active', true)->firstOrFail();
        abort_if($target->is_platform_admin || $target->id === $request->user()->id, 403);
        abort_if(!$request->user()->is_platform_admin && in_array('superadmin', json_decode($target->pivot->roles, true) ?: [], true), 403);
        abort_if($target->requires_email_verification && !$target->hasVerifiedEmail(), 403, 'The user must verify their email first.');
        $state = ['actor_id' => $request->user()->id, 'target_id' => $target->id, 'tenant_id' => $company->id, 'reason' => $data['reason'], 'started_at' => now()->toIso8601String()];
        Log::notice('impersonation.started', $state);
        $request->session()->regenerate();
        $request->session()->put('haven.impersonation', $state);
        $actor = $request->user();
        app()->instance('currentTenant', $company);
        \Illuminate\Support\Facades\Auth::guard()->setUser($target);
        try {
            $landing = haven_landing();
        } finally {
            \Illuminate\Support\Facades\Auth::guard()->setUser($actor);
            app()->forgetInstance('currentTenant');
        }
        return redirect($landing);
    }

    public function stop(Request $request)
    {
        $request->session()->forget('haven.impersonation');
        $request->session()->regenerate();
        return redirect('/')->with('success', 'Returned to your account.');
    }
}
