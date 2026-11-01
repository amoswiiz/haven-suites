<?php

use App\Services\ExternalCalendars;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('haven:deliver-investor-notifications')->everyMinute()->withoutOverlapping();
Schedule::command('haven:sync-sms-status')->everyFiveMinutes()->withoutOverlapping();

Schedule::command('haven:accrue-rent')->dailyAt('00:10')->timezone('Africa/Nairobi')->withoutOverlapping();

Schedule::command('haven:task-notifications')->everyMinute()->withoutOverlapping();

Artisan::command('haven:sync-external-calendars', function (ExternalCalendars $calendars): void {
    DB::table('external_calendars as c')->join('tenants as t', 't.id', '=', 'c.tenant_id')
        ->where('t.active', true)->select('c.id', 'c.tenant_id')->orderBy('c.id')
        ->chunkById(100, function ($feeds) use ($calendars): void {
            foreach ($feeds as $feed) {
                $calendars->sync((int) $feed->tenant_id, (int) $feed->id);
            }
        }, 'c.id', 'id');
})->purpose('Refresh external apartment availability calendars');

Schedule::command('haven:sync-external-calendars')->everyFifteenMinutes()->withoutOverlapping();
