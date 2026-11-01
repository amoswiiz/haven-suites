<?php

namespace App\Http\Controllers;

use App\Services\ExternalCalendars;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ExternalCalendarController extends Controller
{
    public function index(): View
    {
        $units = DB::connection('tenant')->table('units as u')->leftJoin('properties as p', 'p.id', '=', 'u.property_id')
            ->orderBy('p.name')->orderBy('u.unit_number')->get(['u.id', 'u.unit_number', 'p.name as property_name']);
        $feeds = DB::table('external_calendars')->where('tenant_id', app('currentTenant')->id)
            ->orderBy('name')->get(['id', 'unit_id', 'name', 'last_synced_at', 'last_error']);

        return view('availability.external', compact('units', 'feeds'));
    }

    public function store(Request $request, ExternalCalendars $calendars): RedirectResponse
    {
        $data = $request->validate(['unit_id' => ['required', 'integer'], 'name' => ['required', 'string', 'max:100'], 'url' => ['required', 'string', 'max:2048']]);
        if (! DB::connection('tenant')->table('units')->where('id', $data['unit_id'])->exists()) {
            throw ValidationException::withMessages(['unit_id' => 'Choose an apartment in this company.']);
        }
        try {
            $calendars->validateUrl($data['url']);
        } catch (\RuntimeException $exception) {
            throw ValidationException::withMessages(['url' => $exception->getMessage()]);
        }
        $tenantId = app('currentTenant')->id;
        $id = DB::table('external_calendars')->insertGetId([
            'tenant_id' => $tenantId, 'unit_id' => $data['unit_id'], 'name' => $data['name'],
            'url_encrypted' => Crypt::encryptString($data['url']), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $ok = $calendars->sync($tenantId, $id);

        return redirect(site_url('availability/external'))->with($ok ? 'success' : 'error', $ok ? 'Calendar connected and dates imported.' : 'Calendar saved, but import failed. Review the sync status below.');
    }

    public function sync(string $tenant, int $id, ExternalCalendars $calendars): RedirectResponse
    {
        $tenantId = app('currentTenant')->id;
        abort_unless(DB::table('external_calendars')->where('tenant_id', $tenantId)->where('id', $id)->exists(), 404);
        $ok = $calendars->sync($tenantId, $id);

        return back()->with($ok ? 'success' : 'error', $ok ? 'Calendar refreshed.' : 'Import failed. Previously imported dates have been retained.');
    }

    public function destroy(string $tenant, int $id): RedirectResponse
    {
        DB::transaction(function () use ($id): void {
            $query = DB::table('external_calendars')->where('tenant_id', app('currentTenant')->id)->where('id', $id);
            abort_unless($query->lockForUpdate()->first(), 404);
            DB::table('external_calendar_blocks')->where('calendar_id', $id)->delete();
            $query->delete();
        });

        return back()->with('success', 'Calendar disconnected and its imported blocks removed.');
    }
}
