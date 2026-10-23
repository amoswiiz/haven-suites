<?php

namespace App\Http\Controllers\Haven;

use App\Support\Migration\PageNotFoundException;

/**
 * Read-only receipts for individual invoice payment allocations.
 * A receipt represents the allocated portion of a recorded guest payment.
 * No new payment or invoice allocation is created here.
 */
class Receipts extends BaseController
{
    public function show(int $allocationId)
    {
        // Keep the authorization check in the controller as well as the route.
        if (! haven_auth()->user() || ! haven_auth()->user()->can('finance.view')) {
            return $this->response->setStatusCode(403)->setContent('Access denied.');
        }

        $db = db_connect();
        $allocation = $db->table('invoice_payment_allocations AS a')
            ->select('a.id, a.invoice_id, a.guest_payment_id, a.amount AS receipt_amount, a.allocated_at, '
                . 'i.invoice_number, i.reservation_id, i.customer_name, i.customer_email, '
                . 'i.customer_phone, i.currency, i.total_amount AS invoice_total, i.status AS invoice_status, '
                . 'p.payment_date, p.payment_method, p.reference AS payment_reference, '
                . 'p.amount AS original_payment_amount, p.reservation_id AS payment_reservation_id')
            ->join('invoices AS i', 'i.id = a.invoice_id', 'inner')
            ->join('guest_payments AS p', 'p.id = a.guest_payment_id', 'inner')
            ->where('a.id', $allocationId)
            ->get()->getRowArray();

        if (! $allocation) {
            throw PageNotFoundException::forPageNotFound('Receipt not found.');
        }

        // Never present a receipt for a cross-reservation allocation.
        if ((int) $allocation['reservation_id'] !== (int) $allocation['payment_reservation_id']
            || $allocation['invoice_status'] !== 'issued') {
            log_message('error', 'Invalid receipt allocation relationship: ' . $allocationId);
            return $this->response->setStatusCode(409)->setContent('This receipt requires administrator review.');
        }

        $settings = $db->table('invoice_settings')->where('id', 1)->get()->getRowArray() ?? [];
        $allAllocations = $db->table('invoice_payment_allocations')
            ->select('id, amount, allocated_at')
            ->where('invoice_id', $allocation['invoice_id'])
            ->orderBy('id', 'ASC')->get()->getResultArray();

        $allocatedToDate = 0.0;
        $totalAllocated = 0.0;
        foreach ($allAllocations as $item) {
            $amount = (float) $item['amount'];
            $totalAllocated += $amount;
            if ((int) $item['id'] <= $allocationId) {
                $allocatedToDate += $amount;
            }
        }

        // This number is stable because allocation IDs do not change. It is a
        // receipt reference, not a separately sequenced fiscal receipt number.
        $receiptNumber = 'RCT-' . str_pad((string) $allocationId, 6, '0', STR_PAD_LEFT);

        return haven_view('receipts/show', [
            'receipt' => $allocation,
            'receiptNumber' => $receiptNumber,
            'settings' => $settings,
            'balanceAfterAllocation' => max(0, round((float) $allocation['invoice_total'] - $allocatedToDate, 2)),
            'currentInvoiceBalance' => max(0, round((float) $allocation['invoice_total'] - $totalAllocated, 2)),
        ]);
    }
}
