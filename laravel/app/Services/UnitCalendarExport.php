<?php

namespace App\Services;

use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class UnitCalendarExport
{
    public function render(Tenant $tenant, int $unitId): string
    {
        $originalConnection = config('database.connections.tenant');
        $connection = $originalConnection;
        $connection['database'] = $tenant->database_name;
        config(['database.connections.calendar_export' => $connection]);
        DB::purge('calendar_export');
        try {
            $db = DB::connection('calendar_export');
            abort_unless($db->table('units')->where('id', $unitId)->exists(), 404);
            $bookings = $db->table('reservations')->where('unit_id', $unitId)
                ->whereIn('status', ['confirmed', 'checked_in'])->orderBy('id')
                ->get(['id', 'check_in', 'check_out', 'created_at', 'updated_at']);
            $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Haven Suites//Apartment availability//EN', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH'];
            foreach ($bookings as $booking) {
                $uid = substr(hash('sha256', $tenant->id.':'.$unitId.':'.$booking->id), 0, 40).'@haven-suites';
                $stamp = CarbonImmutable::parse($booking->updated_at ?? $booking->created_at ?? '2000-01-01', $tenant->timezone ?? config('app.timezone'))->utc()->format('Ymd\THis\Z');
                array_push($lines, 'BEGIN:VEVENT', 'UID:'.$uid, 'DTSTAMP:'.$stamp,
                    'DTSTART;VALUE=DATE:'.str_replace('-', '', $booking->check_in),
                    'DTEND;VALUE=DATE:'.str_replace('-', '', $booking->check_out),
                    'SUMMARY:Unavailable', 'STATUS:CONFIRMED', 'TRANSP:OPAQUE', 'END:VEVENT');
            }
            $lines[] = 'END:VCALENDAR';

            return implode("\r\n", $lines)."\r\n";
        } finally {
            DB::purge('calendar_export');
            config(['database.connections.calendar_export' => null]);
        }
    }
}
