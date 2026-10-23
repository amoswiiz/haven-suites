<?php
namespace App\Http\Middleware;
use App\Models\Tenant;
use Closure;
use Illuminate\Support\Facades\DB;
class ResolveTenant
{
    public function handle($request, Closure $next) {
        $tenant = Tenant::where('slug', $request->route('tenant'))->where('active', true)->firstOrFail();
        app()->instance('currentTenant', $tenant);
        $connection = config('database.connections.tenant');
        $connection['database'] = $tenant->database_name;
        config(['database.connections.tenant'=>$connection]);
        DB::purge('tenant');
        try { return $next($request); }
        finally { DB::purge('tenant'); app()->forgetInstance('currentTenant'); app()->forgetScopedInstances(); }
    }
}
