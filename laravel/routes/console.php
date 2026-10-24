<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('haven:deliver-investor-notifications')->everyMinute()->withoutOverlapping();
Schedule::command('haven:sync-sms-status')->everyFiveMinutes()->withoutOverlapping();

Schedule::command('haven:accrue-rent')->dailyAt('00:10')->timezone('Africa/Nairobi')->withoutOverlapping();
