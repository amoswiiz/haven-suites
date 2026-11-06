<?php

namespace App\Services;

use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UnitAvailability
{
    public function calendar(Tenant $tenant, int $unitId, string $from, string $to, ?Connection $db = null): array
    {
        [$start, $end] = $this->dates($from, $to);
        $today = CarbonImmutable::now($tenant->timezone ?: config('app.timezone'))->startOfDay();
        if ($from < $today->startOfMonth()->toDateString() || $to > $today->addYears(2)->toDateString() || $start->diffInDays($end) > 366) {
            throw ValidationException::withMessages(['dates' => 'Choose up to one year of availability within the next two years.']);
        }

        return $this->using($tenant, $db, function (Connection $connection) use ($tenant, $unitId, $from, $to): array {
            $unit = $connection->table('units')->where('id', $unitId)->first();
            abort_unless($tenant->active && $unit && ($unit->status ?? 'active') === 'active', 404);
            $bookings = $connection->table('reservations')->where('unit_id', $unitId)
                ->whereIn('status', ['confirmed', 'checked_in'])->where('check_in', '<', $to)->where('check_out', '>', $from)
                ->get(['check_in as start', 'check_out as end'])->map(fn (object $row): array => (array) $row)->all();
            $external = DB::table('external_calendar_blocks')->where('tenant_id', $tenant->id)->where('unit_id', $unitId)
                ->where('start_date', '<', $to)->where('end_date', '>', $from)->get(['start_date as start', 'end_date as end'])
                ->map(fn (object $row): array => (array) $row)->all();
            $closures = $this->closures((int) $tenant->id, $unitId, $from, $to);
            $feeds = DB::table('external_calendars')->where('tenant_id', $tenant->id)->where('unit_id', $unitId)
                ->get(['last_synced_at', 'last_error']);
            $fresh = $feeds->every(fn (object $feed): bool => $feed->last_synced_at !== null && $feed->last_error === null
                && CarbonImmutable::parse($feed->last_synced_at, config('app.timezone'))->greaterThanOrEqualTo(now()->subMinutes(10)));
            $lastSynced = $feeds->contains(fn (object $feed): bool => $feed->last_synced_at === null)
                ? null : $feeds->min('last_synced_at');

            return ['blocked' => $this->merge([...$bookings, ...$external, ...$closures], $from, $to),
                'fresh' => $fresh, 'last_synced_at' => $lastSynced, 'requires_confirmation' => true];
        });
    }

    public function overlaps(Tenant $tenant, int $unitId, string $from, string $to, ?int $excludeReservationId = null, ?Connection $db = null): bool
    {
        $this->dates($from, $to);

        return $this->using($tenant, $db, function (Connection $connection) use ($tenant, $unitId, $from, $to, $excludeReservationId): bool {
            abort_unless($tenant->active && $connection->table('units')->where('id', $unitId)->exists(), 404);
            $bookings = $connection->table('reservations')->where('unit_id', $unitId)
                ->whereIn('status', ['confirmed', 'checked_in'])->where('check_in', '<', $to)->where('check_out', '>', $from);
            if ($excludeReservationId !== null) {
                $bookings->where('id', '!=', $excludeReservationId);
            }

            return $bookings->exists()
                || DB::table('external_calendar_blocks')->where('tenant_id', $tenant->id)->where('unit_id', $unitId)
                    ->where('start_date', '<', $to)->where('end_date', '>', $from)->exists()
                || $this->closureOverlaps((int) $tenant->id, $unitId, $from, $to);
        });
    }

    public function closureOverlaps(int $tenantId, int $unitId, string $from, string $to): bool
    {
        return DB::getSchemaBuilder()->hasTable('public_unit_closures')
            && DB::table('public_unit_closures')->where('tenant_id', $tenantId)->where('unit_id', $unitId)
                ->where('start_date', '<', $to)->where('end_date', '>', $from)->exists();
    }

    /** @return array<int, array{available: bool, fresh: bool}> */
    public function availabilityForUnits(Tenant $tenant, array $unitIds, string $from, string $to, ?Connection $db = null): array
    {
        $this->dates($from, $to);
        $unitIds = array_values(array_unique(array_filter(array_map('intval', $unitIds), fn (int $id): bool => $id > 0)));
        if (count($unitIds) > 500) {
            throw ValidationException::withMessages(['units' => 'Search up to 500 apartments at a time.']);
        }
        if ($unitIds === []) {
            return [];
        }

        return $this->using($tenant, $db, function (Connection $connection) use ($tenant, $unitIds, $from, $to): array {
            abort_unless($tenant->active, 404);
            $units = $connection->table('units')->whereIn('id', $unitIds)->get(['id', 'status'])->keyBy('id');
            $booked = $connection->table('reservations')->whereIn('unit_id', $unitIds)->whereIn('status', ['confirmed', 'checked_in'])
                ->where('check_in', '<', $to)->where('check_out', '>', $from)->pluck('unit_id')->map(fn ($id): int => (int) $id)->all();
            $external = DB::table('external_calendar_blocks')->where('tenant_id', $tenant->id)->whereIn('unit_id', $unitIds)
                ->where('start_date', '<', $to)->where('end_date', '>', $from)->pluck('unit_id')->map(fn ($id): int => (int) $id)->all();
            $closures = DB::getSchemaBuilder()->hasTable('public_unit_closures')
                ? DB::table('public_unit_closures')->where('tenant_id', $tenant->id)->whereIn('unit_id', $unitIds)
                    ->where('start_date', '<', $to)->where('end_date', '>', $from)->pluck('unit_id')->map(fn ($id): int => (int) $id)->all() : [];
            $blocked = array_fill_keys([...$booked, ...$external, ...$closures], true);
            $feeds = DB::table('external_calendars')->where('tenant_id', $tenant->id)->whereIn('unit_id', $unitIds)
                ->get(['unit_id', 'last_synced_at', 'last_error'])->groupBy('unit_id');
            $result = [];
            foreach ($unitIds as $unitId) {
                $fresh = ($feeds->get($unitId) ?? collect())->every(fn (object $feed): bool => $feed->last_synced_at !== null && $feed->last_error === null
                    && CarbonImmutable::parse($feed->last_synced_at, config('app.timezone'))->greaterThanOrEqualTo(now()->subMinutes(10)));
                $result[$unitId] = ['available' => ($units->get($unitId)?->status === 'active') && ! isset($blocked[$unitId]), 'fresh' => $fresh];
            }

            return $result;
        });
    }

    /** @return array<int, array{start: string, end: string}> */
    private function closures(int $tenantId, int $unitId, string $from, string $to): array
    {
        if (! DB::getSchemaBuilder()->hasTable('public_unit_closures')) {
            return [];
        }

        return DB::table('public_unit_closures')->where('tenant_id', $tenantId)->where('unit_id', $unitId)
            ->where('start_date', '<', $to)->where('end_date', '>', $from)->get(['start_date as start', 'end_date as end'])
            ->map(fn (object $row): array => (array) $row)->all();
    }

    private function dates(string $from, string $to): array
    {
        try {
            $start = CarbonImmutable::createFromFormat('!Y-m-d', $from, 'UTC');
            $end = CarbonImmutable::createFromFormat('!Y-m-d', $to, 'UTC');
        } catch (\Throwable $exception) {
            $start = $end = false;
        }
        if (! $start || ! $end || $start->format('Y-m-d') !== $from || $end->format('Y-m-d') !== $to || $end <= $start) {
            throw ValidationException::withMessages(['dates' => 'Enter a valid start date and a later end date.']);
        }

        return [$start, $end];
    }

    private function using(Tenant $tenant, ?Connection $db, Closure $callback): mixed
    {
        if ($db !== null) {
            return $callback($db);
        }
        $config = config('database.connections.tenant');
        $config['database'] = $tenant->database_name;
        $connection = DB::build($config);
        try {
            return $callback($connection);
        } finally {
            $connection->disconnect();
        }
    }

    private function merge(array $ranges, string $from, string $to): array
    {
        $ranges = array_map(fn (array $range): array => ['start' => max($from, $range['start']), 'end' => min($to, $range['end'])], $ranges);
        $ranges = array_values(array_filter($ranges, fn (array $range): bool => $range['end'] > $range['start']));
        usort($ranges, fn (array $first, array $second): int => strcmp($first['start'], $second['start']));
        $merged = [];
        foreach ($ranges as $range) {
            $last = count($merged) - 1;
            if ($last >= 0 && $range['start'] <= $merged[$last]['end']) {
                $merged[$last]['end'] = max($merged[$last]['end'], $range['end']);
            } else {
                $merged[] = $range;
            }
        }

        return $merged;
    }
}
