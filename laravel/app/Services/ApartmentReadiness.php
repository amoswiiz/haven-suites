<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ApartmentReadiness
{
    public function overview(?int $unitId = null): Collection
    {
        $db = DB::connection('tenant');
        $query = $db->table('units as u')->leftJoin('properties as p', 'p.id', '=', 'u.property_id')->select('u.id', 'u.unit_number', 'u.status', 'p.name as property_name');
        if ($unitId !== null) {
            $query->where('u.id', $unitId);
        }
        $units = $query->orderBy('p.name')->orderBy('u.unit_number')->get();
        $ids = $units->pluck('id');
        $today = now()->toDateString();
        $bookings = auth()->user()->can('reservations.view') ? $db->table('reservations')->whereIn('unit_id', $ids)->whereIn('status', ['confirmed', 'checked_in', 'checked_out'])->get(['id', 'unit_id', 'guest_name', 'check_in', 'check_out', 'status'])->groupBy('unit_id') : collect();
        $cleanings = auth()->user()->can('housekeeping.view') ? $db->table('housekeeping')->whereIn('unit_id', $ids)->where('status', '!=', 'cancelled')->orderByDesc('scheduled_at')->get(['id', 'unit_id', 'status', 'scheduled_at', 'completed_at'])->groupBy('unit_id') : collect();
        $issues = auth()->user()->can('maintenance.view') ? $db->table('maintenance')->whereIn('unit_id', $ids)->whereIn('status', ['open', 'assigned', 'in_progress', 'on_hold'])->get(['id', 'unit_id', 'title', 'priority', 'status'])->groupBy('unit_id') : collect();

        return $units->map(function ($unit) use ($bookings, $cleanings, $issues, $today): object {
            $stays = $bookings->get($unit->id, collect());
            $unit->arrivals = $stays->where('check_in', $today)->whereIn('status', ['confirmed', 'checked_in'])->values();
            $unit->departures = $stays->where('check_out', $today)->values();
            $occupied = $stays->filter(static fn ($stay): bool => $stay->check_in <= $today && $stay->check_out > $today && in_array($stay->status, ['confirmed', 'checked_in'], true))->isNotEmpty();
            $unit->availability = ! auth()->user()->can('reservations.view') ? 'Booking status unavailable' : ($occupied ? 'Booked today' : 'No stay tonight');
            $jobs = $cleanings->get($unit->id, collect());
            $unit->cleaning = 'Not verified';
            $lastDeparture = $stays->where('check_out', '<=', $today)->max('check_out');
            $verified = $jobs->first(static fn ($job): bool => $job->status === 'verified' && $job->completed_at && substr($job->completed_at, 0, 10) <= $today && (! $lastDeparture || substr($job->completed_at, 0, 10) >= $lastDeparture));
            if (! auth()->user()->can('housekeeping.view')) {
                $unit->cleaning = 'Cleaning status unavailable';
            } elseif ($jobs->whereIn('status', ['pending', 'assigned', 'in_progress'])->where('scheduled_at', '<', $today.' 23:59:59')->isNotEmpty()) {
                $unit->cleaning = 'Cleaning pending';
            } elseif ($verified) {
                $unit->cleaning = 'Verified clean';
            } elseif ($jobs->where('status', 'completed')->isNotEmpty()) {
                $unit->cleaning = 'Awaiting verification';
            }
            $unit->issues = $issues->get($unit->id, collect());
            $unit->maintenance = ! auth()->user()->can('maintenance.view') ? 'Maintenance status unavailable' : ($unit->issues->isEmpty() ? 'No open issues' : $unit->issues->count().' open issue(s)');
            $pendingDeparture = $stays->filter(static fn ($stay): bool => $stay->check_out <= $today && $stay->status === 'checked_in')->isNotEmpty();
            $unit->readiness = $unit->status !== 'active' ? 'Apartment unavailable' : (($unit->cleaning === 'Verified clean' && $unit->maintenance === 'No open issues') ? ($occupied ? 'In use today' : ($pendingDeparture ? 'Awaiting guest departure' : 'Ready for review at arrival')) : 'Needs attention');

            return $unit;
        });
    }
}
