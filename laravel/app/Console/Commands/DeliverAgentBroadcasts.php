<?php

namespace App\Console\Commands;

use App\Services\AgentBroadcasts;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DeliverAgentBroadcasts extends Command
{
    protected $signature = 'haven:deliver-agent-broadcasts {--limit=200}';

    protected $description = 'Send queued broadcast messages to referral agents';

    public function handle(AgentBroadcasts $broadcasts): int
    {
        $ids = DB::table('agent_broadcast_recipients')->whereIn('status', ['pending', 'failed'])->where('attempts', '<', 5)
            ->where(fn ($query) => $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->orderBy('id')->limit(max(1, min(1000, (int) $this->option('limit'))))->pluck('id');
        foreach ($ids as $id) {
            $broadcasts->deliver((int) $id);
        }
        $this->info('Processed '.$ids->count().' agent broadcast messages.');

        return self::SUCCESS;
    }
}
