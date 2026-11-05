<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\ExpenseCorrectionSchema;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class InstallExpenseCorrections extends Command
{
    protected $signature = 'haven:install-expense-corrections {tenant : Company slug}';

    protected $description = 'Install expense correction history for one company without changing expenses';

    public function handle(ExpenseCorrectionSchema $schema): int
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
        $this->info('Expense correction history ready for '.$tenant->slug.'.');

        return self::SUCCESS;
    }
}
