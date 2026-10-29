<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Services\ApartmentReadiness;
use App\Support\CompanyModules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class WorkCentreController extends Controller
{
    private function eligible(string $kind): bool
    {
        return auth()->user()->can($kind === 'finance' ? 'finance.manage' : 'reservations.manage');
    }

    public function index(): View
    {
        abort_unless(auth()->user()->can('sales.view') || auth()->user()->can('reservations.view') || auth()->user()->can('finance.view'), 403);
        $requests = DB::table('workflow_requests')->where('workflow_requests.tenant_id', app('currentTenant')->id)
            ->where(function ($q): void {
                $q->where('workflow_requests.requested_by', auth()->id());
                foreach (['finance', 'reservations'] as $kind) {
                    if ($this->eligible($kind)) {
                        $q->orWhere('workflow_requests.kind', $kind);
                    }
                }
            })->leftJoin('users as assignee', 'assignee.id', '=', 'workflow_requests.assigned_to')
            ->leftJoin('users as requester', 'requester.id', '=', 'workflow_requests.requested_by')
            ->select('workflow_requests.*', 'assignee.name as assignee_name', 'requester.name as requester_name')->orderByRaw("CASE WHEN workflow_requests.status = 'open' THEN 0 ELSE 1 END")->orderBy('due_at')->paginate(30);
        $db = DB::connection('tenant');
        $cleaningReviews = auth()->user()->can('housekeeping.manage') && ! auth()->user()->inGroup('cleaner') ? $db->table('housekeeping as h')->join('units as u', 'u.id', '=', 'h.unit_id')->where('h.status', 'completed')->select('h.id', 'u.unit_number', 'h.completed_at')->limit(20)->get() : collect();
        $claims = auth()->user()->can('finance.manage') && CompanyModules::enabled('reservations') ? $db->table('haven_quote_payment_claims')->where('status', 'pending')->count() : 0;
        $paymentDifferences = auth()->user()->can('finance.manage') && CompanyModules::enabled('reservations') ? $db->table('reservations as r')->leftJoinSub($db->table('guest_payments')->selectRaw('reservation_id, SUM(amount) as total')->groupBy('reservation_id'), 'gp', 'gp.reservation_id', '=', 'r.id')->where('r.amount_paid', '>', 0)->whereRaw('ROUND(r.amount_paid * 100) <> ROUND(COALESCE(gp.total,0) * 100)')->selectRaw('r.id, r.guest_name, r.amount_paid, COALESCE(gp.total,0) as recorded_total')->limit(50)->get() : collect();

        return view('work.index', compact('requests', 'cleaningReviews', 'claims', 'paymentDifferences'))->with('pageTitle', 'Work inbox & approvals');
    }

    public function store(string $tenant, Request $request): RedirectResponse
    {
        $data = $request->validate(['kind' => ['required', Rule::in(['finance', 'reservations'])], 'resource_id' => 'required|integer|min:1', 'assigned_to' => 'required|integer', 'due_at' => 'required|date|after:now', 'message' => 'required|string|max:2000']);
        if ($data['kind'] === 'finance') {
            abort_unless(auth()->user()->can('reservations.view') && CompanyModules::enabled('finance'), 403);
            abort_unless(DB::connection('tenant')->table('reservations')->where('id', $data['resource_id'])->where('status', '!=', 'cancelled')->exists(), 404);
        } else {
            abort_unless(auth()->user()->can('sales.manage') && CompanyModules::enabled('reservations'), 403);
            abort_unless(DB::table('sales_leads')->where('tenant_id', app('currentTenant')->id)->where('id', $data['resource_id'])->whereNull('reservation_id')->whereNotIn('stage', ['won', 'lost'])->exists(), 404);
        }
        $assignee = app('currentTenant')->users()->where('users.id', $data['assigned_to'])->first();
        if (! $assignee || ! $assignee->can($data['kind'] === 'finance' ? 'finance.manage' : 'reservations.manage')) {
            throw ValidationException::withMessages(['assigned_to' => 'Choose a team member who can perform this action.']);
        }
        DB::transaction(function () use ($data): void {
            Tenant::whereKey(app('currentTenant')->id)->lockForUpdate()->firstOrFail();
            if (DB::table('workflow_requests')->where('tenant_id', app('currentTenant')->id)->where('kind', $data['kind'])->where('resource_id', $data['resource_id'])->where('status', 'open')->exists()) {
                throw ValidationException::withMessages(['message' => 'An open request already exists. Check the work inbox for its progress.']);
            }
            DB::table('workflow_requests')->insert([...$data, 'tenant_id' => app('currentTenant')->id, 'requested_by' => auth()->id(), 'status' => 'open', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        });

        return back()->with('success', 'Request assigned. Follow its progress in the work inbox. No booking, invoice or payment was created.');
    }

    public function resolve(string $tenant, int $id, Request $request): RedirectResponse
    {
        $row = DB::table('workflow_requests')->where('tenant_id', app('currentTenant')->id)->where('id', $id)->first();
        abort_unless($row, 404);
        abort_unless($this->eligible($row->kind), 403);
        $data = $request->validate(['status' => ['required', Rule::in(['completed', 'declined'])], 'resolution' => 'required|string|max:2000', 'version' => 'required|integer']);
        $changed = DB::table('workflow_requests')->where('tenant_id', app('currentTenant')->id)->where('id', $id)->where('status', 'open')->where('version', $data['version'])->update(['status' => $data['status'], 'resolution' => $data['resolution'], 'resolved_by' => auth()->id(), 'resolved_at' => now(), 'version' => $data['version'] + 1, 'updated_at' => now()]);
        if (! $changed) {
            throw ValidationException::withMessages(['version' => 'This request changed. Refresh before resolving it.']);
        }

        return back()->with('success', 'Request updated. This acknowledgement does not itself record a payment or change a booking.');
    }

    public function today(ApartmentReadiness $readiness): View
    {
        abort_unless(auth()->user()->can('units.view') && auth()->user()->can('reservations.view'), 403);

        return view('work.today', ['apartments' => $readiness->overview(), 'pageTitle' => "Today's work"]);
    }

    public function setup(): View
    {
        abort_unless(auth()->user()->inGroup('admin', 'superadmin'), 403);
        $tenant = app('currentTenant');
        $db = DB::connection('tenant');
        $checks = [
            ['label' => 'Company contact details', 'done' => (bool) ($tenant->contact_email && $tenant->contact_phone), 'path' => 'company/settings'],
            ['label' => 'Apartments and nightly pricing', 'done' => $db->table('units')->where('status', 'active')->where('nightly_rate', '>', 0)->exists(), 'path' => 'units'],
            ['label' => 'Apartment accounting terms', 'done' => DB::table('unit_operating_arrangements')->where('tenant_id', $tenant->id)->exists(), 'path' => 'accounting/units'],
            ['label' => 'Invoice details and defaults', 'done' => ! empty($tenant->invoice_preferences), 'path' => 'invoices/settings'],
            ['label' => 'Team access', 'done' => $tenant->users()->count() > 1, 'path' => 'company/team'],
            ['label' => 'Cleaning rates', 'done' => $db->table('cleaning_rates')->exists(), 'path' => 'settings/cleaning-rates'],
            ['label' => 'Communications readiness', 'done' => (bool) (config('services.roycetalk.manual_live', false) && config('services.roycetalk.api_key') && config('services.roycetalk.sender_id')), 'path' => 'communications/sms'],
        ];

        return view('work.setup', compact('checks'));
    }
}
