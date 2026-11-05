<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\StaffBillSchema;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class InstallStaffBills extends Command
{
    protected $signature = 'haven:install-staff-bills {tenant : Company slug}';

    protected $description = 'Add bill submission tables to one company without changing existing records';

    public function handle(StaffBillSchema $schema): int
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
        $this->info('Bill submission tables ready for '.$tenant->slug.'.');

        return self::SUCCESS;
    }
}
