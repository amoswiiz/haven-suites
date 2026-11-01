<?php

namespace App\Services;

use DateTimeImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ExternalCalendars
{
    public function validateUrl(string $url): string
    {
        $parts = parse_url($url);
        $host = strtolower($parts['host'] ?? '');
        if (! filter_var($url, FILTER_VALIDATE_URL) || ($parts['scheme'] ?? '') !== 'https'
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || (isset($parts['port']) && $parts['port'] !== 443)
            || ! in_array($host, config('calendars.hosts'), true)) {
            throw new RuntimeException('Use a private HTTPS iCal export link from Airbnb, Booking.com, Vrbo, or Google Calendar.');
        }

        return $host;
    }

    public function download(string $url): string
    {
        $host = $this->validateUrl($url);
        $addresses = gethostbynamel($host) ?: [];
        if (! $addresses) {
            throw new RuntimeException('Calendar host could not be resolved.');
        }
        foreach ($addresses as $address) {
            if (! filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new RuntimeException('Calendar host must use public addresses.');
            }
        }
        $body = '';
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROXY => '',
            CURLOPT_RESOLVE => [$host.':443:'.$addresses[0]], CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 20,
            CURLOPT_USERAGENT => 'HavenSuites-Calendar/1.0',
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body): int {
                if (strlen($body) + strlen($chunk) > 2 * 1024 * 1024) {
                    return 0;
                }
                $body .= $chunk;

                return strlen($chunk);
            },
        ]);
        $ok = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        if ($ok === false || $status !== 200) {
            throw new RuntimeException('Calendar download failed. Check the export link and try again.');
        }

        return $body;
    }

    /** @return array<int, array{start_date: string, end_date: string}> */
    public function parse(string $body): array
    {
        $body = str_replace(["\r\n", "\r"], "\n", $body);
        $body = preg_replace('/\n[ \t]/', '', $body);
        if (! preg_match('/\ABEGIN:VCALENDAR\n/', $body) || ! preg_match('/\nEND:VCALENDAR\s*\z/', $body)
            || substr_count($body, 'BEGIN:VEVENT') !== substr_count($body, 'END:VEVENT')) {
            throw new RuntimeException('The link did not return a complete iCal calendar.');
        }
        preg_match_all('/^BEGIN:VEVENT\n(.*?)^END:VEVENT$/ms', $body, $matches);
        $blocks = [];
        foreach ($matches[1] as $event) {
            if (preg_match('/^STATUS:CANCELLED\s*$/mi', $event)) {
                continue;
            }
            if (preg_match('/^(RRULE|RDATE|EXDATE|RECURRENCE-ID)[;:]/m', $event)) {
                throw new RuntimeException('Recurring events are not supported. Use individual all-day events.');
            }
            $dates = [];
            foreach (['DTSTART', 'DTEND'] as $field) {
                if (! preg_match('/^'.$field.'(?:;VALUE=DATE)?:([0-9]{8})\s*$/m', $event, $match)) {
                    throw new RuntimeException('Calendar events must have all-day start and end dates.');
                }
                $date = DateTimeImmutable::createFromFormat('!Ymd', $match[1]);
                if (! $date || $date->format('Ymd') !== $match[1]) {
                    throw new RuntimeException('Calendar contains an invalid date.');
                }
                $dates[] = $date->format('Y-m-d');
            }
            if ($dates[1] <= $dates[0] || count($blocks) >= 10000) {
                throw new RuntimeException('Calendar contains an invalid stay or too many events.');
            }
            $blocks[] = ['start_date' => $dates[0], 'end_date' => $dates[1]];
        }
        if (count($matches[1]) !== substr_count($body, 'BEGIN:VEVENT')) {
            throw new RuntimeException('Calendar contains malformed events.');
        }

        return $blocks;
    }

    public function sync(int $tenantId, int $id): bool
    {
        $feed = DB::table('external_calendars')->where('tenant_id', $tenantId)->where('id', $id)->first();
        if (! $feed) {
            return false;
        }
        try {
            $blocks = $this->parse($this->download(Crypt::decryptString($feed->url_encrypted)));
            DB::transaction(function () use ($tenantId, $id, $feed, $blocks): void {
                if (! DB::table('external_calendars')->where('tenant_id', $tenantId)->where('id', $id)->lockForUpdate()->first()) {
                    return;
                }
                DB::table('external_calendar_blocks')->where('calendar_id', $id)->delete();
                foreach (array_chunk($blocks, 100) as $chunk) {
                    DB::table('external_calendar_blocks')->insert(array_map(static fn (array $block): array => $block + [
                        'calendar_id' => $id, 'tenant_id' => $tenantId, 'unit_id' => $feed->unit_id,
                    ], $chunk));
                }
                DB::table('external_calendars')->where('tenant_id', $tenantId)->where('id', $id)->update([
                    'last_synced_at' => now(), 'last_error' => null, 'updated_at' => now(),
                ]);
            });

            return true;
        } catch (\Throwable $exception) {
            DB::table('external_calendars')->where('tenant_id', $tenantId)->where('id', $id)->update([
                'last_error' => 'Import failed. Check the private export link and use individual all-day events. Previous blocks were retained.', 'updated_at' => now(),
            ]);

            return false;
        }
    }

    public function overlaps(int $tenantId, int $unitId, string $start, string $end): bool
    {
        return DB::table('external_calendar_blocks')->where('tenant_id', $tenantId)->where('unit_id', $unitId)
            ->where('start_date', '<', $end)->where('end_date', '>', $start)->exists();
    }
}
