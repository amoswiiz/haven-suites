<?php

namespace App\Services;

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UnitAccounting
{
    private array $arrangements = [];

    private array $agreements = [];

    private bool $loaded = false;

    private function load(): void
    {
        if ($this->loaded) {
            return;
        }$this->loaded = true;
        foreach (DB::table('unit_operating_arrangements')->where('tenant_id', app('currentTenant')->id)->orderBy('starts_on')->get() as $row) {
            $this->arrangements[$row->unit_id][] = $row;
        }
        foreach (DB::connection('tenant')->table('unit_host_agreements')->get() as $row) {
            $this->agreements[$row->unit_id][] = $row;
        }
    }

    public function arrangement(int $unit, string $date): ?object
    {
        $this->load();
        foreach ($this->arrangements[$unit] ?? [] as $row) {
            if ($row->starts_on <= $date && (! $row->ends_on || $row->ends_on >= $date)) {
                return $row;
            }
        }

        return null;
    }

    public function mode(int $unit, string $date): string
    {
        return $this->arrangement($unit, $date)?->mode ?? 'managed';
    }

    public function matchingAgreements(int $unit, string $date, array $fallback): array
    {
        $row = $this->arrangement($unit, $date);
        if (! $row) {
            return $fallback;
        }if ($row->mode === 'leased') {
            return [];
        }

        return [['id' => -$row->id, 'unit_id' => $unit, 'host_name' => $row->landlord_name ?? 'Owner', 'commission_rate' => number_format($row->commission_basis_points / 100, 2, '.', ''), 'effective_from' => $row->starts_on, 'effective_to' => $row->ends_on]];
    }

    public function rate(int $unit, string $date): ?int
    {
        $row = $this->arrangement($unit, $date);
        if ($row) {
            return $row->mode === 'managed' ? $row->commission_basis_points : null;
        }
        $this->load();
        $matches = array_filter($this->agreements[$unit] ?? [], fn ($a) => $a->effective_from <= $date && (! $a->effective_to || $a->effective_to >= $date));
        if (count($matches) !== 1) {
            return null;
        }$rate = (string) array_values($matches)[0]->commission_rate;

        return preg_match('/^\d{1,3}(?:\.\d{1,2})?$/D', $rate) && (float) $rate <= 100 ? (int) round((float) $rate * 100) : null;
    }

    public function rentDue(int $unit, string $month): int
    {
        $row = $this->arrangement($unit, $month.'-01');

        return $row?->mode === 'leased' ? $row->monthly_rent_minor : 0;
    }

    public function postRent(int $unit, string $month): ?int
    {
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $month) || $month > now(app('currentTenant')->timezone)->addYear()->format('Y-m')) {
            throw ValidationException::withMessages(['month' => 'Choose a valid month within the next twelve months. Future expenses support prepaid rent and remain allocated to their own accounting month.']);
        }
        $row = $this->arrangement($unit, $month.'-01');
        if (! $row || $row->mode !== 'leased') {
            return null;
        }
        $tenant = app('currentTenant');
        $money = new OwnerRemittanceCalculator;

        return DB::transaction(function () use ($tenant, $unit, $month, $row, $money): int {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $existing = DB::table('unit_rent_charges')->where('tenant_id', $tenant->id)->where('unit_id', $unit)->where('period_month', $month.'-01')->first();
            if ($existing?->expense_id) {
                return (int) $existing->expense_id;
            }

            return DB::connection('tenant')->transaction(function () use ($tenant, $unit, $month, $row, $money, $existing): int {
                $db = DB::connection('tenant');
                $db->table('units')->where('id', $unit)->lockForUpdate()->firstOrFail();
                (new AccountingPeriodGuard(db_connect()))->assertOpen($unit, $month.'-01');
                $category = $db->table('expense_categories')->where('name', 'Rent')->first();
                if (! $category) {
                    $category = (object) ['id' => $db->table('expense_categories')->insertGetId(['name' => 'Rent', 'created_at' => now(), 'updated_at' => now()])];
                }
                $manual = $db->table('unit_expenses')->where('unit_id', $unit)->where('category_id', $category->id)->where('expense_date', '>=', $month.'-01')->where('expense_date', '<', date('Y-m-d', strtotime($month.'-01 +1 month')))->where('source_type', '!=', 'operating_rent')->get();
                if ($manual->count() > 1 || ($manual->count() === 1 && ($manual->first()->source_type !== 'manual' || $money->cents($manual->first()->amount) !== $row->monthly_rent_minor))) {
                    throw ValidationException::withMessages(['rent' => 'Existing rent expenses do not match the monthly obligation. Reconcile the amount before posting recurring rent.']);
                }
                $charge = $existing?->id ?? DB::table('unit_rent_charges')->insertGetId(['tenant_id' => $tenant->id, 'unit_id' => $unit, 'arrangement_id' => $row->id, 'period_month' => $month.'-01', 'amount_minor' => $row->monthly_rent_minor, 'created_at' => now(), 'updated_at' => now()]);
                if ($manual->count() === 1) {
                    $id = $manual->first()->id;
                    $db->table('unit_expenses')->where('id', $id)->update(['source_type' => 'operating_rent', 'source_id' => $charge, 'updated_at' => now()]);
                    DB::table('unit_rent_charges')->where('id', $charge)->update(['expense_id' => $id, 'updated_at' => now()]);
                    DB::table('unit_accounting_events')->insert(['tenant_id' => $tenant->id, 'unit_id' => $unit, 'actor_id' => auth()->id(), 'action' => 'rent.expense.linked', 'details' => 'Existing expense #'.$id.' linked to '.$month.' rent; payment history retained.', 'created_at' => now(), 'updated_at' => now()]);

                    return $id;
                }
                $id = $db->table('unit_expenses')->insertGetId(['unit_id' => $unit, 'category_id' => $category->id, 'expense_date' => $month.'-01', 'description' => 'Monthly rent / '.$month, 'amount' => $money->money($row->monthly_rent_minor), 'amount_paid' => '0.00', 'payment_status' => 'unpaid', 'supplier_name' => $row->landlord_name, 'source_type' => 'operating_rent', 'source_id' => $charge, 'notes' => 'Generated from dated operating arrangement #'.$row->id.'. Pay through expense payments; do not duplicate as owner remittance.', 'created_at' => now(), 'updated_at' => now()]);
                DB::table('unit_rent_charges')->where('id', $charge)->update(['expense_id' => $id, 'updated_at' => now()]);

                return $id;
            });
        });
    }

    public function report(string $month, ?int $unit = null): array
    {
        $money = new OwnerRemittanceCalculator;
        $db = DB::connection('tenant');
        $start = $month.'-01';
        $end = date('Y-m-d', strtotime($start.' +1 month'));
        $today = now(app('currentTenant')->timezone)->toDateString();
        $rows = [];
        $units = $db->table('units as u')->leftJoin('properties as p', 'p.id', '=', 'u.property_id')->select('u.id', 'u.unit_number', 'p.name as property_name');
        if ($unit) {
            $units->where('u.id', $unit);
        }
        foreach ($units->get() as $u) {
            $mode = $this->mode($u->id, $start);
            $rows[$u->id] = ['unit_id' => $u->id, 'unit' => $u->property_name.' / '.$u->unit_number, 'mode' => $mode, 'guest_revenue' => 0, 'company_income' => 0, 'owner_earned' => 0, 'rent' => 0, 'other_expenses' => 0, 'profit' => 0, 'issues' => []];
        }
        $stays = $db->table('reservations')->whereIn('unit_id', array_keys($rows))->where('booking_type', 'managed')->whereIn('status', ['checked_in', 'checked_out'])->where('check_in', '<', $end)->where('check_out', '>', $start)->get(['unit_id', 'check_in', 'check_out', 'total_amount']);
        foreach ($stays as $stay) {
            $n = (new \DateTimeImmutable($stay->check_in))->diff(new \DateTimeImmutable($stay->check_out))->days;
            if ($n < 1) {
                continue;
            }$cents = $money->cents($stay->total_amount);
            $from = max($start, $stay->check_in);
            $until = min($end, $stay->check_out, $today);
            for ($day = $from; $day < $until; $day = date('Y-m-d', strtotime($day.' +1 day'))) {
                $offset = (new \DateTimeImmutable($stay->check_in))->diff(new \DateTimeImmutable($day))->days;
                $value = intdiv($cents, $n) + ($offset < $cents % $n ? 1 : 0);
                $rows[$stay->unit_id]['guest_revenue'] += $value;
                if ($this->mode($stay->unit_id, $day) === 'leased') {
                    $rows[$stay->unit_id]['company_income'] += $value;
                } else {
                    $rate = $this->rate($stay->unit_id, $day);
                    if ($rate === null) {
                        $rows[$stay->unit_id]['issues'][] = 'Management fee not configured.';

                        continue;
                    }$fee = intdiv($value * $rate + 5000, 10000);
                    $rows[$stay->unit_id]['company_income'] += $fee;
                    $rows[$stay->unit_id]['owner_earned'] += $value - $fee;
                }
            }
        }
        foreach ($db->table('unit_expenses as e')->leftJoin('expense_categories as c', 'c.id', '=', 'e.category_id')->whereIn('e.unit_id', array_keys($rows))->where('e.expense_date', '>=', $start)->where('e.expense_date', '<', $end)->get(['e.unit_id', 'e.amount', 'e.source_type', 'c.name as category']) as $expense) {
            $field = $expense->source_type === 'operating_rent' || strcasecmp($expense->category ?? '', 'Rent') === 0 ? 'rent' : 'other_expenses';
            $rows[$expense->unit_id][$field] += $money->cents($expense->amount);
        }
        if (! $unit) {
            $partner = ['unit_id' => 0, 'unit' => 'Partner bookings', 'mode' => 'partner', 'guest_revenue' => 0, 'company_income' => 0, 'owner_earned' => 0, 'rent' => 0, 'other_expenses' => 0, 'profit' => 0, 'issues' => []];
            foreach ($db->table('reservations')->where('booking_type', 'partner')->where('status', 'checked_out')->where('check_out', '>=', $start)->where('check_out', '<', $end)->get(['id', 'total_amount', 'owner_net_amount']) as $booking) {
                if ($booking->owner_net_amount === null || $money->cents($booking->owner_net_amount) > $money->cents($booking->total_amount)) {
                    $partner['issues'][] = 'Check partner payout for booking #'.$booking->id;

                    continue;
                }
                $gross = $money->cents($booking->total_amount);
                $payout = $money->cents($booking->owner_net_amount);
                $partner['guest_revenue'] += $gross;
                $partner['company_income'] += $gross - $payout;
                $partner['owner_earned'] += $payout;
            }
            if ($partner['guest_revenue'] || $partner['issues']) {
                $rows[0] = $partner;
            }
        }
        $totals = array_fill_keys(['guest_revenue', 'company_income', 'owner_earned', 'rent', 'other_expenses', 'profit'], 0);
        $issues = [];
        foreach ($rows as &$r) {
            $due = $month <= substr($today, 0, 7) ? $this->rentDue($r['unit_id'], $month) : 0;
            if ($due > $r['rent']) {
                $r['issues'][] = 'Rent due is included but has not been fully posted to the expense ledger.';
                $r['rent'] = $due;
            }$r['profit'] = $r['company_income'] - $r['rent'] - $r['other_expenses'];
            $r['issues'] = array_unique($r['issues']);
            foreach ($totals as $key => $_) {
                $totals[$key] += $r[$key];
            }foreach ($r['issues'] as $issue) {
                $issues[] = $r['unit'].': '.$issue;
            }
        }unset($r);

        return ['rows' => array_values($rows), 'totals' => $totals, 'issues' => $issues, 'month' => $month];
    }
}
