<?php

namespace App\Http\Controllers;

use App\Services\StaffBills;
use App\Support\CompanyModules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StaffBillController extends Controller
{
    private function authorizeAccess(): void
    {
        abort_unless(auth()->user()?->active && (auth()->user()->can('bills.submit') || $this->canReview()), 403);
        abort_unless(CompanyModules::enabled('housekeeping'), 403);
    }

    private function canReview(): bool
    {
        return auth()->user()->can('finance.manage') && ! auth()->user()->inGroup('cleaner');
    }

    private function staffId(): int
    {
        $legacy = haven_auth()->id();
        abort_unless($legacy, 403, 'Link your login to a staff record first.');
        $id = DB::connection('tenant')->table('staff')->where('user_id', $legacy)->where('status', 'active')->value('id');
        abort_unless($id, 403, 'An active staff record must be linked to your login.');

        return (int) $id;
    }

    private function units(): Collection
    {
        $staff = $this->staffId();

        return DB::connection('tenant')->table('units as u')->leftJoin('properties as p', 'p.id', '=', 'u.property_id')
            ->whereExists(function ($q) use ($staff): void {
                $q->selectRaw('1')->from('housekeeping as h')->whereColumn('h.unit_id', 'u.id')
                    ->where('h.assigned_staff_id', $staff)->where('h.status', '!=', 'cancelled')
                    ->where('h.scheduled_at', '>=', now()->subDays(90)->startOfDay())
                    ->where('h.scheduled_at', '<=', now()->addDays(7)->endOfDay());
            })->select('u.id', 'u.unit_number', 'p.name as property_name')->orderBy('u.unit_number')->get();
    }

    private function bill(int $id): object
    {
        $this->authorizeAccess();
        $bill = DB::connection('tenant')->table('staff_bills')->find($id);
        abort_unless($bill && ($this->canReview() || (int) $bill->submitted_by === (int) auth()->id()), 404);

        return $bill;
    }

    public function index(Request $request): View
    {
        $this->authorizeAccess();
        $data = $request->validate(['status' => ['nullable', Rule::in(['pending', 'returned', 'rejected', 'approved'])]]);
        $query = DB::connection('tenant')->table('staff_bills as b')
            ->leftJoin('units as u', 'u.id', '=', 'b.unit_id')->leftJoin('staff as s', 's.id', '=', 'b.staff_id')
            ->leftJoin('unit_expenses as e', 'e.id', '=', 'b.expense_id')
            ->select('b.*', 'u.unit_number', 's.full_name', 'e.amount_paid');
        if (! $this->canReview()) {
            $query->where('b.submitted_by', auth()->id());
        }
        if (! empty($data['status'])) {
            $query->where('b.status', $data['status']);
        }

        return view('staff_bills.index', ['bills' => $query->orderByDesc('b.id')->paginate(25)->withQueryString(), 'canReview' => $this->canReview()]);
    }

    public function create(): View
    {
        $this->authorizeAccess();
        abort_unless(auth()->user()->can('bills.submit'), 403);

        return view('staff_bills.form', ['bill' => null, 'units' => $this->units(), 'token' => (string) Str::uuid(), 'categories' => StaffBills::CATEGORIES]);
    }

    public function edit(string $tenant, int $id): View
    {
        $bill = $this->bill($id);
        abort_unless((int) $bill->submitted_by === (int) auth()->id() && $bill->status === 'returned', 403);

        return view('staff_bills.form', ['bill' => $bill, 'units' => $this->units(), 'token' => $bill->submission_token, 'categories' => StaffBills::CATEGORIES]);
    }

    public function store(Request $request): RedirectResponse
    {
        return $this->save($request, null);
    }

    public function update(string $tenant, int $id, Request $request): RedirectResponse
    {
        return $this->save($request, $id);
    }

    private function save(Request $request, ?int $id): RedirectResponse
    {
        $this->authorizeAccess();
        abort_unless(auth()->user()->can('bills.submit'), 403);
        $existing = $id ? $this->bill($id) : null;
        abort_if($existing && ((int) $existing->submitted_by !== (int) auth()->id() || $existing->status !== 'returned'), 403);
        $data = $request->validate([
            'submission_token' => 'required|uuid', 'unit_id' => 'required|integer|min:1',
            'purchase_date' => 'required|date_format:Y-m-d|before_or_equal:today',
            'category' => ['required', Rule::in(StaffBills::CATEGORIES)],
            'description' => 'required|string|max:255', 'supplier' => 'required|string|max:150',
            'amount' => ['required', 'regex:/^\d{1,7}(\.\d{1,2})?$/D', 'numeric', 'min:0.01'],
            'payer' => 'required|in:personal,company,supplier',
            'receipt' => ($existing ? 'nullable' : 'required').'|file|mimes:jpg,jpeg,png,pdf|max:5120',
            'version' => $existing ? 'required|integer|min:1' : 'nullable',
        ]);
        $staff = $this->staffId();
        abort_unless($this->units()->contains('id', (int) $data['unit_id']), 403, 'Choose an apartment assigned to you. Ask management to assign the refill task if it is missing.');
        $db = DB::connection('tenant');
        $path = null;
        try {
            $path = $request->file('receipt')?->store('staff-bills/'.app('currentTenant')->id, 'local');
            abort_if($request->hasFile('receipt') && ! $path, 500, 'Receipt could not be stored.');
            $saved = $db->transaction(function () use ($db, $id, $existing, $staff, $data, $path): int {
                $db->table('staff')->where('id', $staff)->lockForUpdate()->firstOrFail();
                if (! $id) {
                    $duplicate = $db->table('staff_bills')->where('submission_token', $data['submission_token'])->first();
                    if ($duplicate) {
                        abort_unless((int) $duplicate->submitted_by === (int) auth()->id(), 409);

                        return (int) $duplicate->id;
                    }
                } else {
                    $locked = $db->table('staff_bills')->where('id', $id)->lockForUpdate()->firstOrFail();
                    abort_unless($locked->status === 'returned' && (int) $locked->version === (int) $data['version'], 409, 'Bill changed; refresh and retry.');
                }
                $values = [
                    'unit_id' => $data['unit_id'], 'purchase_date' => $data['purchase_date'], 'category' => $data['category'],
                    'description' => $data['description'], 'supplier' => $data['supplier'], 'amount_minor' => (int) round($data['amount'] * 100),
                    'payer' => $data['payer'], 'receipt_path' => $path ?: $existing->receipt_path,
                    'status' => 'pending', 'updated_at' => now(),
                ];
                if ($id) {
                    $db->table('staff_bills')->where('id', $id)->update($values + ['version' => $existing->version + 1]);
                } else {
                    $id = $db->table('staff_bills')->insertGetId($values + ['submitted_by' => auth()->id(), 'staff_id' => $staff, 'submission_token' => $data['submission_token'], 'created_at' => now()]);
                }
                app(StaffBills::class)->event($db->table('staff_bills')->find($id), (int) auth()->id(), $existing ? 'resubmitted' : 'submitted');

                return (int) $id;
            });
            if ($path && $db->table('staff_bills')->where('id', $saved)->value('receipt_path') !== $path) {
                Storage::disk('local')->delete($path);
            }
        } catch (\Throwable $e) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }
            throw $e;
        }

        return redirect()->route('staff-bills.show', [app('currentTenant')->slug, $saved])->with('success', 'Bill submitted for review.');
    }

    public function show(string $tenant, int $id): View
    {
        $bill = $this->bill($id);
        $db = DB::connection('tenant');

        return view('staff_bills.show', [
            'bill' => $bill, 'canReview' => $this->canReview(), 'unit' => $db->table('units')->find($bill->unit_id),
            'staff' => $db->table('staff')->find($bill->staff_id),
            'events' => $db->table('staff_bill_events')->where('bill_id', $id)->orderBy('id')->get(),
            'expense' => $bill->expense_id ? $db->table('unit_expenses')->find($bill->expense_id) : null,
            'categories' => $this->canReview() ? $db->table('expense_categories')->where('is_active', 1)->get() : collect(),
        ]);
    }

    public function review(string $tenant, int $id, Request $request): RedirectResponse
    {
        $this->bill($id);
        abort_unless($this->canReview(), 403);
        $data = $request->validate([
            'version' => 'required|integer|min:1', 'action' => 'required|in:approved,returned,rejected',
            'note' => 'nullable|required_if:action,returned,rejected|string|max:2000',
            'category_id' => 'nullable|integer|min:1', 'expense_id' => 'nullable|integer|min:1',
        ]);
        app(StaffBills::class)->review($id, (int) auth()->id(), $data);

        return back()->with('success', 'Bill '.$data['action'].'.');
    }

    public function receipt(string $tenant, int $id, int $event): StreamedResponse
    {
        $this->bill($id);
        $row = DB::connection('tenant')->table('staff_bill_events')->where('bill_id', $id)->where('id', $event)->firstOrFail();
        $path = json_decode($row->snapshot, true, 512, JSON_THROW_ON_ERROR)['receipt_path'];
        abort_unless(str_starts_with($path, 'staff-bills/'.app('currentTenant')->id.'/') && ! str_contains($path,'..') && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->download($path,'bill-'.$id.'.'.pathinfo($path,PATHINFO_EXTENSION),['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
