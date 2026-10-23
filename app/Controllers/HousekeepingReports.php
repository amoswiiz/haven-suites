<?php

namespace App\Controllers;

use App\Models\HousekeepingModel;

class HousekeepingReports extends BaseController
{
    private function authorized(): bool
    {
        $user = auth()->user();
        return $user !== null && $user->can('finance.view');
    }

    private function forbidden()
    {
        return service('response')->setStatusCode(403)->setBody('Permission denied.');
    }

    private function filters(): array
    {
        $month = (string) ($this->request->getGet('month') ?: date('Y-m'));
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            $month = date('Y-m');
        }

        $positiveId = static function ($value): ?int {
            if (!is_scalar($value)) {
                return null;
            }
            $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            return $id === false ? null : (int) $id;
        };

        $start = $month . '-01';
        return [
            'month' => $month,
            'property_id' => $positiveId($this->request->getGet('property_id')),
            'unit_id' => $positiveId($this->request->getGet('unit_id')),
            'staff_id' => $positiveId($this->request->getGet('staff_id')),
            'start' => $start,
            'end' => date('Y-m-d', strtotime($start . ' +1 month')),
        ];
    }

    private function reportRows(array $filters): array
    {
        $query = (new HousekeepingModel())
            ->select('housekeeping.id, housekeeping.unit_id, housekeeping.assigned_staff_id, housekeeping.scheduled_at, housekeeping.completed_at, housekeeping.status, housekeeping.cleaning_type, housekeeping.bedrooms_cleaned, housekeeping.cleaning_charge, housekeeping.charge_adjusted_by, properties.id AS property_id, properties.name AS property_name, units.unit_number, staff.full_name AS cleaner_name')
            ->join('units', 'units.id = housekeeping.unit_id', 'left')
            ->join('properties', 'properties.id = units.property_id', 'left')
            ->join('staff', 'staff.id = housekeeping.assigned_staff_id', 'left')
            ->whereIn('housekeeping.status', ['completed', 'verified'])
            ->where('housekeeping.completed_at >=', $filters['start'])
            ->where('housekeeping.completed_at <', $filters['end']);

        if ($filters['property_id'] !== null) {
            $query->where('properties.id', $filters['property_id']);
        }
        if ($filters['unit_id'] !== null) {
            $query->where('housekeeping.unit_id', $filters['unit_id']);
        }
        if ($filters['staff_id'] !== null) {
            $query->where('housekeeping.assigned_staff_id', $filters['staff_id']);
        }
        return $query->orderBy('housekeeping.completed_at', 'DESC')->findAll();
    }

    private function filterOptions(): array
    {
        $db = db_connect();
        return [
            'properties' => $db->table('properties')->select('id, name')->orderBy('name', 'ASC')->get()->getResultArray(),
            'units' => $db->table('units')->select('id, property_id, unit_number')->orderBy('unit_number', 'ASC')->get()->getResultArray(),
            'staff' => $db->table('staff')->select('id, full_name')->orderBy('full_name', 'ASC')->get()->getResultArray(),
        ];
    }

    public function index()
    {
        if (!$this->authorized()) {
            return $this->forbidden();
        }

        $filters = $this->filters();
        $rows = $this->reportRows($filters);
        $totalCost = 0.0;
        $byCleaner = [];
        $byApartment = [];

        foreach ($rows as $row) {
            $charge = (float) ($row['cleaning_charge'] ?? 0);
            $totalCost += $charge;

            $cleanerKey = $row['assigned_staff_id'] === null ? 'unassigned' : 'staff-' . $row['assigned_staff_id'];
            if (!isset($byCleaner[$cleanerKey])) {
                $byCleaner[$cleanerKey] = ['name' => $row['cleaner_name'] ?: 'Unassigned', 'count' => 0, 'total' => 0.0];
            }
            $byCleaner[$cleanerKey]['count']++;
            $byCleaner[$cleanerKey]['total'] += $charge;

            $unitKey = 'unit-' . $row['unit_id'];
            if (!isset($byApartment[$unitKey])) {
                $byApartment[$unitKey] = [
                    'name' => ($row['property_name'] ?: 'Unknown Property') . ' / ' . ($row['unit_number'] ?: 'Unknown Unit'),
                    'count' => 0,
                    'total' => 0.0,
                ];
            }
            $byApartment[$unitKey]['count']++;
            $byApartment[$unitKey]['total'] += $charge;
        }

        uasort($byCleaner, static fn ($a, $b) => $b['total'] <=> $a['total']);
        uasort($byApartment, static fn ($a, $b) => $b['total'] <=> $a['total']);

        return view('housekeeping_reports/index', array_merge(
            [
                'pageTitle' => 'Housekeeping Financial Report',
                'month' => $filters['month'],
                'filters' => $filters,
                'rows' => $rows,
                'totalCost' => $totalCost,
                'totalTasks' => count($rows),
                'byCleaner' => $byCleaner,
                'byApartment' => $byApartment,
            ],
            $this->filterOptions()
        ));
    }

    public function export()
    {
        if (!$this->authorized()) {
            return $this->forbidden();
        }
        $filters = $this->filters();
        $rows = $this->reportRows($filters);
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, ['Task ID', 'Property', 'Apartment', 'Cleaner', 'Cleaning Type', 'Bedrooms Cleaned', 'Charge (KES)', 'Status', 'Completed At', 'Charge Adjusted']);
        foreach ($rows as $row) {
            // Prevent spreadsheet formula execution in exported text fields.
            $safe = static function ($value): string {
                $text = (string) ($value ?? '');
                return preg_match('/^[\s]*[=+\-@]/u', $text) ? "'" . $text : $text;
            };
            fputcsv($stream, [
                (int) $row['id'],
                $safe($row['property_name']),
                $safe($row['unit_number']),
                $safe($row['cleaner_name']),
                $safe($row['cleaning_type']),
                $row['bedrooms_cleaned'],
                $row['cleaning_charge'],
                $safe($row['status']),
                $row['completed_at'],
                $row['charge_adjusted_by'] !== null ? 'Yes' : 'No',
            ]);
        }
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);
        return service('response')
            ->setHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->setHeader('Content-Disposition', 'attachment; filename="housekeeping-' . $filters['month'] . '.csv"')
            ->setBody($csv);
    }
}
