<?php

namespace App\Http\Controllers\Haven;

use App\Models\Haven\HousekeepingModel;
use App\Models\Haven\MaintenanceModel;
use App\Models\Haven\ReservationModel;
use App\Models\Haven\StaffModel;
use App\Models\Haven\UnitModel;
use App\Services\UnitAccounting;

class Dashboard extends BaseController
{
    public function index()
    {
        $today = date('Y-m-d');

        $unitModel = new UnitModel;
        $reservationModel = new ReservationModel;
        $staffModel = new StaffModel;
        $maintenanceModel = new MaintenanceModel;
        $housekeepingModel = new HousekeepingModel;

        $data = [
            'totalUnits' => $unitModel->countAllResults(),

            'activeUnits' => $unitModel
                ->where('status', 'active')
                ->countAllResults(),

            'totalReservations' => $reservationModel
                ->countAllResults(),

            'confirmedReservations' => $reservationModel
                ->whereIn('status', ['confirmed', 'checked_in'])
                ->countAllResults(),

            'totalStaff' => $staffModel
                ->where('status', 'active')
                ->countAllResults(),

            'openMaintenance' => $maintenanceModel
                ->whereIn('status', [
                    'open',
                    'assigned',
                    'in_progress',
                    'on_hold',
                ])
                ->countAllResults(),

            'pendingCleaning' => $housekeepingModel
                ->whereIn('status', [
                    'pending',
                    'assigned',
                    'in_progress',
                ])
                ->countAllResults(),

            'todayCheckins' => $reservationModel
                ->where('check_in', $today)
                ->whereIn('status', ['confirmed', 'checked_in'])
                ->countAllResults(),

            'todayCheckouts' => $reservationModel
                ->where('check_out', $today)
                ->whereIn('status', [
                    'confirmed',
                    'checked_in',
                    'checked_out',
                ])
                ->countAllResults(),

            'todayCleaning' => $housekeepingModel
                ->where('scheduled_at >=', $today.' 00:00:00')
                ->where('scheduled_at <', date('Y-m-d', strtotime('+1 day')).' 00:00:00')
                ->whereNotIn('status', ['cancelled'])
                ->countAllResults(),

            'upcomingCheckins' => $reservationModel
                ->select(
                    'reservations.*, units.unit_number, '.
                    'properties.name AS property_name'
                )
                ->join('units', 'units.id = reservations.unit_id')
                ->join('properties', 'properties.id = units.property_id')
                ->where('reservations.check_in >=', $today)
                ->whereIn('reservations.status', [
                    'confirmed', 'checked_in',
                ])
                ->orderBy('reservations.check_in', 'ASC')
                ->findAll(5),

            'recentMaintenance' => $maintenanceModel
                ->select(
                    'maintenance.*, units.unit_number, '.
                    'properties.name AS property_name'
                )
                ->join('units', 'units.id = maintenance.unit_id')
                ->join('properties', 'properties.id = units.property_id')
                ->whereIn('maintenance.status', [
                    'open', 'assigned', 'in_progress', 'on_hold',
                ])
                ->orderBy('maintenance.id', 'DESC')
                ->findAll(5),
        ];

        $data['pageTitle'] = 'Dashboard';
        $data['departureTasks'] = $reservationModel
            ->select('reservations.*, units.unit_number, properties.name AS property_name')
            ->join('units', 'units.id = reservations.unit_id', 'left')
            ->join('properties', 'properties.id = units.property_id', 'left')
            ->where('reservations.check_out', $today)
            ->whereIn('reservations.status', ['confirmed', 'checked_in', 'checked_out'])
            ->orderBy('reservations.id', 'ASC')->findAll(5);
        $data['cleaningTasks'] = $housekeepingModel
            ->select('housekeeping.*, units.unit_number, properties.name AS property_name, staff.full_name AS assigned_name')
            ->join('units', 'units.id = housekeeping.unit_id')
            ->join('properties', 'properties.id = units.property_id')
            ->join('staff', 'staff.id = housekeeping.assigned_staff_id', 'left')
            ->whereIn('housekeeping.status', ['pending', 'assigned', 'in_progress'])
            ->orderBy('housekeeping.scheduled_at', 'ASC')->findAll(5);
        $data['upcomingDepartures'] = $reservationModel
            ->select('reservations.*, units.unit_number, properties.name AS property_name')
            ->join('units', 'units.id = reservations.unit_id', 'left')
            ->join('properties', 'properties.id = units.property_id', 'left')
            ->where('reservations.check_out >', $today)
            ->whereIn('reservations.status', ['confirmed', 'checked_in'])
            ->orderBy('reservations.check_out', 'ASC')->findAll(5);

        // Financial reports and filters are restricted to finance viewers.
        $user = haven_auth()->user();
        $data['canViewFinance'] = $user !== null && $user->can('finance.view');
        $data['financeMonth'] = date('Y-m');

        if ($data['canViewFinance']) {
            $db = db_connect();
            $requestedMonth = (string) $this->request->getGet('month');
            $month = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $requestedMonth)
                ? $requestedMonth : date('Y-m');
            $monthStart = $month.'-01';
            $nextMonth = date('Y-m-d', strtotime($monthStart.' +1 month'));
            $unitId = max(0, (int) $this->request->getGet('unit_id'));
            $units = $db->table('units u')
                ->select('u.id, u.unit_number, u.property_id, p.name AS property_name')
                ->join('properties p', 'p.id = u.property_id')
                ->orderBy('p.name')->orderBy('u.unit_number')
                ->get()->getResultArray();
            $validUnitIds = array_map('intval', array_column($units, 'id'));
            if ($unitId && ! in_array($unitId, $validUnitIds, true)) {
                $unitId = 0;
            }

            // Every report is filtered using the unit linked to the accounting entry.
            $applyUnitFilter = static function ($builder, string $alias) use ($unitId) {
                if ($unitId) {
                    $builder->where($alias.'.id', $unitId);
                }

                return $builder;
            };
            $collections = static function ($start, $end) use ($db, $applyUnitFilter): float {
                $q = $db->table('guest_payments gp')->select('COALESCE(SUM(gp.amount), 0) AS total', false)
                    ->join('reservations r', 'r.id = gp.reservation_id')
                    ->join('units u', 'u.id = r.unit_id');
                $applyUnitFilter($q, 'u');

                return (float) (($q->where('gp.payment_date >=', $start)->where('gp.payment_date <', $end)->get()->getRowArray())['total'] ?? 0);
            };
            $expenses = static function ($start, $end) use ($db, $applyUnitFilter): float {
                $q = $db->table('unit_expenses e')->select('COALESCE(SUM(e.amount), 0) AS total', false)
                    ->join('units u', 'u.id = e.unit_id');
                $applyUnitFilter($q, 'u');

                return (float) (($q->where('e.expense_date >=', $start)->where('e.expense_date <', $end)->get()->getRowArray())['total'] ?? 0);
            };
            $remittanceQuery = $db->table('owner_remittances o')
                ->select('COALESCE(SUM(o.amount), 0) AS total', false)
                ->join('units u', 'u.id = o.unit_id');
            $applyUnitFilter($remittanceQuery, 'u');
            $remittance = $remittanceQuery->where('o.period_month', $monthStart)->get()->getRowArray();

            // Outstanding is an all-time balance for reservations in the selected units.
            $balanceQuery = $db->table('booking_financials bf')
                ->select('bf.reservation_id, bf.guest_total, COALESCE(SUM(gp.amount), 0) AS paid', false)
                ->join('reservations r', 'r.id = bf.reservation_id')
                ->join('units u', 'u.id = r.unit_id')
                ->join('guest_payments gp', 'gp.reservation_id = bf.reservation_id', 'left')
                ->groupBy('bf.reservation_id, bf.guest_total');
            $applyUnitFilter($balanceQuery, 'u');
            $outstanding = 0.0;
            foreach ($balanceQuery->get()->getResultArray() as $balanceRow) {
                $outstanding += max(0, (float) $balanceRow['guest_total'] - (float) $balanceRow['paid']);
            }

            $data['financialSummary'] = [
                'collections' => $collections($monthStart, $nextMonth),
                'expenses' => $expenses($monthStart, $nextMonth),
                'remittances' => (float) ($remittance['total'] ?? 0),
                'outstanding' => $outstanding,
            ];
            // Company-wide income uses the same recognition rules as the
            // Monthly Company Income report. It intentionally ignores the
            // apartment-unit filter, which cannot represent partner apartments.
            $incomeCents = static function ($value): int {
                $value = trim((string) ($value ?? '0'));
                if (! preg_match('/^-?\\d{1,12}(?:\\.\\d{1,2})?$/', $value)) {
                    throw new \RuntimeException('Invalid monetary amount in accounting records.');
                }
                $negative = str_starts_with($value, '-');
                $parts = explode('.', ltrim($value, '-'), 2);
                $cents = ((int) $parts[0] * 100) + (int) str_pad($parts[1] ?? '', 2, '0');

                return $negative ? -$cents : $cents;
            };
            $incomeMoney = static function (int $cents): string {
                return ($cents < 0 ? '-' : '')
                    .sprintf('%d.%02d', intdiv(abs($cents), 100), abs($cents) % 100);
            };

            $incomePayments = $db->table('guest_payments gp')
                ->select('gp.id, gp.payment_date, gp.amount, r.unit_id')
                ->join('reservations r', 'r.id = gp.reservation_id', 'inner')
                ->join('units u', 'u.id = r.unit_id', 'inner')
                ->where('gp.payment_date >=', $monthStart)
                ->where('gp.payment_date <', $nextMonth)
                ->orderBy('gp.payment_date', 'ASC')
                ->orderBy('gp.id', 'ASC')
                ->get()->getResultArray();

            $incomeAgreements = $db->table('unit_host_agreements')
                ->select('id, unit_id, commission_rate, effective_from, effective_to')
                ->where('effective_from <', $nextMonth)
                ->groupStart()
                ->where('effective_to', null)
                ->orWhere('effective_to >=', $monthStart)
                ->groupEnd()
                ->get()->getResultArray();

            $agreementsByUnit = [];
            foreach ($incomeAgreements as $agreement) {
                $agreementsByUnit[(int) $agreement['unit_id']][] = $agreement;
            }

            $managedIncomeCents = 0;
            $incomeIssues = 0;
            $unitAccounting = new UnitAccounting;
            foreach ($incomePayments as $payment) {
                if ($unitAccounting->mode((int) $payment['unit_id'], $payment['payment_date']) === 'leased') {
                    continue;
                }
                $matches = [];
                foreach ($agreementsByUnit[(int) $payment['unit_id']] ?? [] as $agreement) {
                    if ($agreement['effective_from'] <= $payment['payment_date']
                        && ($agreement['effective_to'] === null
                            || $agreement['effective_to'] >= $payment['payment_date'])) {
                        $matches[] = $agreement;
                    }
                }
                $matches = $unitAccounting->matchingAgreements((int) $payment['unit_id'], $payment['payment_date'], $matches);
                if (count($matches) !== 1) {
                    $incomeIssues++;

                    continue;
                }
                $rate = (string) $matches[0]['commission_rate'];
                if (! preg_match('/^\\d{1,3}(?:\\.\\d{1,2})?$/', $rate)
                    || (float) $rate > 100) {
                    $incomeIssues++;

                    continue;
                }
                $amount = $incomeCents($payment['amount']);
                $basisPoints = (int) round((float) $rate * 100);
                $managedIncomeCents += intdiv($amount * $basisPoints + 5000, 10000);
            }

            $partnerIncomeCents = 0;
            $incomePartners = $db->table('reservations r')
                ->select('r.id, r.total_amount, r.owner_net_amount')
                ->where('r.booking_type', 'partner')
                ->where('r.status', 'checked_out')
                ->where('r.check_out >=', $monthStart)
                ->where('r.check_out <', $nextMonth)
                ->orderBy('r.check_out', 'ASC')
                ->orderBy('r.id', 'ASC')
                ->get()->getResultArray();

            foreach ($incomePartners as $partner) {
                if ($partner['owner_net_amount'] === null) {
                    $incomeIssues++;

                    continue;
                }
                $gross = $incomeCents($partner['total_amount']);
                $owner = $incomeCents($partner['owner_net_amount']);
                if ($gross < 0 || $owner < 0 || $owner > $gross) {
                    $incomeIssues++;

                    continue;
                }
                $partnerIncomeCents += $gross - $owner;
            }

            $data['companyIncomeSummary'] = [
                'managed' => $incomeMoney($managedIncomeCents),
                'partner' => $incomeMoney($partnerIncomeCents),
                'total' => $incomeMoney($managedIncomeCents + $partnerIncomeCents),
                'issues' => $incomeIssues,
            ];

            $data['operatingReport'] = (new UnitAccounting)->report($month, $unitId ?: null);
            $data['financeMonth'] = $month;
            $data['financeFilters'] = ['month' => $month, 'unit_id' => $unitId];
            $data['financeUnits'] = $units;
            $data['financeTrend'] = [];
            for ($i = 5; $i >= 0; $i--) {
                $start = date('Y-m-01', strtotime($monthStart.' -'.$i.' months'));
                $end = date('Y-m-d', strtotime($start.' +1 month'));
                $data['financeTrend'][] = [
                    'label' => date('M y', strtotime($start)),
                    'collections' => $collections($start, $end),
                    'expenses' => $expenses($start, $end),
                ];
            }

            // Occupancy: unique occupied unit-nights / available unit-nights in the month.
            // Check-out day is not counted. This is a calendar occupancy estimate,
            // excluding cancelled reservations, not a historical active-unit audit.
            $occupancyUnitsQuery = $db->table('units u')->select('u.id')->where('u.status', 'active');
            $applyUnitFilter($occupancyUnitsQuery, 'u');
            $occupancyUnitIds = array_map('intval', array_column($occupancyUnitsQuery->get()->getResultArray(), 'id'));
            $days = (int) date('t', strtotime($monthStart));
            $capacity = count($occupancyUnitIds) * $days;
            $occupied = [];
            if ($occupancyUnitIds) {
                $stays = $db->table('reservations')->select('unit_id, check_in, check_out')
                    ->whereIn('unit_id', $occupancyUnitIds)
                    ->whereIn('status', ['confirmed', 'checked_in', 'checked_out'])
                    ->where('check_in <', $nextMonth)
                    ->where('check_out >', $monthStart)->get()->getResultArray();
                foreach ($stays as $stay) {
                    $from = max($stay['check_in'], $monthStart);
                    $to = min($stay['check_out'], $nextMonth);
                    for ($day = $from; $day < $to; $day = date('Y-m-d', strtotime($day.' +1 day'))) {
                        $occupied[(int) $stay['unit_id'].':'.$day] = true;
                    }
                }
            }
            $data['occupancySummary'] = [
                'occupied' => count($occupied),
                'capacity' => $capacity,
                'percent' => $capacity > 0 ? round(count($occupied) / $capacity * 100, 1) : null,
            ];
        }

        return haven_view('dashboard/index', $data);
    }
}
