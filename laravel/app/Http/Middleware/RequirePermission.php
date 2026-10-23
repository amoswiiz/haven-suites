<?php
namespace App\Http\Middleware;
use Closure;
class RequirePermission
{
    public function handle($request, Closure $next, string $permission) {
        abort_unless($request->user()?->can($permission), 403);
        return $next($request);
    }
}
