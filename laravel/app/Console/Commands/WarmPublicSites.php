<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\PublicListingCatalog;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('haven:warm-public-sites')]
#[Description('Rebuild the cached list of published stays for every public website, so guests never wait for it')]
class WarmPublicSites extends Command
{
    public function handle(PublicListingCatalog $catalog): int
    {
        $original = config('database.connections.tenant.database');
        $tenantIds = DB::table('public_sites')->where('enabled', true)->pluck('tenant_id');
        foreach (Tenant::whereIn('id', $tenantIds)->where('active', true)->get() as $tenant) {
            app()->instance('currentTenant', $tenant);
            config(['database.connections.tenant.database' => $tenant->database_name]);
            DB::purge('tenant');
            try {
                $this->line($tenant->slug.': '.$catalog->refresh($tenant).' stays ready.');
            } catch (\Throwable $exception) {
                report($exception);
                $this->error($tenant->slug.': could not prepare the stays list.');
            } finally {
                DB::purge('tenant');
                app()->forgetInstance('currentTenant');
            }
        }
        config(['database.connections.tenant.database' => $original]);
        DB::purge('tenant');

        return self::SUCCESS;
    }
}
