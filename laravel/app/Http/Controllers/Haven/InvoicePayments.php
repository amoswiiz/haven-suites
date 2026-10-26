<?php

namespace App\Http\Controllers\Haven;

use App\Services\AccountingPeriodGuard;
use App\Support\Migration\PageNotFoundException;

/**
 * Allocate existing guest payments to issued invoices without duplicating receipts.
 * Routes MUST require an authenticated session and finance.manage permission.
 */
class InvoicePayments extends BaseController
{
    public function index(int $invoiceId)
    {
        $db = db_connect();
        $invoice = $db->table('invoices')->where('id', $invoiceId)->get()->getRowArray();
        if (! $invoice) {
            throw PageNotFoundException::forPageNotFound('Invoice not found.');
        }
        if (empty($invoice['reservation_id'])) {
            return redirect()->to(site_url('invoices/'.$invoiceId))
                ->with('error', 'Only reservation invoices can use existing guest payments.');
        }

        $allocated = $db->table('invoice_payment_allocations')
            ->selectSum('amount', 'total')->where('invoice_id', $invoiceId)->get()->getRowArray();
        $invoiceBalance = max(0, round((float) $invoice['total_amount'] - (float) ($allocated['total'] ?? 0), 2));

        $payments = $db->table('guest_payments AS p')
            ->select('p.id, p.payment_date, p.amount, p.payment_method, p.reference, p.notes, COALESCE(a.used_amount, 0) AS allocated_amount', false)
            ->join('(SELECT guest_payment_id, SUM(amount) AS used_amount FROM invoice_payment_allocations GROUP BY guest_payment_id) a', 'a.guest_payment_id = p.id', 'left', false)
            ->where('p.reservation_id', $invoice['reservation_id'])
            ->orderBy('p.payment_date', 'DESC')->orderBy('p.id', 'DESC')->get()->getResultArray();

        foreach ($payments as &$payment) {
            $payment['available_amount'] = max(0, round((float) $payment['amount'] - (float) $payment['allocated_amount'], 2));
        }
        unset($payment);

        $history = $db->table('invoice_payment_allocations AS a')
            ->select('a.id, a.amount, a.allocated_at, p.payment_date, p.payment_method, p.reference')
            ->join('guest_payments AS p', 'p.id = a.guest_payment_id')
            ->where('a.invoice_id', $invoiceId)
            ->orderBy('a.id', 'DESC')->get()->getResultArray();

        return haven_view('invoices/payments', [
            'invoice' => $invoice,
            'payments' => $payments,
            'history' => $history,
            'invoiceBalance' => $invoiceBalance,
        ]);
    }

