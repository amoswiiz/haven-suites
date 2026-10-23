<?php
namespace App\Support;
use Illuminate\Http\Request;
class RouteAccess
{
    public function allows(string $url, string $method = 'GET'): bool {
        $user=auth()->user();
        if (!app()->bound('currentTenant')) return false;
        $path=parse_url($url,PHP_URL_PATH);
        if (!is_string($path)) return false;
        $prefix='/t/'.app('currentTenant')->slug;
        if ($path!==$prefix && !str_starts_with($path,$prefix.'/')) return false;
        $probe=Request::create($url,strtoupper($method));
        foreach (app('router')->getRoutes() as $route) {
            if (!$route->matches($probe)) continue;
            $middlewareList=$route->gatherMiddleware();
            if (in_array('auth',$middlewareList,true) && (!$user || !$user->active || !$user->tenants()->whereKey(app('currentTenant')->id)->exists())) return false;
            foreach ($route->gatherMiddleware() as $middleware) {
                if (str_starts_with($middleware,'permission:') && !$user?->can(substr($middleware,11))) return false;
            }
            if (str_contains($path,'/housekeeping/my-cleanings') || str_ends_with($path,'/payments/cleaners/earnings')) {
                return $user->inGroup('cleaner','admin','superadmin') || $user->can('housekeeping.manage');
            }
            return true;
        }
        return false;
    }
}
