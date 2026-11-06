<?php

namespace App\Services;

use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PartnerAvailability
{
    /** @return array{blocked: array<int, array{start: string, end: string}>, fresh: bool, last_synced_at: null, requires_confirmation: bool} */
    public function calendar(Tenant $tenant, int $partnerId, string $from, string $to, ?Connection $db = null): array
    {
        $this->dates($tenant, $from, $to);

        return $this->using($tenant, $db, function (Connection $connection) use ($tenant, $partnerId, $from, $to): array {
            abort_unless($tenant->active && $connection->getSchemaBuilder()->hasTable('partner_apartments'), 404);
            abort_unless($connection->table('partner_apartments')->where('id', $partnerId)->where('status', 'active')->exists(), 404);
            $bookings = $this->bookings($connection, [$partnerId], $from, $to)
                ->get(['check_in as start', 'check_out as end'])->map(fn (object $row): array => (array) $row)->all();

            return ['blocked' => $this->merge($bookings, $from, $to), 'fresh' => false,
                'last_synced_at' => null, 'requires_confirmation' => true];
        });
    }

    /** @return array<int, array{available: bool, fresh: bool}> */
    public function availabilityForPartners(Tenant $tenant, array $partnerIds, string $from, string $to, ?Connection $db = null): array
    {
        $this->dates($tenant, $from, $to);
        $partnerIds = array_values(array_unique(array_filter(array_map('intval', $partnerIds), fn (int $id): bool => $id > 0)));
        if (count($partnerIds) > 500) {
            throw ValidationException::withMessages(['apartments' => 'Search up to 500 apartments at a time.']);
        }
        if ($partnerIds === []) {
            return [];
        }

        return $this->using($tenant, $db, function (Connection $connection) use ($tenant, $partnerIds, $from, $to): array {
            abort_unless($tenant->active, 404);
            $result = array_fill_keys($partnerIds, ['available' => false, 'fresh' => false]);
            if (! $connection->getSchemaBuilder()->hasTable('partner_apartments')) {
                return $result;
            }
            $active = $connection->table('partner_apartments')->whereIn('id', $partnerIds)->where('status', 'active')->pluck('id')->all();
            $blocked = $this->bookings($connection, $partnerIds, $from, $to)->pluck('partner_apartment_id')->all();
            foreach (array_diff($active, $blocked) as $partnerId) {
                $result[(int) $partnerId]['available'] = true;
            }

            return $result;
        });
    }

    private function bookings(Connection $db, array $partnerIds, string $from, string $to): Builder
    {
        $query = $db->table('reservations')->whereIn('partner_apartment_id', $partnerIds)
            ->whereIn('status', ['confirmed', 'checked_in'])->where('check_in', '<', $to)->where('check_out', '>', $from);
        if ($db->getSchemaBuilder()->hasColumn('reservations', 'booking_type')) {
            $query->where('booking_type', 'partner');
        }

        return $query;
    }

    private function dates(Tenant $tenant, string $from, string $to): void
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
        $today = CarbonImmutable::now($tenant->timezone ?: config('app.timezone'))->startOfDay();
        if ($from < $today->startOfMonth()->toDateString() || $to > $today->addYears(2)->toDateString() || $start->diffInDays($end) > 366) {
            throw ValidationException::withMessages(['dates' => 'Choose up to one year of availability within the next two years.']);
        }
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

    /** @return array<int, array{start: string, end: string}> */
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
