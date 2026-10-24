<?php

namespace App\Http\Controllers\Haven;

use App\Services\AccountingPeriodGuard;
use App\Services\OwnerRemittanceCalculator;
use App\Services\UnitAccounting;

class OwnerRemittances extends BaseController
{
    private function allowed(string $permission): bool
    {
        return haven_auth()->user() !== null && haven_auth()->user()->can($permission);
    }

    public function index()
    {
        if (! $this->allowed('finance.view')) {
            return haven_service('response')->setStatusCode(403)->setContent('Permission denied.');
        }
        $month = (string) ($this->request->getGet('month') ?: date('Y-m'));
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            $month = date('Y-m');
        }
        $unitId = (int) ($this->request->getGet('unit_id') ?: 0);
        $db = db_connect();
        $units = $db->table('units u')
            ->select('u.id, u.unit_number, p.name AS property_name')
            ->join('properties p', 'p.id = u.property_id', 'left')
            ->orderBy('p.name')->orderBy('u.unit_number')->get()->getResultArray();
        $selected = null;
        foreach ($units as $unit) {
            if ((int) $unit['id'] === $unitId) {
                $selected = $unit;
            }
        }
        $report = null;
        $history = [];
        if ($selected) {
            $report = (new OwnerRemittanceCalculator)->calculate($unitId, $month);
            $history = $db->table('owner_remittances')
                ->where('unit_id', $unitId)->where('period_month', $month.'-01')
                ->orderBy('payment_date', 'DESC')->orderBy('id', 'DESC')->get()->getResultArray();
        }

