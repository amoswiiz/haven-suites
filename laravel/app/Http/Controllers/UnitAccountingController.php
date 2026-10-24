<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Services\OwnerRemittanceCalculator;
use App\Services\UnitAccounting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class UnitAccountingController extends Controller
{
    public function index(Request $request, UnitAccounting $accounting): View
    {
        $month = $request->validate(['month' => 'nullable|date_format:Y-m'])['month'] ?? now(app('currentTenant')->timezone)->format('Y-m');

        return view('accounting.units', ['month' => $month, 'report' => $accounting->report($month), 'arrangements' => DB::table('unit_operating_arrangements')->where('tenant_id', app('currentTenant')->id)->orderByDesc('starts_on')->get()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['unit_id' => 'required|integer', 'mode' => 'required|in:managed,leased', 'effective_month' => 'required|date_format:Y-m', 'commission' => 'nullable|required_if:mode,managed|numeric|min:0|max:100', 'monthly_rent' => 'nullable|required_if:mode,leased|numeric|min:0.01|max:999999999.99', 'landlord_name' => 'nullable|required_if:mode,leased|string|max:150', 'notes' => 'required|string|max:2000']);
        $unit = (int) $data['unit_id'];
        $start = $data['effective_month'].'-01';
        $db = DB::connection('tenant');
        $db->table('units')->where('id', $unit)->firstOrFail();
        $locked = $db->table('owner_statement_closings')->where('unit_id', $unit)->max('period_month');
        if (DB::table('unit_rent_charges')->where('tenant_id', app('currentTenant')->id)->where('unit_id', $unit)->where('period_month', '>=', $start)->exists()) {
            throw ValidationException::withMessages(['effective_month' => 'Rent has already been billed for this period or later. Start after the billed months; existing rent and prepaid payments must be reconciled before changing those terms.']);
        }
        if ($data['mode'] === 'leased' && $db->table('owner_remittances')->where('unit_id', $unit)->where('period_month', '>=', $start)->exists()) {
            throw ValidationException::withMessages(['effective_month' => 'Existing owner remittances overlap this lease period. Reconcile them before changing the payment workflow.']);
        }
        if (($locked && $start < date('Y-m-d', strtotime($locked.' +1 month'))) || $db->table('accounting_period_locks')->where('unit_id', $unit)->where('period_month', '>=', $start)->where('is_locked', 1)->exists()) {
            throw ValidationException::withMessages(['effective_month' => 'Start after all approved/locked months. Reopen accounting periods through the controlled workflow to correct history.']);
        }
        DB::transaction(function () use ($data, $unit, $start): void {
            $tenant = Tenant::whereKey(app('currentTenant')->id)->lockForUpdate()->firstOrFail();
            $last = DB::table('unit_operating_arrangements')->where('tenant_id', $tenant->id)->where('unit_id', $unit)->orderByDesc('starts_on')->first();
            if ($last && $start <= $last->starts_on) {
                throw ValidationException::withMessages(['effective_month' => 'New terms must start after the most recent arrangement. Existing terms are retained for audit.']);
            }
            if ($last) {
                DB::table('unit_operating_arrangements')->where('id', $last->id)->update(['ends_on' => date('Y-m-d', strtotime($start.' -1 day')), 'updated_at' => now()]);
            }
            $money = new OwnerRemittanceCalculator;
            DB::table('unit_operating_arrangements')->insert(['tenant_id' => $tenant->id, 'unit_id' => $unit, 'mode' => $data['mode'], 'starts_on' => $start, 'commission_basis_points' => $data['mode'] === 'managed' ? (int) round((float) $data['commission'] * 100) : 0, 'monthly_rent_minor' => $data['mode'] === 'leased' ? $money->cents(number_format((float) $data['monthly_rent'], 2, '.', '')) : 0, 'landlord_name' => $data['landlord_name'] ?? null, 'notes' => $data['notes'], 'created_by' => auth()->id(), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('unit_accounting_events')->insert(['tenant_id' => $tenant->id, 'unit_id' => $unit, 'actor_id' => auth()->id(), 'action' => 'arrangement.created', 'details' => json_encode($data), 'created_at' => now(), 'updated_at' => now()]);
        });
        if ($data['mode'] === 'leased' && $data['effective_month'] <= now(app('currentTenant')->timezone)->format('Y-m')) {
            try {
                (new UnitAccounting)->postRent($unit, now(app('currentTenant')->timezone)->format('Y-m'));
            } catch (\Throwable $e) {
                report($e);

                return back()->with('success', 'Arrangement saved.')->withErrors(['rent' => 'Rent posting needs review: '.$e->getMessage()]);
            }
        }

        return back()->with('success', 'Operating arrangement saved. Managed expenses are borne by the company; leased units include rent.');
    }

    public function rent(Request $request, UnitAccounting $accounting): RedirectResponse
    {
        $data = $request->validate(['unit_id' => 'required|integer', 'month' => 'required|date_format:Y-m']);
        $id = $accounting->postRent((int) $data['unit_id'], $data['month']);

        return back()->with('success', $id ? 'Rent is posted as an unpaid expense. Record any payment through the expense ledger.' : 'No lease rent applies to this unit and month.');
    }
}
