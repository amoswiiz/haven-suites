<?php

namespace App\Http\Controllers;

use App\Models\CompanyInvitation;
use App\Models\Tenant;
use App\Notifications\CompanyInvitationNotification;
use App\Services\CompanyMembership;
use App\Services\InvestorNotifications;
use App\Services\OwnerRemittanceCalculator;
use App\Services\UnitAccounting;
use Dompdf\Dompdf;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class InvestorController extends Controller
{
    private function rows(string $table): Builder
    {
        return DB::table($table)->where('tenant_id', app('currentTenant')->id);
    }

    private function investor(int $id): object
    {
        return $this->rows('investors')->where('id', $id)->firstOrFail();
    }

    private function audit(int $id, string $action, string $details): void
    {
        DB::table('investor_audit_events')->insert(['tenant_id' => app('currentTenant')->id, 'investor_id' => $id, 'actor_id' => auth()->id(), 'action' => $action, 'details' => $details, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function portalInvestor(): object
    {
        abort_unless(auth()->user()->hasVerifiedEmail(), 403, 'Verify your email before accessing the investor portal.');

        return $this->rows('investors')->where('user_id', auth()->id())->where('active', true)->firstOrFail();
    }

    private function units(): Collection
    {
        return DB::connection('tenant')->table('units as u')->leftJoin('properties as p', 'p.id', '=', 'u.property_id')->select('u.id', 'u.unit_number', 'p.name as property_name')->orderBy('p.name')->get();
    }

    public function reports(Request $request): View|Response
    {
        $investor = $this->portalInvestor();
        $data = $request->validate([
            'period' => 'nullable|in:monthly,yearly', 'month' => 'nullable|date_format:Y-m',
            'year' => 'nullable|integer|between:2000,2100', 'unit_id' => 'nullable|integer|min:0',
            'download' => 'nullable|in:pdf,csv',
        ]);
        $period = $data['period'] ?? 'monthly';
        $month = $data['month'] ?? now(app('currentTenant')->timezone ?: 'Africa/Nairobi')->format('Y-m');
        $year = (int) ($data['year'] ?? substr($month, 0, 4));
        $unitId = (int) ($data['unit_id'] ?? 0);
        $assignments = $this->rows('investor_assignments')->where('investor_id', $investor->id)->get();
        abort_if($unitId && ! $assignments->contains('unit_id', $unitId), 404);
        $selected = $assignments->when($unitId, fn (Collection $items) => $items->where('unit_id', $unitId));
        $start = $period === 'yearly' ? sprintf('%04d-01-01', $year) : $month.'-01';
        $end = $period === 'yearly' ? sprintf('%04d-12-31', $year) : date('Y-m-t', strtotime($start));
        $statements = $this->rows('investor_statements')->where('investor_id', $investor->id)
            ->whereIn('assignment_id', $selected->pluck('id'))->whereNull('superseded_at')->whereNotNull('published_at')
            ->whereBetween('period_month', [$start, $end])->orderBy('period_month')->orderBy('id')->get();
        $labels = ['collected' => 'Owner guest collections', 'rental_income' => 'Fixed landlord rent', 'management_fee' => 'Management fees', 'expenses' => 'Owner-deductible expenses', 'entitlement' => 'Net entitlement'];
        $totals = array_fill_keys(array_keys($labels), 0);
        $balances = [];
        $rows = $statements->map(function (object $statement) use (&$totals, &$balances, $labels): array {
            $snapshot = json_decode($statement->snapshot, true);
            $amounts = [];
            foreach ($labels as $key => $label) {
                $amounts[$key] = app(OwnerRemittanceCalculator::class)->cents($snapshot['totals'][$key] ?? '0');
                $totals[$key] += $amounts[$key];
            }
            $closing = app(OwnerRemittanceCalculator::class)->cents($snapshot['totals']['closing_balance'] ?? '0');
            $balances[$statement->assignment_id] = ['amount' => $closing, 'month' => substr($statement->period_month, 0, 7), 'property' => $snapshot['property']];

            return ['id' => $statement->id, 'month' => substr($statement->period_month, 0, 7), 'property' => $snapshot['property'], 'currency' => $snapshot['currency'], 'amounts' => $amounts, 'closing' => $closing];
        });
        $currency = app('currentTenant')->currency;
        abort_if($rows->contains(fn (array $row): bool => $row['currency'] !== $currency), 422, 'Statements in different currencies cannot be combined.');
        $payouts = $this->rows('investor_payouts')->where('investor_id', $investor->id)->whereIn('assignment_id', $selected->pluck('id'))->whereBetween('payment_date', [$start, $end])->orderBy('payment_date')->get();
        abort_if($payouts->contains(fn (object $payout): bool => $payout->currency !== $currency), 422, 'Payouts in different currencies cannot be combined.');
        $filters = ['period' => $period, 'month' => $month, 'year' => $year, 'unit_id' => $unitId];
        $viewData = compact('investor', 'period', 'month', 'year', 'unitId', 'rows', 'labels', 'totals', 'balances', 'payouts', 'currency', 'filters');
        $viewData['units'] = $this->units()->whereIn('id', $assignments->pluck('unit_id'));
        $viewData['title'] = ($period === 'yearly' ? $year : $month).' investor summary';
        $filename = 'investor-summary-'.($period === 'yearly' ? $year : $month).($unitId ? '-unit-'.$unitId : '-all-units');
        if (($data['download'] ?? null) === 'pdf') {
            $pdf = new Dompdf;
            $pdf->loadHtml(view('investors.report_pdf', $viewData)->render());
            $pdf->setPaper('A4', 'landscape');
            $pdf->render();

            return response($pdf->output(), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="'.$filename.'.pdf"', 'Cache-Control' => 'private, no-store']);
        }
        if (($data['download'] ?? null) === 'csv') {
            $stream = fopen('php://temp', 'w+');
            $write = static function (array $values) use ($stream): void {
                fputcsv($stream, array_map(static fn ($value) => is_string($value) && ! is_numeric($value) && preg_match('/^[\s]*[=+@-]/', $value) ? "'".$value : $value, $values), ',', '"', '');
            };
            $write([$viewData['title'], $investor->name, $currency, 'Published statements only; missing months are not zero.']);
            $write(['Month', 'Unit', ...array_values($labels), 'Closing balance']);
            foreach ($rows as $row) {
                $write([$row['month'], $row['property'], ...array_map(static fn (int $amount): string => number_format($amount / 100, 2, '.', ''), $row['amounts']), number_format($row['closing'] / 100, 2, '.', '')]);
            }
            $write(['Flow totals', '', ...array_map(static fn (int $amount): string => number_format($amount / 100, 2, '.', ''), $totals)]);
            $write(['Latest published balances within selection (not summed across months)']);
            foreach ($balances as $balance) {
                $write([$balance['month'], $balance['property'], number_format($balance['amount'] / 100, 2, '.', '')]);
            }
            $write(['Published payouts by payment date', 'Unit', 'Amount', 'Reference']);
            foreach ($payouts as $payout) {
                $write([$payout->payment_date, $payout->property_label, number_format($payout->amount_minor / 100, 2, '.', ''), $payout->reference]);
            }
            rewind($stream);
            $csv = stream_get_contents($stream);
            fclose($stream);

            return response($csv, 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="'.$filename.'.csv"', 'Cache-Control' => 'private, no-store']);
        }

        return view('investors.reports', $viewData);
    }

    public function index(): View
    {
        $investors = $this->rows('investors')->orderBy('name')->paginate(30);
        $ids = $investors->pluck('id');

        return view('investors.index', ['investors' => $investors, 'assignments' => $this->rows('investor_assignments')->whereIn('investor_id', $ids)->get(), 'invitations' => $this->rows('company_invitations')->whereIn('investor_id', $ids)->orderByDesc('id')->get(), 'statements' => $this->rows('investor_statements')->whereIn('investor_id', $ids)->whereNull('superseded_at')->orderByDesc('period_month')->get(), 'payouts' => $this->rows('investor_payouts')->whereIn('investor_id', $ids)->orderByDesc('payment_date')->get()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => 'required|string|max:150', 'email' => 'required|email|max:180', 'phone' => 'nullable|regex:/^\+[1-9][0-9]{7,14}$/']);
        $data['email'] = strtolower($data['email']);
        if ($this->rows('investors')->where('email', $data['email'])->exists()) {
            throw ValidationException::withMessages(['email' => 'This investor already exists in this company.']);
        }
        $id = DB::transaction(function () use ($data): int {
            $id = DB::table('investors')->insertGetId([...$data, 'tenant_id' => app('currentTenant')->id, 'created_at' => now(), 'updated_at' => now()]);
            $this->audit($id, 'created', 'Investor profile created.');

            return $id;
        });

        return redirect()->route('investors.show', [app('currentTenant')->slug, $id])->with('success', 'Investor created. Assign properties and invite them to the portal.');
    }

    public function show(string $tenant, int $id): View
    {
        $investor = $this->investor($id);

        return view('investors.show', ['investor' => $investor, 'units' => $this->units(), 'assignments' => $this->rows('investor_assignments')->where('investor_id', $id)->get(), 'statements' => $this->rows('investor_statements')->where('investor_id', $id)->latest('id')->get(), 'payouts' => $this->rows('investor_payouts')->where('investor_id', $id)->latest('id')->limit(30)->get(), 'deliveries' => DB::table('investor_deliveries as d')->join('investor_events as e', 'e.id', '=', 'd.event_id')->where('d.tenant_id', app('currentTenant')->id)->where('e.investor_id', $id)->select('d.*', 'e.title')->latest('d.id')->limit(50)->get(), 'audit' => $this->rows('investor_audit_events')->where('investor_id', $id)->latest('id')->limit(30)->get(), 'invitation' => CompanyInvitation::where('tenant_id', app('currentTenant')->id)->where('investor_id', $id)->latest()->first()]);
    }

    public function update(string $tenant, int $id, Request $request): RedirectResponse
    {
        $investor = $this->investor($id);
        $data = $request->validate(['active' => 'required|boolean', 'phone' => 'nullable|regex:/^\+[1-9][0-9]{7,14}$/', 'sms_consent' => 'nullable|boolean', 'phone_confirmed' => 'nullable|boolean']);
        if ($investor->phone !== ($data['phone'] ?? null)) {
            $data['phone_verified_at'] = null;
            $data['sms_consented_at'] = null;
            $data['sms_notifications'] = false;
        }
        if ($request->boolean('phone_confirmed') && ! empty($data['phone'])) {
            $data['phone_verified_at'] = now();
        }
        if ($request->boolean('sms_consent') && ! empty($data['phone'])) {
            $data['sms_consented_at'] = now();
        }
        unset($data['sms_consent'],$data['phone_confirmed']);
        DB::transaction(function () use ($id, $data): void {
            $this->rows('investors')->where('id', $id)->update([...$data, 'updated_at' => now()]);
            $this->audit($id, 'access.updated', 'Investor access/contact updated; phone confirmation and consent are recorded only when explicitly confirmed.');
        });

        return back()->with('success', 'Investor access and contact updated.');
    }

    public function invite(string $tenant, int $id, CompanyMembership $membership): RedirectResponse
    {
        $investor = $this->investor($id);
        abort_unless($investor->active, 403);
        abort_unless(app('currentTenant')->modules['team'] ?? true, 403);
        $existing = app('currentTenant')->users()->where('email', $investor->email)->first();
        if ($existing) {
            $roles = json_decode($existing->pivot->roles, true);
            if (! in_array('owner', $roles, true) || ! $existing->hasVerifiedEmail()) {
                throw ValidationException::withMessages(['email' => 'An existing member must have verified email and the Owner role before linking this profile.']);
            }$this->rows('investors')->where('id', $id)->update(['user_id' => $existing->id, 'updated_at' => now()]);
            $this->audit($id, 'account.linked', 'Linked existing verified owner account.');

            return back()->with('success', 'Existing investor login linked.');
        }
        $token = bin2hex(random_bytes(32));
        $invitation = DB::transaction(function () use ($investor, $membership, $token): CompanyInvitation {
            $company = Tenant::whereKey($investor->tenant_id)->lockForUpdate()->firstOrFail();
            $pending = CompanyInvitation::where('tenant_id', $company->id)->where('email', '!=', $investor->email)->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now())->count();
            $membership->assertCapacity($company, $pending);
            CompanyInvitation::where('tenant_id', $company->id)->where('email', $investor->email)->whereNull('accepted_at')->update(['revoked_at' => now()]);

            return CompanyInvitation::create(['tenant_id' => $company->id, 'investor_id' => $investor->id, 'invited_by' => auth()->id(), 'email' => $investor->email, 'role' => 'owner', 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDays(7)]);
        });
        try {
            Notification::route('mail', $investor->email)->notify(new CompanyInvitationNotification(app('currentTenant')->name, route('invitations.show', $token)));
        } catch (\Throwable $exception) {
            $invitation->update(['revoked_at' => now()]);
            report($exception);

            return back()->withErrors(['email' => 'Invitation delivery failed. Check email settings.']);
        }
        $this->audit($id, 'invited', 'Expiring investor invitation issued through the configured email transport.');

        return back()->with('success', 'Invitation issued through the configured email transport. Existing account holders sign in; new investors register and verify their email before accepting.');
    }

    public function assignment(string $tenant, int $id, Request $request): RedirectResponse
    {
        $this->investor($id);
        $data = $request->validate(['unit_id' => 'required|integer', 'share' => 'required|numeric|min:0.01|max:100', 'starts_on' => 'required|date_format:Y-m-d', 'ends_on' => 'nullable|date_format:Y-m-d|after_or_equal:starts_on', 'agreement_reference' => 'required|string|max:255']);
        DB::connection('tenant')->table('units')->where('id', $data['unit_id'])->firstOrFail();
        DB::transaction(function () use ($data, $id): void {
            Tenant::whereKey(app('currentTenant')->id)->lockForUpdate()->firstOrFail();
            $rows = $this->rows('investor_assignments')->where('unit_id', $data['unit_id'])->where('starts_on', '<=', $data['ends_on'] ?? '9999-12-31')->where(function (Builder $q) use ($data): void {
                $q->whereNull('ends_on')->orWhere('ends_on', '>=', $data['starts_on']);
            })->get();
            $points = [$data['starts_on']];
            foreach ($rows as $row) {
                $points[] = max($data['starts_on'], $row->starts_on);
                if ((int) $row->investor_id === $id) {
                    throw ValidationException::withMessages(['share' => 'This investor already has an overlapping assignment for this unit.']);
                }
            }
            $share = (int) round((float) $data['share'] * 100);
            foreach ($points as $date) {
                $sum = $share;
                foreach ($rows as $row) {
                    if ($row->starts_on <= $date && (! $row->ends_on || $row->ends_on >= $date)) {
                        $sum += $row->share_basis_points;
                    }
                }if ($sum > 10000) {
                    throw ValidationException::withMessages(['share' => 'Ownership shares exceed 100% during this assignment period.']);
                }
            }
            unset($data['share']);
            DB::table('investor_assignments')->insert([...$data, 'share_basis_points' => $share, 'tenant_id' => app('currentTenant')->id, 'investor_id' => $id, 'created_at' => now(), 'updated_at' => now()]);
            $this->audit($id, 'property.assigned', 'Unit #'.$data['unit_id'].' assigned at '.($share / 100).'%, agreement '.$data['agreement_reference'].'.');
        });

        return back()->with('success', 'Property access assigned for the specified ownership period.');
    }

    public function publish(string $tenant, int $id, Request $request, InvestorNotifications $notifications): RedirectResponse
    {
        $investor = $this->investor($id);
        $data = $request->validate(['assignment_id' => 'required|integer', 'month' => 'required|date_format:Y-m', 'correction_reason' => 'nullable|string|max:255']);
        $assignment = $this->rows('investor_assignments')->where('investor_id', $id)->where('id', $data['assignment_id'])->firstOrFail();
        $start = $data['month'].'-01';
        $end = date('Y-m-t', strtotime($start));
        if ($assignment->starts_on > $start || ($assignment->ends_on && $assignment->ends_on < $end)) {
            throw ValidationException::withMessages(['month' => 'Only full months covered by this ownership assignment can be published. Partial ownership months require an agreed reconciliation.']);
        }
        $closing = DB::connection('tenant')->table('owner_statement_closings')->where('unit_id', $assignment->unit_id)->where('period_month', $start)->first();
        if (! $closing || ! hash_equals($closing->statement_hash, hash('sha256', $closing->statement_json))) {
            throw ValidationException::withMessages(['month' => 'Approve this accounting month first. The approved statement must have a valid integrity hash.']);
        }
        $report = json_decode($closing->statement_json, true, 512, JSON_THROW_ON_ERROR);
        if (! empty($report['issues'])) {
            throw ValidationException::withMessages(['month' => 'Resolve accounting issues before publishing.']);
        }
        $unit = $this->units()->firstWhere('id', $assignment->unit_id);
        abort_unless($unit, 404);
        $calculator = new OwnerRemittanceCalculator;
        $totals = [];
        $peers = $this->rows('investor_assignments')->where('unit_id', $assignment->unit_id)->where('starts_on', '<=', $end)->where(function (Builder $query) use ($start): void {
            $query->whereNull('ends_on')->orWhere('ends_on', '>=', $start);
        })->orderBy('id')->get();
        $before = 0;
        foreach ($peers as $peer) {
            if ($peer->starts_on > $start || ($peer->ends_on && $peer->ends_on < $end)) {
                throw ValidationException::withMessages(['month' => 'Ownership changed during this month. Reconcile the partial ownership periods before publishing.']);
            }
            if ((int) $peer->id < (int) $assignment->id) {
                $before += $peer->share_basis_points;
            }
        }
        foreach (['opening_balance', 'collected', 'rental_income', 'management_fee', 'expenses', 'entitlement', 'paid', 'closing_balance', 'outstanding', 'deficit_recovered', 'deficit_to_recover'] as $key) {
            $cents = $calculator->cents($report[$key] ?? '0');
            $allocated = intdiv(abs($cents) * ($before + $assignment->share_basis_points), 10000) - intdiv(abs($cents) * $before, 10000);
            $totals[$key] = $calculator->money($cents < 0 ? -$allocated : $allocated);
        }
        $opening = $calculator->cents($totals['opening_balance']);
        $entitlement = $calculator->cents($totals['collected']) + $calculator->cents($totals['rental_income']) - $calculator->cents($totals['management_fee']) - $calculator->cents($totals['expenses']);
        $balance = $opening + $entitlement - $calculator->cents($totals['paid']);
        $totals['entitlement'] = $calculator->money($entitlement);
        $totals['closing_balance'] = $calculator->money($balance);
        $totals['outstanding'] = $calculator->money(max(0, $balance));
        $totals['deficit_to_recover'] = $calculator->money(max(0, -$balance));
        $totals['deficit_recovered'] = $calculator->money(min(max(0, -$opening), max(0, $entitlement)));
        DB::transaction(function () use ($id, $data, $assignment, $start, $closing, $totals, $unit, $investor, $notifications, $report): void {
            Tenant::whereKey(app('currentTenant')->id)->lockForUpdate()->firstOrFail();
            $prior = $this->rows('investor_statements')->where('assignment_id', $assignment->id)->where('period_month', $start)->orderByDesc('revision')->first();
            if ($prior && empty($data['correction_reason'])) {
                throw ValidationException::withMessages(['correction_reason' => 'A correction reason is required to replace a published statement.']);
            }
            $revision = ($prior->revision ?? 0) + 1;
            $now = now();
            if ($prior) {
                $oldEvent = $this->rows('investor_events')->where('event_key', $investor->tenant_id.':'.$id.':statement:'.$prior->id)->first();
                if ($oldEvent) {
                    $this->rows('investor_deliveries')->where('event_id', $oldEvent->id)->whereIn('status', ['pending', 'blocked', 'failed'])->update(['status' => 'cancelled', 'last_error' => 'A corrected statement replaced this notification.', 'updated_at' => $now]);
                }
            }
            $this->rows('investor_statements')->where('assignment_id', $assignment->id)->where('period_month', $start)->whereNull('superseded_at')->update(['superseded_at' => $now]);
            $statement = DB::table('investor_statements')->insertGetId(['tenant_id' => $investor->tenant_id, 'investor_id' => $id, 'assignment_id' => $assignment->id, 'unit_id' => $assignment->unit_id, 'period_month' => $start, 'revision' => $revision, 'snapshot' => json_encode(['property' => $unit->property_name.' / '.$unit->unit_number, 'share' => $assignment->share_basis_points / 100, 'currency' => app('currentTenant')->currency, 'arrangement' => $report['arrangement'] ?? 'legacy', 'accounting_policy' => $report['accounting_policy'] ?? 'legacy-approved', 'totals' => $totals]), 'source_hash' => $closing->statement_hash, 'correction_reason' => $data['correction_reason'] ?? null, 'published_by' => auth()->id(), 'published_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
            $this->audit($id, 'statement.published', 'Statement #'.$statement.' revision '.$revision.' published.');
            $notifications->record($investor, 'statement:'.$statement, $prior ? 'statement_corrected' : 'statement_ready', $prior ? 'Statement corrected' : 'Monthly statement ready', $prior ? 'A corrected statement has been published. Sign in to review the revision and explanation.' : 'Your monthly statement is ready. Sign in to review it.');
        });

        return back()->with('success', 'Approved statement published. Email and SMS notifications queued according to preferences.');
    }

    public function endAssignment(string $tenant, int $id, int $assignment, Request $request): RedirectResponse
    {
        $this->investor($id);
        $data = $request->validate(['ends_on' => 'required|date_format:Y-m-d']);
        DB::transaction(function () use ($id, $assignment, $data): void {
            Tenant::whereKey(app('currentTenant')->id)->lockForUpdate()->firstOrFail();
            $row = $this->rows('investor_assignments')->where('investor_id', $id)->where('id', $assignment)->firstOrFail();
            if ($data['ends_on'] < $row->starts_on || ($row->ends_on && $data['ends_on'] > $row->ends_on)) {
                throw ValidationException::withMessages(['ends_on' => 'Choose an end date within the existing assignment. Create a new agreement to extend ownership.']);
            }
            $latest = $this->rows('investor_statements')->where('assignment_id', $assignment)->max('period_month');
            if ($latest && date('Y-m-t', strtotime($latest)) > $data['ends_on']) {
                throw ValidationException::withMessages(['ends_on' => 'The end date cannot invalidate an already published statement.']);
            }
            $this->rows('investor_assignments')->where('id', $assignment)->update(['ends_on' => $data['ends_on'], 'updated_at' => now()]);
            $this->audit($id, 'property.assignment.ended', 'Assignment #'.$assignment.' ends on '.$data['ends_on'].'.');
        });

        return back()->with('success', 'Ownership end date recorded. Historical statements remain available.');
    }

    public function payout(string $tenant, int $id, Request $request, InvestorNotifications $notifications): RedirectResponse
    {
        $investor = $this->investor($id);
        $data = $request->validate(['assignment_id' => 'required|integer', 'remittance_id' => 'nullable|required_without:rent_payment_id|integer', 'rent_payment_id' => 'nullable|required_without:remittance_id|integer']);
        $assignment = $this->rows('investor_assignments')->where('id', $data['assignment_id'])->where('investor_id', $id)->firstOrFail();
        if (! empty($data['remittance_id']) && ! empty($data['rent_payment_id'])) {
            throw ValidationException::withMessages(['remittance_id' => 'Choose either an owner remittance or a rent expense payment, not both.']);
        }
        $sourceField = ! empty($data['rent_payment_id']) ? 'rent_payment_id' : 'remittance_id';
        if ($sourceField === 'rent_payment_id') {
            $payment = DB::connection('tenant')->table('expense_payments as ep')->join('unit_expenses as e', 'e.id', '=', 'ep.expense_id')->where('e.unit_id', $assignment->unit_id)->where('e.source_type', 'operating_rent')->where('ep.id', $data['rent_payment_id'])->select('ep.id', 'ep.payment_date', 'ep.amount', 'ep.reference', 'e.expense_date as period_month')->firstOrFail();
        } else {
            $payment = DB::connection('tenant')->table('owner_remittances')->where('unit_id', $assignment->unit_id)->where('id', $data['remittance_id'])->firstOrFail();
            if ((new UnitAccounting)->mode($assignment->unit_id, $payment->period_month) === 'leased') {
                throw ValidationException::withMessages(['remittance_id' => 'Lease payouts must link to an existing rent expense payment.']);
            }
        }
        $periodEnd = date('Y-m-t', strtotime($payment->period_month));
        if ($assignment->starts_on > $payment->period_month || ($assignment->ends_on && $assignment->ends_on < $periodEnd)) {
            throw ValidationException::withMessages(['remittance_id' => 'The remittance accounting month must be fully covered by this ownership assignment.']);
        }
        $unit = $this->units()->firstWhere('id', $assignment->unit_id);
        $calc = new OwnerRemittanceCalculator;
        $peers = $this->rows('investor_assignments')->where('unit_id', $assignment->unit_id)->where('starts_on', '<=', $periodEnd)->where(function (Builder $query) use ($payment): void {
            $query->whereNull('ends_on')->orWhere('ends_on', '>=', $payment->period_month);
        })->orderBy('id')->get();
        $before = 0;
        foreach ($peers as $peer) {
            if ($peer->starts_on > $payment->period_month || ($peer->ends_on && $peer->ends_on < $periodEnd)) {
                throw ValidationException::withMessages(['remittance_id' => 'Reconcile partial ownership periods before publishing this payout.']);
            }
            if ($peer->id < $assignment->id) {
                $before += $peer->share_basis_points;
            }
        }
        $cents = $calc->cents($payment->amount);
        abort_unless($cents > 0, 422, 'Publish positive remittances only.');
        $allocated = intdiv($cents * ($before + $assignment->share_basis_points), 10000) - intdiv($cents * $before, 10000);
        DB::transaction(function () use ($investor, $assignment, $payment, $unit, $allocated, $notifications, $sourceField): void {
            if ($this->rows('investor_payouts')->where('investor_id', $investor->id)->where($sourceField, $payment->id)->exists()) {
                throw ValidationException::withMessages(['remittance_id' => 'This remittance has already been published to this investor.']);
            }
            $id = DB::table('investor_payouts')->insertGetId(['tenant_id' => $investor->tenant_id, 'investor_id' => $investor->id, 'assignment_id' => $assignment->id, $sourceField => $payment->id, 'property_label' => $unit->property_name.' / '.$unit->unit_number, 'payment_date' => $payment->payment_date, 'amount_minor' => $allocated, 'reference' => $payment->reference, 'currency' => app('currentTenant')->currency, 'published_by' => auth()->id(), 'created_at' => now(), 'updated_at' => now()]);
            $this->audit($investor->id, 'payout.published', 'Existing '.$sourceField.' #'.$payment->id.' published; no new payment made.');
            $notifications->record($investor, 'payout:'.$id, 'payout_completed', 'Payout recorded', 'A payout has been recorded. Sign in to view its amount, date and reference.');
        });

        return back()->with('success', 'Recorded payout published. This action does not send money.');
    }

    public function portal(Request $request): View
    {
        $investor = $this->portalInvestor();
        $assignments = $this->rows('investor_assignments')->where('investor_id', $investor->id)->get();
        $section = $request->route('section') ?? 'dashboard';
        $unitIds = $assignments->pluck('unit_id');
        $unitId = $request->integer('unit_id');
        abort_if($unitId && ! $unitIds->contains($unitId), 404);
        $bookingLists = [];
        $month = $request->validate(['month' => 'nullable|date_format:Y-m', 'unit_id' => 'nullable|integer|min:0', 'tab' => 'nullable|in:current,upcoming,completed'])['month'] ?? now(app('currentTenant')->timezone)->format('Y-m');
        $monthStart = $month.'-01';
        $monthEnd = date('Y-m-d', strtotime($monthStart.' +1 month'));
        $monthly = ['bookings' => 0, 'nights' => 0, 'value_minor' => 0];
        if (app('currentTenant')->modules['reservations'] ?? true) {
            $today = now(app('currentTenant')->timezone)->toDateString();
            $query = DB::connection('tenant')->table('reservations')->select('id', 'unit_id', 'check_in', 'check_out', 'status', 'nightly_rate', 'total_amount')->where('booking_type', 'managed');
            $query->where(function (Builder $scope) use ($assignments): void {
                $scope->whereRaw('1 = 0');
                foreach ($assignments as $assignment) {
                    $scope->orWhere(function (Builder $stay) use ($assignment): void {
                        $stay->where('unit_id', $assignment->unit_id)->where('check_in', '>=', $assignment->starts_on);
                        if ($assignment->ends_on) {
                            $stay->where('check_out', '<=', date('Y-m-d', strtotime($assignment->ends_on.' +1 day')));
                        }
                    });
                }
            });
            if ($unitId) {
                $query->where('unit_id', $unitId);
            }
            $calculator = new OwnerRemittanceCalculator;
            foreach ((clone $query)->whereIn('status', ['confirmed', 'checked_in', 'checked_out'])->where('check_in', '<', $monthEnd)->where('check_out', '>', $monthStart)->get() as $stay) {
                $nights = (new \DateTimeImmutable($stay->check_in))->diff(new \DateTimeImmutable($stay->check_out))->days;
                if ($nights < 1) {
                    continue;
                }
                $from = max($stay->check_in, $monthStart);
                $to = min($stay->check_out, $monthEnd);
                $offset = (new \DateTimeImmutable($stay->check_in))->diff(new \DateTimeImmutable($from))->days;
                $count = (new \DateTimeImmutable($from))->diff(new \DateTimeImmutable($to))->days;
                $total = $calculator->cents($stay->total_amount);
                $remainder = $total % $nights;
                $monthly['bookings']++;
                $monthly['nights'] += $count;
                $monthly['value_minor'] += intdiv($total, $nights) * $count + max(0, min($offset + $count, $remainder) - $offset);
            }
            $bookingLists = [
                'Upcoming bookings' => (clone $query)->where('check_in', '>=', $today)->whereIn('status', ['pending', 'confirmed'])->orderBy('check_in')->orderBy('id')->paginate(10, ['*'], 'upcoming_page')->withQueryString(),
                'Currently checked in' => (clone $query)->where('status', 'checked_in')->orderBy('check_out')->orderBy('id')->paginate(10, ['*'], 'in_house_page')->withQueryString(),
                'Checked-out bookings' => (clone $query)->where('status', 'checked_out')->orderByDesc('check_out')->orderByDesc('id')->paginate(10, ['*'], 'past_page')->withQueryString(),
            ];
        }

        $published = $this->rows('investor_statements')->where('investor_id', $investor->id)->where('period_month', $monthStart)->whereNull('superseded_at');
        if ($unitId) {
            $published->where('unit_id', $unitId);
        }
        $monthly['expected_units'] = $assignments->filter(fn (object $assignment): bool => (! $unitId || (int) $assignment->unit_id === $unitId) && $assignment->starts_on < $monthEnd && (! $assignment->ends_on || $assignment->ends_on >= $monthStart))->pluck('unit_id')->unique()->count();
        $monthly['published_count'] = 0;
        $monthly['published_totals'] = array_fill_keys(['collected', 'rental_income', 'management_fee', 'expenses', 'entitlement', 'closing_balance'], 0);
        $calculator = new OwnerRemittanceCalculator;
        foreach ($published->get() as $statement) {
            $snapshot = json_decode($statement->snapshot, true);
            if ($snapshot['currency'] !== app('currentTenant')->currency) {
                continue;
            }
            $monthly['published_count']++;
            foreach ($monthly['published_totals'] as $key => $value) {
                $monthly['published_totals'][$key] += $calculator->cents($snapshot['totals'][$key] ?? '0');
            }
        }
        $monthly['payout_minor'] = (int) $this->rows('investor_payouts')->where('investor_id', $investor->id)->where('currency', app('currentTenant')->currency)->where('payment_date', '>=', $monthStart)->where('payment_date', '<', $monthEnd)->whereIn('assignment_id', $assignments->filter(fn ($assignment) => ! $unitId || (int) $assignment->unit_id === $unitId)->pluck('id'))->sum('amount_minor');

        return view('investors.portal', ['unreadCount' => (app('currentTenant')->modules['notifications'] ?? true) ? $this->rows('investor_events')->where('investor_id', $investor->id)->whereNull('read_at')->count() : 0, 'section' => $section, 'monthly' => $monthly, 'reportMonth' => $month, 'bookingLists' => $bookingLists, 'selectedUnitId' => $unitId, 'investor' => $investor, 'assignments' => $assignments->filter(fn ($a) => ! $unitId || (int) $a->unit_id === $unitId), 'units' => $this->units()->whereIn('id', $unitIds), 'statements' => $this->rows('investor_statements')->where('investor_id', $investor->id)->whereNull('superseded_at')->when($unitId, fn ($q) => $q->where('unit_id', $unitId))->latest('period_month')->paginate(5, ['*'], 'statements_page')->withQueryString(), 'payouts' => $this->rows('investor_payouts')->where('investor_id', $investor->id)->whereIn('assignment_id', $assignments->filter(fn ($a) => ! $unitId || (int) $a->unit_id === $unitId)->pluck('id'))->latest('payment_date')->paginate(5, ['*'], 'payouts_page')->withQueryString(), 'events' => (app('currentTenant')->modules['notifications'] ?? true) ? $this->rows('investor_events')->where('investor_id', $investor->id)->latest('id')->limit(50)->get() : collect()]);
    }

    public function preferences(Request $request): RedirectResponse
    {
        $investor = $this->portalInvestor();
        $data = $request->validate(['email_notifications' => 'required|boolean', 'sms_notifications' => 'required|boolean']);
        if ($data['sms_notifications'] && (! $investor->phone_verified_at || ! $investor->sms_consented_at)) {
            throw ValidationException::withMessages(['sms_notifications' => 'Contact management to confirm your phone number and record SMS consent first.']);
        }
        $this->rows('investors')->where('id', $investor->id)->update([...$data, 'updated_at' => now()]);
        $this->audit($investor->id, 'preferences.updated', 'Investor updated email and SMS preferences.');

        return back()->with('success', 'Notification preferences saved.');
    }

    public function read(string $tenant, int $id): RedirectResponse
    {
        $investor = $this->portalInvestor();
        $this->rows('investor_events')->where('investor_id', $investor->id)->where('id', $id)->firstOrFail();
        $this->rows('investor_events')->where('investor_id', $investor->id)->where('id', $id)->update(['read_at' => now()]);

        return back();
    }

    public function statement(string $tenant, int $id): View|Response
    {
        abort_unless(auth()->user()->can('investors.view') || auth()->user()->can('investor.portal'), 403);
        $row = $this->rows('investor_statements')->where('id', $id)->firstOrFail();
        if (! auth()->user()->can('investors.view')) {
            abort_unless((int) $row->investor_id === (int) $this->portalInvestor()->id, 404);
        }
        $data = ['statement' => $row, 'snapshot' => json_decode($row->snapshot, true), 'investor' => $this->investor($row->investor_id)];
        if (request()->boolean('download')) {
            $pdf = new Dompdf;
            $pdf->loadHtml(view('investors.statement_pdf', $data)->render());
            $pdf->setPaper('A4');
            $pdf->render();

            return response($pdf->output(), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="investor-statement-'.$row->id.'.pdf"', 'Cache-Control' => 'private, no-store']);
        }

        return view('investors.statement', $data);
    }

    public function notifications(): View
    {
        return view('investors.notifications', ['settings' => $this->rows('investor_notification_settings')->first(), 'deliveries' => DB::table('investor_deliveries as d')->join('investor_events as e', 'e.id', '=', 'd.event_id')->join('investors as i', 'i.id', '=', 'e.investor_id')->where('d.tenant_id', app('currentTenant')->id)->select('d.*', 'e.title', 'i.name')->latest('d.id')->paginate(40)]);
    }

    public function notificationSettings(Request $request): RedirectResponse
    {
        $data = $request->validate(['email_enabled' => 'required|boolean', 'sms_enabled' => 'required|boolean']);
        DB::table('investor_notification_settings')->updateOrInsert(['tenant_id' => app('currentTenant')->id], [...$data, 'created_at' => now(), 'updated_at' => now()]);

        return back()->with('success', 'Company email and SMS settings saved. Live transport configuration is separate.');
    }

    public function retry(string $tenant, int $id): RedirectResponse
    {
        $row = $this->rows('investor_deliveries')->where('id', $id)->firstOrFail();
        abort_unless(in_array($row->status, ['failed', 'blocked', 'unknown', 'simulated', 'rejected', 'undelivered'], true), 422);
        $this->rows('investor_deliveries')->where('id', $id)->update(['status' => 'pending', 'attempts' => 0, 'next_attempt_at' => null, 'last_error' => null, 'sms_provider' => null, 'provider_reference' => null, 'provider_status' => null, 'status_checked_at' => null, 'delivered_at' => null, 'updated_at' => now()]);
        $event = $this->rows('investor_events')->where('id', $row->event_id)->firstOrFail();
        $this->audit($event->investor_id, 'notification.retry', 'Delivery #'.$id.' manually requeued; prior status '.$row->status.'; prior provider reference '.($row->provider_reference ?? 'none').'.');

        return back()->with('success', 'Notification requeued. Eligibility will be checked again before sending.');
    }

    public function notice(string $tenant, int $id, Request $request, InvestorNotifications $notifications): RedirectResponse
    {
        abort_unless(app('currentTenant')->modules['notifications'] ?? true, 403);
        $investor = $this->investor($id);
        $data = $request->validate(['type' => 'required|in:payout_scheduled,payout_delayed,maintenance,agreement', 'details' => 'required|string|max:3000', 'event_token' => 'required|uuid']);
        $titles = ['payout_scheduled' => 'Payout scheduled', 'payout_delayed' => 'Payout update', 'maintenance' => 'Property maintenance update', 'agreement' => 'Agreement update'];
        DB::transaction(function () use ($investor, $data, $titles, $notifications): void {
            $notifications->record($investor, 'notice:'.$investor->id.':'.$data['event_token'], $data['type'], $titles[$data['type']], $data['details']);
            $this->audit($investor->id, 'notice.published', $titles[$data['type']].' published to the investor portal.');
        });

        return back()->with('success', 'Investor update published and notifications queued.');
    }
}