    /**
     * Record a new payment and allocate it to an issued invoice atomically.
     * Route: POST invoices/(:num)/payments/record (session + finance.manage).
     * Form fields: payment_date, amount, payment_method, reference, notes.
     */
    public function record(int $invoiceId)
    {
        $back = site_url('invoices/'.$invoiceId);
        $amountInput = trim((string) $this->request->getPost('amount'));
        $date = trim((string) $this->request->getPost('payment_date'));
        $method = trim((string) $this->request->getPost('payment_method'));
        $reference = trim((string) $this->request->getPost('reference'));
        $notes = trim((string) $this->request->getPost('notes'));

        if (! preg_match('/^\d{1,10}(?:\.\d{1,2})?$/', $amountInput)) {
            return redirect()->to($back)->withInput()->with('error', 'Enter a valid payment amount with at most two decimal places.');
        }
        $cents = (int) round((float) $amountInput * 100);
        if ($cents <= 0) {
            return redirect()->to($back)->withInput()->with('error', 'Payment amount must be greater than zero.');
        }
        $parsedDate = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (! $parsedDate || $parsedDate->format('Y-m-d') !== $date) {
            return redirect()->to($back)->withInput()->with('error', 'Enter a valid payment date.');
        }
        if ($method === '' || mb_strlen($method) > 30 || ! preg_match('/^[\p{L}\p{N} ._\/-]+$/u', $method)) {
            return redirect()->to($back)->withInput()->with('error', 'Enter a valid payment method (up to 30 characters).');
        }
        if (mb_strlen($reference) > 100 || mb_strlen($notes) > 5000) {
            return redirect()->to($back)->withInput()->with('error', 'Payment reference or notes are too long.');
        }

        $db = db_connect();
        $db->transBegin();
        try {
            // Lock the invoice so concurrent payment submissions cannot overpay it.
            $invoice = $db->query(
                'SELECT id, reservation_id, status, currency, total_amount FROM invoices WHERE id = ? FOR UPDATE',
                [$invoiceId]
            )->getRowArray();
            if (! $invoice || $invoice['status'] !== 'issued') {
                throw new \RuntimeException('Only issued invoices can receive payments.');
            }
            if (strtoupper((string) ($invoice['currency'] ?? 'KES')) !== 'KES') {
                throw new \RuntimeException('This payment register currently supports KES invoices only.');
            }
            if (! empty($invoice['reservation_id'])) {
                $booking = $db->query('SELECT id, unit_id, status FROM reservations WHERE id = ? FOR UPDATE', [$invoice['reservation_id']])->getRowArray();
                if (! $booking || $booking['status'] === 'cancelled') {
                    throw new \RuntimeException('This booking is cancelled or unavailable. Review its invoice before recording payment.');
                }
                if ($booking['unit_id']) {
                    $db->query('SELECT id FROM units WHERE id = ? FOR UPDATE', [$booking['unit_id']]);
                    (new AccountingPeriodGuard($db))->assertOpen((int) $booking['unit_id'], $date);
                }
                $recorded = $db->table('guest_payments')->selectSum('amount', 'total')->where('reservation_id', $booking['id'])->get()->getRowArray();
                $used = $db->table('invoice_payment_allocations a')->select('COALESCE(SUM(a.amount), 0) AS total', false)->join('guest_payments gp', 'gp.id = a.guest_payment_id')->where('gp.reservation_id', $booking['id'])->get()->getRowArray();
                if ((int) round((float) ($recorded['total'] ?? 0) * 100) > (int) round((float) ($used['total'] ?? 0) * 100)) {
                    throw new \RuntimeException('This booking has existing unallocated payments. Allocate those payments to its invoice before recording a new payment.');
                }
            }

            $allocated = $db->table('invoice_payment_allocations')
                ->selectSum('amount', 'total')->where('invoice_id', $invoiceId)->get()->getRowArray();
            $balanceCents = (int) round(((float) $invoice['total_amount'] - (float) ($allocated['total'] ?? 0)) * 100);
            if ($balanceCents <= 0 || $cents > $balanceCents) {
                throw new \RuntimeException('Payment exceeds the outstanding invoice balance.');
            }

            // A supplied transaction reference must not be recorded twice for the same method.
            // The reference is optional for cash payments without an external transaction ID.
            if ($reference !== '') {
                $duplicate = $db->table('guest_payments')
                    ->where('payment_method', $method)->where('reference', $reference)
                    ->countAllResults();
                if ($duplicate > 0) {
                    throw new \RuntimeException('This payment reference has already been recorded. Check existing payments before continuing.');
                }
            }

            $now = date('Y-m-d H:i:s');
            $ok = $db->table('guest_payments')->insert([
                'reservation_id' => empty($invoice['reservation_id']) ? null : (int) $invoice['reservation_id'],
                'payment_date' => $date,
                'amount' => number_format($cents / 100, 2, '.', ''),
                'payment_method' => $method,
                'reference' => $reference !== '' ? $reference : null,
                'notes' => $notes !== '' ? $notes : null,
                'recorded_by' => haven_auth()->id(),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            if (! $ok || $db->transStatus() === false) {
                throw new \RuntimeException('Could not save the payment.');
            }
            $paymentId = (int) $db->insertID();
            if ($paymentId <= 0) {
                throw new \RuntimeException('Could not identify the new payment.');
            }

            $ok = $db->table('invoice_payment_allocations')->insert([
                'invoice_id' => $invoiceId,
                'guest_payment_id' => $paymentId,
                'amount' => number_format($cents / 100, 2, '.', ''),
                'allocated_at' => $now,
                'allocated_by' => haven_auth()->id(),
            ]);
            if (! $ok || $db->transStatus() === false) {
                throw new \RuntimeException('Could not allocate the payment to the invoice.');
            }
            $allocationId = (int) $db->insertID();
            if ($allocationId <= 0) {
                throw new \RuntimeException('Could not identify the payment receipt.');
            }

            $db->transCommit();

            return redirect()->to(site_url('receipts/'.$allocationId))
                ->with('success', 'Payment recorded and allocated. Receipt is ready.');
        } catch (\Throwable $e) {
            $db->transRollback();
            log_message('error', 'Invoice payment recording failed for invoice '.$invoiceId.': '.$e->getMessage());

            return redirect()->to($back)->withInput()->with('error', $e->getMessage());
        }
    }

    public function allocate(int $invoiceId)
    {
        $paymentId = filter_var($this->request->getPost('guest_payment_id'), FILTER_VALIDATE_INT);
        $amountInput = trim((string) $this->request->getPost('amount'));
        if (! $paymentId || ! preg_match('/^\d{1,10}(?:\.\d{1,2})?$/', $amountInput)) {
            return redirect()->to(site_url('invoices/'.$invoiceId.'/payments'))
                ->with('error', 'Select a payment and enter a valid amount (maximum two decimal places).');
        }
        $cents = (int) round(((float) $amountInput) * 100);
        if ($cents <= 0) {
            return redirect()->to(site_url('invoices/'.$invoiceId.'/payments'))
                ->with('error', 'Allocation amount must be greater than zero.');
        }

        $db = db_connect();
        $db->transBegin();
        try {
            // Lock invoice and payment rows to serialize competing allocations.
            $invoice = $db->query('SELECT id, reservation_id, status, total_amount FROM invoices WHERE id = ? FOR UPDATE', [$invoiceId])->getRowArray();
            if (! $invoice || $invoice['status'] !== 'issued' || empty($invoice['reservation_id'])) {
                throw new \RuntimeException('An issued reservation invoice is required.');
            }
            $payment = $db->query('SELECT id, reservation_id, amount FROM guest_payments WHERE id = ? FOR UPDATE', [$paymentId])->getRowArray();
            if (! $payment || (int) $payment['reservation_id'] !== (int) $invoice['reservation_id']) {
                throw new \RuntimeException('Payment does not belong to this invoice reservation.');
            }

            $usedOnInvoice = $db->table('invoice_payment_allocations')
                ->selectSum('amount', 'total')->where('invoice_id', $invoiceId)->get()->getRowArray();
            $usedFromPayment = $db->table('invoice_payment_allocations')
                ->selectSum('amount', 'total')->where('guest_payment_id', $paymentId)->get()->getRowArray();

            $balanceCents = (int) round(((float) $invoice['total_amount'] - (float) ($usedOnInvoice['total'] ?? 0)) * 100);
            $availableCents = (int) round(((float) $payment['amount'] - (float) ($usedFromPayment['total'] ?? 0)) * 100);
            if ($cents > $balanceCents || $cents > $availableCents) {
                throw new \RuntimeException('Amount exceeds the invoice balance or unallocated payment amount.');
            }

            $ok = $db->table('invoice_payment_allocations')->insert([
                'invoice_id' => $invoiceId,
                'guest_payment_id' => $paymentId,
                'amount' => number_format($cents / 100, 2, '.', ''),
                'allocated_at' => date('Y-m-d H:i:s'),
                'allocated_by' => haven_auth()->id(),
            ]);
            if (! $ok || $db->transStatus() === false) {
                throw new \RuntimeException('Could not save the payment allocation.');
            }
            $db->transCommit();

            return redirect()->to(site_url('invoices/'.$invoiceId.'/payments'))
                ->with('success', 'Existing guest payment allocated to invoice successfully.');
        } catch (\Throwable $e) {
            $db->transRollback();
            log_message('error', 'Invoice allocation error: '.$e->getMessage());

            return redirect()->to(site_url('invoices/'.$invoiceId.'/payments'))
                ->with('error', $e->getMessage());
        }
    }
}
