<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class TenantWriteAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('GET') || $request->isMethod('HEAD') || ! $request->user()) {
            return $next($request);
        }
        $tenant = app('currentTenant');
        $valid = $tenant->subscription_status === 'legacy'
            || ($tenant->subscription_status === 'trial' && $tenant->trial_ends_at?->isFuture())
            || ($tenant->subscription_status === 'active' && $tenant->subscription_ends_at?->isFuture());
        if (! $valid && ! $request->routeIs('company.plan.request', 'company.billing.start', 'company.billing.evidence')) {
            throw ValidationException::withMessages(['subscription' => 'Your subscription needs renewal. You can view existing records and request a plan from Company settings.']);
        }
        $path = preg_replace('#^t/[^/]+/#', '', $request->path());
        if ($tenant->plan !== 'legacy' && preg_match('#^staff/\d+/account/store$#', $path)) {
            throw ValidationException::withMessages(['team' => 'Use Company settings → Team invitations to add company logins.']);
        }
        $limited = in_array($path, ['units/store', 'onboarding'], true);
        if ($limited) {
            return DB::transaction(function () use ($request, $next, $tenant): Response {
                $tenant->newQuery()->whereKey($tenant->id)->lockForUpdate()->firstOrFail();
                $limit = config('saas.plans.'.$tenant->plan.'.units');
                if ($limit !== null && DB::connection('tenant')->table('units')->count() >= $limit) {
                    throw ValidationException::withMessages(['plan' => 'Your plan has reached its apartment limit. Request a larger plan.']);
                }

                return $next($request);
            });
        }

        return $next($request);
    }
}
