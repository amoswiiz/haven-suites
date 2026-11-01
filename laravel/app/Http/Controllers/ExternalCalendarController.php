<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Services\ExternalCalendars;
use App\Services\UnitCalendarExport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
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
        $exports = collect();
        if (auth()->user()->can('properties.manage')) {
            $exports = DB::table('unit_calendar_exports')->where('tenant_id', app('currentTenant')->id)->get()
                ->mapWithKeys(static fn (object $export): array => [(int) $export->unit_id => route('calendar.export', ['token' => Crypt::decryptString($export->token_encrypted)])]);
        }

        return view('availability.external', compact('units', 'feeds', 'exports'));
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

    public function generateExport(string $tenant, int $unitId): RedirectResponse
    {
        abort_unless(DB::connection('tenant')->table('units')->where('id', $unitId)->exists(), 404);
        $token = bin2hex(random_bytes(32));
        DB::table('unit_calendar_exports')->upsert([
            'tenant_id' => app('currentTenant')->id, 'unit_id' => $unitId,
            'token_hash' => hash('sha256', $token), 'token_encrypted' => Crypt::encryptString($token),
            'created_at' => now(), 'updated_at' => now(),
        ], ['tenant_id', 'unit_id'], ['token_hash', 'token_encrypted', 'updated_at']);

        return back()->with('success', 'Export link created. Any previous link for this apartment has been disabled.');
    }

    public function revokeExport(string $tenant, int $unitId): RedirectResponse
    {
        DB::table('unit_calendar_exports')->where('tenant_id', app('currentTenant')->id)->where('unit_id', $unitId)->delete();

        return back()->with('success', 'Export link disabled. External platforms can no longer refresh it.');
    }

    public function export(string $token, UnitCalendarExport $calendar): Response
    {
        $export = DB::table('unit_calendar_exports')->where('token_hash', hash('sha256', $token))->first();
        abort_unless($export, 404);
        $tenant = Tenant::whereKey($export->tenant_id)->where('active', true)->firstOrFail();

        return response($calendar->render($tenant, (int) $export->unit_id), 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'inline; filename="availability.ics"',
            'Cache-Control' => 'private, no-store',
            'X-Robots-Tag' => 'noindex, nofollow, noarchive',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }
}
