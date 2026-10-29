<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class ImpersonateUser
{
    public function handle($request, Closure $next)
    {
        $state = $request->session()->get('haven.impersonation');
        if (!$state) {
            return $next($request);
        }
        // The persisted login remains the administrator. Only this request's
        // guard identity changes, so no target remember-me cookie is issued.
        $actor = Auth::user();
        $company = Tenant::whereKey($state['tenant_id'])->where('active', true)->first();
        $membership = $actor && $company ? $actor->tenants()->whereKey($company->id)->first() : null;
        $allowed = $actor && $actor->active && (int) $actor->id === (int) $state['actor_id']
            && ($actor->is_platform_admin || in_array('superadmin', json_decode($membership?->pivot->roles ?? '[]', true) ?: [], true));
        if (!$allowed || !$company) {
            $request->session()->forget('haven.impersonation');
            abort(403, 'Impersonation access has expired.');
        }
        if ($request->is('impersonation/stop') || $request->is('logout')) {
            Log::notice('impersonation.ended', $state);
            $request->session()->forget('haven.impersonation');
            $request->session()->regenerate();
            return $next($request);
        }
        if (now()->timestamp - strtotime($state['started_at']) > 1800) {
            Log::notice('impersonation.expired', $state);
            $request->session()->forget('haven.impersonation');
            $request->session()->regenerate();
            return redirect('/')->with('success', 'The viewing session expired. You are back in your account.');
        }
        $target = User::whereKey($state['target_id'])->where('active', true)->first();
        if (!$target || $target->is_platform_admin || !$target->tenants()->whereKey($company->id)->exists()) {
            $request->session()->forget('haven.impersonation');
            abort(403, 'The selected user is no longer eligible.');
        }
        $targetMembership = $target->tenants()->whereKey($company->id)->first();
        if (!$actor->is_platform_admin && in_array('superadmin', json_decode($targetMembership->pivot->roles, true) ?: [], true)) {
            $request->session()->forget('haven.impersonation');
            abort(403, 'The selected user is no longer eligible.');
        }
        if ($request->is('/')) {
            app()->instance('currentTenant', $company);
            Auth::guard()->setUser($target);
            try {
                $landing = haven_landing();
            } finally {
                Auth::guard()->setUser($actor);
                app()->forgetInstance('currentTenant');
            }
            return redirect($landing);
        }
        // Global account, password, invitation and platform endpoints are not
        // available while acting as another user.
        $prefix = 't/'.$company->slug;
        if (!$request->is($prefix, $prefix.'/*')) {
            return response()->view('impersonation.blocked', [], 403);
        }
        if ($request->is($prefix.'/company/*', $prefix.'/onboarding*', $prefix.'/staff/*', $prefix.'/investor-portal/preferences', $prefix.'/investor-portal/contact*') && !$request->isMethod('GET')) {
            return response()->view('impersonation.blocked', [], 403);
        }
        // Keep writes disabled until every business action has been reviewed
        // for account-security side effects. GET permissions still use target.
        if (!$request->isMethod('GET') && !$request->isMethod('HEAD')) {
            return response()->view('impersonation.blocked', [], 403);
        }
        Auth::guard()->setUser($target);
        try {
            Log::info('impersonation.request', [...$state, 'method' => $request->method(), 'path' => $request->path()]);
            $response = $next($request);
            $response->headers->set('Cache-Control', 'private, no-store');
            return $response;
        } finally {
            Auth::guard()->setUser($actor);
        }
    }
}
