<?php

namespace App\Http\Controllers\Haven;

use App\Models\Haven\UnitModel;
use App\Models\Haven\ReservationModel;
use DateTimeImmutable;

class Availability extends BaseController
{
    public function index()
    {
        $unitModel = new UnitModel();
        $reservationModel = new ReservationModel();

        $monthInput = (string) $this->request->getGet('month');

        $month = DateTimeImmutable::createFromFormat(
            '!Y-m',
            $monthInput
        );

        if (!$month || $month->format('Y-m') !== $monthInput) {
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

        if ($unitId > 0 && !in_array($unitId, $validUnitIds, true)) {
            $unitId = 0;
        }

        if ($unitId > 0) {
            $units = array_values(array_filter(
                $units,
                fn($unit) => (int) $unit['id'] === $unitId
            ));
        }

        $reservations = $reservationModel
            ->whereIn('status', [
                'confirmed',
                'checked_in'
            ])
            ->where('check_in <', $monthEnd)
            ->where('check_out >', $monthStart)
            ->findAll();

        $bookingsByUnit = [];

        foreach ($reservations as $reservation) {
            $bookingsByUnit[(int) $reservation['unit_id']][] =
                $reservation;
        }

        return haven_view('availability/index', [
            'units' => $units,
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
            'selectedUnitId' => $unitId
        ]);
    }
}
