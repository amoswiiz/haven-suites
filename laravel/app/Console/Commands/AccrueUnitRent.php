<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\UnitAccounting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AccrueUnitRent extends Command
{
    protected $signature = 'haven:accrue-rent {--month=}';

    protected $description = 'Post monthly lease rent as unpaid operating expenses without duplicating existing charges';

    public function handle(): int
    {
        $month = $this->option('month') ?: now('Africa/Nairobi')->format('Y-m');
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $month)) {
            $this->error('Invalid month.');

            return self::FAILURE;
        }
        $failed = false;
        $original = config('database.connections.tenant.database');
        foreach (Tenant::where('active', true)->get() as $tenant) {
            if (! ($tenant->modules['finance'] ?? true)) {
                continue;
            }
            app()->instance('currentTenant', $tenant);
            config(['database.connections.tenant.database' => $tenant->database_name]);
            DB::purge('tenant');
            try {
                $service = new UnitAccounting;
                $arrangements = DB::table('unit_operating_arrangements')->where('tenant_id', $tenant->id)->where('mode', 'leased')->where('starts_on', '<=', $month.'-01')->get();
                foreach ($arrangements as $arrangement) {
                    $from = $this->option('month') ? $month.'-01' : $arrangement->starts_on;
                    $until = min($month.'-01', $arrangement->ends_on ?? $month.'-01');
                    $count = 0;
                    for ($cursor = $from; $cursor <= $until; $cursor = date('Y-m-d', strtotime($cursor.' +1 month'))) {
                        if (++$count > 1200) {
                            $failed = true;
                            $this->error('Lease history requires manual reconciliation.');
                            break;
                        }
                        try {
                            $service->postRent((int) $arrangement->unit_id, substr($cursor, 0, 7));
                        } catch (\Throwable $e) {
                            $failed = true;
                            $this->error($tenant->slug.' / unit '.$arrangement->unit_id.' / '.$cursor.': '.$e->getMessage());
                        }
                    }
                }
            } finally {
                DB::purge('tenant');
                app()->forgetInstance('currentTenant');
            }
        }
        config(['database.connections.tenant.database' => $original]);
        $this->info('Monthly rent accrual finished.');

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
