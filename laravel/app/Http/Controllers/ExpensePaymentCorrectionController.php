<?php

namespace App\Http\Controllers;

use App\Services\ExpenseCorrections;
use App\Services\ExpensePaymentCorrections;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ExpensePaymentCorrectionController extends Controller
{
    private function authorizeCorrection(): void
    {
        abort_unless(auth()->user()?->active && auth()->user()->can('finance.manage'), 403);
    }

    public function edit(string $tenant, int $id, int $paymentId): View|RedirectResponse
    {
        return $this->form($id, $paymentId);
    }

    public function editHistorical(string $tenant, int $id): View|RedirectResponse
    {
        return $this->form($id, null);
    }

    private function form(int $id, ?int $paymentId): View|RedirectResponse
    {
        $this->authorizeCorrection();
        $db = DB::connection('tenant');
        $expense = $db->table('unit_expenses')->find($id);
        abort_unless($expense, 404);
        if (! app(ExpenseCorrections::class)->editable($expense)) {
            return redirect()->route('expenses.payments', [app('currentTenant')->slug, $id])
                ->with('error', 'This expense belongs to another workflow. Correct the payment through its original cleaning, rent or bill workflow.');
        }
        if (! $db->getSchemaBuilder()->hasTable('expense_payment_corrections')) {
            return redirect()->route('expenses.payments', [app('currentTenant')->slug, $id])
                ->with('error', 'Payment corrections are not installed for this company. Ask your administrator to complete the update.');
        }
        $payment = $paymentId !== null
            ? $db->table('expense_payments')->where('expense_id', $id)->where('id', $paymentId)->firstOrFail()
            : null;
        if ($paymentId === null && ($db->table('expense_payments')->where('expense_id', $id)->exists()
            || app(ExpenseCorrections::class)->cents($expense->amount_paid) <= 0)) {
            return redirect()->route('expenses.payments', [app('currentTenant')->slug, $id])
                ->with('error', 'There is no standalone historical paid balance to correct. Choose an individual payment instead.');
        }

        return view('unit_expenses.correct_payment', [
            'pageTitle' => $payment ? 'Correct expense payment' : 'Correct historical paid balance',
            'expense' => $expense,
            'payment' => $payment,
            'snapshot' => app(ExpensePaymentCorrections::class)->snapshot($expense, $payment),
        ]);
    }

    public function update(string $tenant, int $id, int $paymentId, Request $request): RedirectResponse
    {
        return $this->save($id, $paymentId, $request);
    }

    public function updateHistorical(string $tenant, int $id, Request $request): RedirectResponse
    {
        return $this->save($id, null, $request);
    }

    private function save(int $id, ?int $paymentId, Request $request): RedirectResponse
    {
        $this->authorizeCorrection();
        $data = $request->validate([
            'amount' => ['required', 'regex:/^\d{1,10}(?:\.\d{1,2})?$/D'],
            'payment_date' => 'required|date_format:Y-m-d',
            'payment_method' => [$paymentId === null ? 'nullable' : 'required', 'string', Rule::in(['mpesa', 'bank', 'cash', 'other'])],
            'reference' => 'nullable|string|max:100',
            'notes' => 'nullable|string|max:10000',
            'reason' => 'required|string|min:3|max:1000',
            'snapshot' => ['required', 'regex:/^[a-f0-9]{64}$/D'],
        ]);
        $db = DB::connection('tenant');
        abort_unless($db->table('unit_expenses')->where('id', $id)->exists(), 404);
        if (! $db->getSchemaBuilder()->hasTable('expense_payment_corrections')) {
            return back()->withInput()->with('error', 'Payment corrections are not installed for this company. Ask your administrator to complete the update.');
        }
        app(ExpensePaymentCorrections::class)->correct($id, $paymentId, $data);

        return redirect()->route('expenses.payments', [app('currentTenant')->slug, $id])
            ->with('success', 'Payment record corrected. If the bill total was also wrong, choose Edit expense to correct it next.');
    }
}
