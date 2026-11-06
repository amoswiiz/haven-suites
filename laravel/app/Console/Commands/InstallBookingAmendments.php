<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\BookingAmendmentSchema;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class InstallBookingAmendments extends Command
{
    protected $signature = 'haven:install-booking-amendments {tenant : Company slug}';

    protected $description = 'Install booking and invoice amendment history for one company without changing bookings';

    public function handle(BookingAmendmentSchema $schema): int
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
        $this->info('Booking amendment history ready for '.$tenant->slug.'.');

        return self::SUCCESS;
    }
}