        return haven_view('owner_remittances/index', [
            'pageTitle' => 'Monthly Owner Remittances', 'month' => $month,
            'units' => $units, 'unitId' => $unitId, 'selected' => $selected,
            'report' => $report, 'history' => $history,
            'canManage' => $this->allowed('finance.manage'),
        ]);
    }

    public function export()
    {
        if (! $this->allowed('finance.view')) {
            return haven_service('response')->setStatusCode(403)->setContent('Permission denied.');
        }
        $month = trim((string) $this->request->getGet('month'));
        $unitId = (int) $this->request->getGet('unit_id');
        if (! $unitId || ! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            return haven_service('response')->setStatusCode(400)->setContent('Invalid unit or month.');
        }
        $db = db_connect();
        $unit = $db->table('units u')->select('u.unit_number, p.name AS property_name')
            ->join('properties p', 'p.id = u.property_id', 'left')
            ->where('u.id', $unitId)->get()->getRowArray();
        if (! $unit) {
            return haven_service('response')->setStatusCode(404)->setContent('Unit not found.');
        }
        $report = (new OwnerRemittanceCalculator)->calculate($unitId, $month);
        $rows = [
            ['Haven Suites Owner Statement', ''],
            ['Accounting month', $month],
            ['Property', $unit['property_name'] ?? ''],
            ['Unit', $unit['unit_number']],
            ['Opening owner balance (KES)', $report['opening_balance']],
            ['Guest collections (KES)', $report['collected']],
            ['Management fee (KES)', $report['management_fee']],
            ['Fixed rent owed (KES)', $report['rental_income'] ?? '0.00'],
            ['Owner-deductible expenses (KES)', $report['expenses']],
            ['Current month entitlement (KES)', $report['entitlement']],
            ['Previous deficit recovered this month (KES)', $report['deficit_recovered']],
            ['Remaining deficit to recover (KES)', $report['deficit_to_recover']],
            ['Remittances allocated to month (KES)', $report['paid']],
            ['Closing owner balance (KES)', $report['closing_balance']],
            ['Issues', implode('; ', $report['issues'])],
            [], ['Remittance date', 'Method', 'Reference', 'Amount (KES)'],
        ];
        $payments = $db->table('owner_remittances')
            ->where('unit_id', $unitId)->where('period_month', $month.'-01')
            ->orderBy('payment_date')->get()->getResultArray();
        foreach ($payments as $payment) {
            $rows[] = [$payment['payment_date'], $payment['payment_method'],
                $payment['reference'] ?? '', $payment['amount']];
        }
        $stream = fopen('php://temp', 'w+');
        foreach ($rows as $row) {
            // Prevent spreadsheet formula injection in user-controlled fields.
            $row = array_map(static function ($value) {
                $value = (string) $value;

                return preg_match('/^[\s]*[=+@-]/', $value) && ! preg_match('/^-?\d+(?:\.\d+)?$/', $value)
                    ? "'".$value : $value;
            }, $row);
            fputcsv($stream, $row);
        }
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        return $this->response->header('Content-Type', 'text/csv; charset=UTF-8')
            ->header('Content-Disposition', 'attachment; filename="owner-statement-'.$unitId.'-'.$month.'.csv"')
            ->setContent($csv);
    }

    public function store()
    {
        $requestedUnit = (int) request()->input('unit_id');
        $requestedMonth = (string) request()->input('month');
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $requestedMonth) && (new UnitAccounting)->mode($requestedUnit, $requestedMonth.'-01') === 'leased') {
            return redirect()->back()->with('error', 'Pay lease rent through its Rent expense payment page. Do not record the same rent again as an owner remittance.');
        }
        if (! $this->allowed('finance.manage')) {
            return haven_service('response')->setStatusCode(403)->setContent('Permission denied.');
        }
        $unitId = (int) $this->request->getPost('unit_id');
        $month = trim((string) $this->request->getPost('month'));
        $date = trim((string) $this->request->getPost('payment_date'));
        $amount = trim((string) $this->request->getPost('amount'));
        $method = trim((string) $this->request->getPost('payment_method'));
        $reference = trim((string) $this->request->getPost('reference'));
        $notes = trim((string) $this->request->getPost('notes'));
        $back = site_url('owner-remittances').'?month='.rawurlencode($month).'&unit_id='.$unitId;
        $dateObj = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (! $unitId || ! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)
            || ! $dateObj || $dateObj->format('Y-m-d') !== $date
            || ! preg_match('/^\d{1,10}(?:\.\d{1,2})?$/', $amount)
            || ! in_array($method, ['mpesa', 'bank', 'cash'], true)
            || strlen($reference) > 100 || strlen($notes) > 10000) {
            return redirect()->to($back)->with('error', 'Please check the remittance details.');
        }

        $db = db_connect();
        $calc = new OwnerRemittanceCalculator;
        $amountCents = $calc->cents($amount);
        if ($amountCents <= 0) {
            return redirect()->to($back)->with('error', 'Amount must be greater than zero.');
        }
        $db->transBegin();
        try {
            // Lock the unit row to serialize remittances for the same unit.
            $unit = $db->query('SELECT id FROM units WHERE id = ? FOR UPDATE', [$unitId])->getRowArray();
            if (! $unit) {
                throw new \RuntimeException('Unit not found.');
            }
            $guard = new AccountingPeriodGuard($db);
            $guard->assertOpen($unitId, $month.'-01');
            $guard->assertOpen($unitId, $date);
            $report = $calc->calculate($unitId, $month);
            if ($report['issues']) {
                throw new \RuntimeException(implode(' ', $report['issues']));
            }
            if ($amountCents > $calc->cents($report['outstanding'])) {
                throw new \RuntimeException('Remittance exceeds the outstanding monthly owner entitlement.');
            }
            $now = date('Y-m-d H:i:s');
            $ok = $db->table('owner_remittances')->insert([
                'unit_id' => $unitId, 'period_month' => $month.'-01',
                'payment_date' => $date, 'amount' => $calc->money($amountCents),
                'payment_method' => $method, 'reference' => $reference ?: null,
                'notes' => $notes ?: null, 'recorded_by' => (int) haven_auth()->user()->id,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            if (! $ok || $db->transStatus() === false) {
                throw new \RuntimeException('Could not save remittance.');
            }
            $db->transCommit();

            return redirect()->to($back)->with('success', 'Owner remittance recorded.');
        } catch (\Throwable $e) {
            $db->transRollback();

            return redirect()->to($back)->with('error', $e->getMessage());
        }
    }
}
