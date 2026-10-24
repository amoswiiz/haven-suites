<?php

namespace App\Console\Commands;

use App\Services\InvestorNotifications;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DeliverInvestorNotifications extends Command
{
    protected $signature = 'haven:deliver-investor-notifications {--limit=100}';

    protected $description = 'Process pending investor email and SMS notifications, respecting company settings and preferences';

    public function handle(InvestorNotifications $notifications): int
    {
        $ids = DB::table('investor_deliveries')->whereIn('status', ['pending', 'failed', 'blocked'])->where('attempts', '<', 5)->where(function ($query): void {
            $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now());
        })->orderBy('id')->limit(max(1, min(1000, (int) $this->option('limit'))))->pluck('id');
        foreach ($ids as $id) {
            $notifications->deliver((int) $id);
        }
        $this->info('Processed '.$ids->count().' investor notification deliveries.');

        return self::SUCCESS;
    }
}
