<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Support\CompanyModules;
use Closure;
use Illuminate\Support\Facades\DB;

class ResolveTenant
{
    public function handle($request, Closure $next)
    {
        $tenant = Tenant::where('slug', $request->route('tenant'))->where('active', true)->firstOrFail();
        app()->instance('currentTenant', $tenant);
        $connection = config('database.connections.tenant');
        $connection['database'] = $tenant->database_name;
        config(['database.connections.tenant' => $connection]);
        DB::purge('tenant');
        try {
            abort_unless(CompanyModules::allowsPath($request->path()), 403, 'This module is not enabled for your company.');

            return $next($request);
        } finally {
            DB::purge('tenant');
            app()->forgetInstance('currentTenant');
            app()->forgetScopedInstances();
        }
    }
}
