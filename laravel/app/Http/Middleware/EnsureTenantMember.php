<?php
namespace App\Http\Middleware;
use Closure;
class EnsureTenantMember
{
    public function handle($request, Closure $next) {
        abort_unless($request->user()?->active && $request->user()->tenants()->whereKey(app('currentTenant')->id)->exists(), 403);
        return $next($request);
    }
}
