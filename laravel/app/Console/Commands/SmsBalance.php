<?php

namespace App\Console\Commands;

use App\Services\RoyceTalkSms;
use Illuminate\Console\Command;

class SmsBalance extends Command
{
    protected $signature = 'haven:sms-balance';

    protected $description = 'Check the platform RoyceTalk account balance without sending SMS';

    public function handle(RoyceTalkSms $transport): int
    {
        try {
            $response = $transport->balance();
            if (! $response->successful() || $response->json('success') !== true || ! is_numeric($response->json('data.current_balance'))) {
                $this->error('RoyceTalk balance unavailable. Check API credentials and provider availability.');

                return self::FAILURE;
            }
            $this->info('Available SMS units: '.$response->json('data.current_balance'));
        } catch (\Throwable $exception) {
            $this->error('RoyceTalk balance unavailable. Check API credentials and connectivity.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
