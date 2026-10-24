<?php

namespace App\Console\Commands;

use App\Services\RoyceTalkSms;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncSmsStatuses extends Command
{
    protected $signature = 'haven:sync-sms-status {--limit=100}';

    protected $description = 'Check accepted RoyceTalk SMS deliveries without resending messages';

    public function handle(RoyceTalkSms $transport): int
    {
        if ((! config('investors.live_delivery') && ! config('services.roycetalk.manual_live')) || ! $transport->configured()) {
            $this->info('RoyceTalk status checks are disabled or not configured.');

            return self::SUCCESS;
        }
        $tables = [];
        if (config('investors.live_delivery')) {
            $tables[] = 'investor_deliveries';
        }
        if (config('services.roycetalk.manual_live')) {
            $tables[] = 'manual_sms_messages';
        }
        foreach ($tables as $table) {
            $deliveries = DB::table($table)->where('channel', 'sms')->where('sms_provider', 'roycetalk')
                ->where('status', 'submitted')->whereNotNull('provider_reference')
                ->where(fn ($query) => $query->whereNull('status_checked_at')->orWhere('status_checked_at', '<=', now()->subMinutes(5)))
                ->orderBy('status_checked_at')->orderBy('id')->limit(max(1, min(1000, (int) $this->option('limit'))))->get();
            foreach ($deliveries as $delivery) {
                $update = ['status_checked_at' => now(), 'updated_at' => now()];
                $stopPolling = false;
                try {
                    $response = $transport->messageStatus($delivery->provider_reference);
                    $status = $response->json('data.status');
                    $stopPolling = in_array($response->status(), [401, 403, 429], true) || $response->serverError();
                    if (! $response->successful() || $response->json('success') !== true || $response->json('data.id') !== $delivery->provider_reference || ! in_array($status, ['pending', 'sent', 'delivered', 'failed', 'rejected'], true)) {
                        $update['last_error'] = 'RoyceTalk status check was not confirmed. No message resent.';
                    } else {
                        $update['provider_status'] = $status;
                        $update['last_error'] = null;
                        if ($status === 'delivered') {
                            $update['status'] = 'delivered';
                            $update['delivered_at'] = now();
                        } elseif (in_array($status, ['failed', 'rejected'], true)) {
                            $update['status'] = 'undelivered';
                            $update['last_error'] = 'RoyceTalk reports '.$status.'. Review before manually retrying.';
                        }
                    }
                } catch (\Throwable $exception) {
                    $update['last_error'] = 'RoyceTalk status check unavailable. No message resent.';
                    $stopPolling = true;
                }
                DB::table($table)->where('id', $delivery->id)->where('status', 'submitted')->where('provider_reference', $delivery->provider_reference)->update($update);
                if ($stopPolling) {
                    break;
                }
            }
        }
        $this->info('RoyceTalk delivery status checks completed.');

        return self::SUCCESS;
    }
}
