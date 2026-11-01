<?php

namespace App\Http\Controllers\Haven;

use App\Models\Haven\ReservationModel;
use App\Models\Haven\UnitModel;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

class Availability extends BaseController
{
    public function index()
    {
        $unitModel = new UnitModel;
        $reservationModel = new ReservationModel;

        $monthInput = (string) $this->request->getGet('month');

        $month = DateTimeImmutable::createFromFormat(
            '!Y-m',
            $monthInput
        );

        if (! $month || $month->format('Y-m') !== $monthInput) {
            $month = new DateTimeImmutable('first day of this month');
        }

        $monthStart = $month->format('Y-m-d');
        $monthEnd = $month->modify('+1 month')->format('Y-m-d');

        $unitId = (int) $this->request->getGet('unit_id');

        $units = $unitModel
            ->select(
                'units.*, properties.name AS property_name'
            )
            ->join(
                'properties',
                'properties.id = units.property_id',
                'left'
            )
            ->orderBy('properties.name', 'ASC')
            ->orderBy('units.unit_number', 'ASC')
            ->findAll();

        $validUnitIds = array_map(
            'intval',
            array_column($units, 'id')
        );

        if ($unitId > 0 && ! in_array($unitId, $validUnitIds, true)) {
            $unitId = 0;
        }

        if ($unitId > 0) {
            $units = array_values(array_filter(
                $units,
                fn ($unit) => (int) $unit['id'] === $unitId
            ));
        }

        $reservations = $reservationModel
            ->whereIn('status', [
                'confirmed',
                'checked_in',
            ])
            ->where('check_in <', $monthEnd)
            ->where('check_out >', $monthStart)
            ->findAll();

        $bookingsByUnit = [];

        foreach ($reservations as $reservation) {
            $bookingsByUnit[(int) $reservation['unit_id']][] =
                $reservation;
        }

        $externalBlocks = DB::table('external_calendar_blocks as b')
            ->join('external_calendars as c', 'c.id', '=', 'b.calendar_id')
            ->where('b.tenant_id', app('currentTenant')->id)
            ->whereIn('b.unit_id', $validUnitIds)
            ->where('b.start_date', '<', $monthEnd)->where('b.end_date', '>', $monthStart)
            ->get(['b.unit_id', 'b.start_date', 'b.end_date', 'c.name'])->groupBy('unit_id');
        $externalSyncWarning = DB::table('external_calendars')
            ->where('tenant_id', app('currentTenant')->id)
            ->where(function ($query): void {
                $query->whereNull('last_synced_at')->orWhere('last_synced_at', '<', now()->subMinutes(30))->orWhereNotNull('last_error');
            })->exists();

        return haven_view('availability/index', [
            'units' => $units,
            'externalBlocks' => $externalBlocks,
            'externalSyncWarning' => $externalSyncWarning,
            'allUnits' => $unitModel
                ->select('units.*, properties.name AS property_name')
                ->join(
                    'properties',
                    'properties.id = units.property_id',
                    'left'
                )
                ->orderBy('properties.name', 'ASC')
                ->orderBy('units.unit_number', 'ASC')
                ->findAll(),
            'bookingsByUnit' => $bookingsByUnit,
            'month' => $month,
            'selectedUnitId' => $unitId,
        ]);
    }
}
