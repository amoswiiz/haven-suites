<?php

namespace App\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class UnitClosureController extends Controller
{
    public function index(): View
    {
        $this->authorizeManagement();
        $units = DB::connection('tenant')->table('units as u')->leftJoin('properties as p', 'p.id', '=', 'u.property_id')
            ->orderBy('p.name')->orderBy('u.unit_number')->get(['u.id', 'u.unit_number', 'p.name as property_name']);
        $closures = DB::table('public_unit_closures')->where('tenant_id', app('currentTenant')->id)
            ->orderByDesc('end_date')->orderByDesc('id')->paginate(40);

        return view('availability.closures', compact('units', 'closures'));
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeManagement();
        $today = CarbonImmutable::now(app('currentTenant')->timezone ?: config('app.timezone'))->toDateString();
        $data = $request->validate([
            'unit_id' => ['required', 'integer', 'min:1'],
            'start_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$today],
            'end_date' => ['required', 'date_format:Y-m-d', 'after:start_date'],
            'kind' => ['required', Rule::in(['owner', 'maintenance', 'other'])],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);
        if (CarbonImmutable::parse($data['start_date'])->diffInDays(CarbonImmutable::parse($data['end_date'])) > 730) {
            throw ValidationException::withMessages(['end_date' => 'Block up to two years at a time.']);
        }
        $db = DB::connection('tenant');
        $db->transaction(function () use ($db, $data): void {
            abort_unless($db->table('units')->where('id', $data['unit_id'])->lockForUpdate()->first(), 404);
            if ($db->table('reservations')->where('unit_id', $data['unit_id'])->whereIn('status', ['confirmed', 'checked_in'])
                ->where('check_in', '<', $data['end_date'])->where('check_out', '>', $data['start_date'])->exists()) {
                throw ValidationException::withMessages(['start_date' => 'These dates overlap an existing confirmed stay. Review the booking before blocking the apartment.']);
            }
            DB::transaction(function () use ($data): void {
                DB::table('public_unit_closures')->insert($data + [
                    'tenant_id' => app('currentTenant')->id, 'created_by' => auth()->id(), 'created_at' => now(), 'updated_at' => now(),
                ]);
            });
        }, 3);

        return redirect()->route('unit-closures.index', app('currentTenant')->slug)->with('success', 'Apartment dates blocked. The public calendar and local export now show them as unavailable.');
    }

    public function destroy(string $tenant, int $id): RedirectResponse
    {
        $this->authorizeManagement();
        $closure = DB::table('public_unit_closures')->where('tenant_id', app('currentTenant')->id)->where('id', $id)->firstOrFail();
        $db = DB::connection('tenant');
        $db->transaction(function () use ($db, $closure): void {
            abort_unless($db->table('units')->where('id', $closure->unit_id)->lockForUpdate()->first(), 404);
            DB::table('public_unit_closures')->where('tenant_id', app('currentTenant')->id)->where('id', $closure->id)->delete();
        }, 3);

        return redirect()->route('unit-closures.index', app('currentTenant')->slug)->with('success', 'Apartment block removed. Existing bookings and external calendar blocks still apply.');
    }

    private function authorizeManagement(): void
    {
        abort_unless(auth()->user()?->active && auth()->user()->can('properties.manage'), 403);
    }
}
