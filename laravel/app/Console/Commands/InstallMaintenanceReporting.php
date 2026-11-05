<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\MaintenanceReportSchema;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class InstallMaintenanceReporting extends Command
{
    protected $signature = 'haven:install-maintenance-reporting {tenant : Company slug}';

    protected $description = 'Add maintenance reporter attribution to one company without changing existing records';

    public function handle(MaintenanceReportSchema $schema): int
    {
        $tenant = Tenant::where('slug', $this->argument('tenant'))->firstOrFail();
        $config = config('database.connections.tenant');
        $config['database'] = $tenant->database_name;
        $db = DB::build($config);
        try {
            $schema->install($db);
        } finally {
            $db->disconnect();
        }
        $this->info('Maintenance reporting ready for '.$tenant->slug.'.');

        return self::SUCCESS;
    }
}
