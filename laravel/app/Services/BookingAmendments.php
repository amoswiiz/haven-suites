<?php

namespace App\Services;

use App\Models\User;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BookingAmendments
{
    public function preview(array $reservation): array
    {
        $state = $this->state((int) $reservation['id']);
        $reason = $this->unavailableReason($state);
        if ($reason === null && ! auth()->user()?->can('invoices.manage')) {
            $reason = 'Ask a colleague with invoice management access to amend this stay and its invoice.';
        }

        return [
            'snapshot' => $this->snapshot($state),
            'invoices' => array_map(fn (array $entry): array => $entry['invoice'] + [
                'allocated_amount' => array_sum(array_column($entry['allocations'], 'amount')),
            ], $state['invoices']),
            'can_amend' => $reason === null,
            'unavailable_reason' => $reason,
            'history' => $this->history((int) $reservation['id']),
        ];
    }

    public function history(int $reservationId): array
    {
        $db = DB::connection('tenant');
        if (! $db->getSchemaBuilder()->hasTable('booking_amendments')) {
            return [];
        }

        return $db->table('booking_amendments')->where('reservation_id', $reservationId)
            ->orderByDesc('id')->get()->map(function (object $row): array {
                $entry = (array) $row;
                foreach (['before_snapshot', 'after_snapshot'] as $field) {
                    $entry[$field] = json_decode($entry[$field], true, 512, JSON_THROW_ON_ERROR);
                }

                return $entry;
            })->all();
    }

    public function save(int $reservationId, array $data, string $snapshot, bool $amendInvoice, string $reason, User $actor): void
    {
        abort_unless($actor->active && $actor->can('reservations.manage'), 403);
        $db = DB::connection('tenant');
        $db->transaction(function () use ($db, $reservationId, $data, $snapshot, $amendInvoice, $reason, $actor): void {
            // Match the payment workflow: invoice, reservation, then apartment locks.
            $db->table('invoices')->where('reservation_id', $reservationId)->where('status', '!=', 'void')->orderBy('id')->lockForUpdate()->get();
            $booking = $db->table('reservations')->where('id', $reservationId)->lockForUpdate()->firstOrFail();
            $unitIds = array_unique(array_filter([$booking->unit_id, $data['unit_id'] ?? null]));
            sort($unitIds, SORT_NUMERIC);
            foreach ($unitIds as $unitId) {
                $unit = $db->table('units')->where('id', $unitId)->lockForUpdate()->first();
                if (! $unit || ($unit->status !== 'active' && (int) $unitId !== (int) $booking->unit_id)) {
                    $this->fail('Choose an active apartment belonging to this company.');
                }
            }
            $before = $this->state($reservationId, true);
            if ($snapshot !== '' && ! hash_equals($this->snapshot($before), $snapshot)) {
                $this->fail('This booking, invoice or its payments changed. Reload the edit page and review the latest amounts before saving.');
            }
            $changed = $this->stayChanged((array) $booking, $data);
            $total = $this->cents($data['total_amount']);
            $start = DateTimeImmutable::createFromFormat('!Y-m-d', $data['check_in']);
            $end = DateTimeImmutable::createFromFormat('!Y-m-d', $data['check_out']);
            if (! $start || ! $end || $start->format('Y-m-d') !== $data['check_in'] || $end->format('Y-m-d') !== $data['check_out'] || $end <= $start
                || ! is_finite((float) $data['total_amount']) || ! is_finite((float) $data['nightly_rate'])
                || (float) $data['nightly_rate'] < 0 || $total < 0 || $total > 999999999999
                || $total !== $this->cents((int) $start->diff($end)->days * (float) $data['nightly_rate'])) {
                $this->fail('Check the stay dates, nightly rate and booking total.');
            }
            $paid = $db->table('guest_payments')->where('reservation_id', $reservationId)->lockForUpdate()->get();
            $paidCents = $paid->sum(fn (object $payment): int => $this->cents($payment->amount));
            if ($total < max($this->cents($booking->amount_paid), $paidCents)) {
                $this->fail('The revised total cannot be lower than money already received. A refund or credit must be handled separately.');
            }
            $invoice = null;
            if ($changed && $before['invoices'] !== []) {
                if (! $actor->can('invoices.manage')) {
                    $this->fail('You need invoice management access to amend this stay and its invoice.');
                }
                if ($snapshot === '') {
                    $this->fail('Reload the booking edit page before amending its invoice.');
                }
                if ($unavailable = $this->unavailableReason($before)) {
                    $this->fail($unavailable);
                }
                $invoice = $before['invoices'][0];
                if (mb_strlen(trim($reason)) > 2000) {
                    $this->fail('Keep the amendment reason within 2,000 characters.');
                }
                if ($invoice['invoice']['status'] === 'issued' && (! $amendInvoice || mb_strlen(trim($reason)) < 3 || mb_strlen(trim($reason)) > 2000)) {
                    $this->fail('Select “Amend linked invoice” and enter a reason (3–2,000 characters) to save this stay change.');
                }
                $allocated = array_sum(array_map(fn (array $allocation): int => $this->cents($allocation['amount']), $invoice['allocations']));
                if ($total <= 0 || $total < $allocated) {
                    $this->fail('The revised invoice must be positive and cannot be lower than its allocated payments. Handle a refund or credit separately.');
                }
            }
            if ($changed) {
                $this->assertPeriodsOpen((array) $booking, $data, $before['invoices'], $paid->all());
            }
            if (in_array($data['status'], ['confirmed', 'checked_in'], true)) {
                $partner = ($booking->booking_type ?? 'managed') === 'partner';
                if (! $partner && app(ExternalCalendars::class)->overlaps(app('currentTenant')->id, (int) $data['unit_id'], $data['check_in'], $data['check_out'])) {
                    $this->fail('This apartment is blocked by an external calendar for those dates.');
                }
                $overlap = $db->table('reservations')->where('id', '!=', $reservationId)
                    ->where($partner ? 'partner_apartment_id' : 'unit_id', $data[$partner ? 'partner_apartment_id' : 'unit_id'])
                    ->whereIn('status', ['confirmed', 'checked_in'])->where('check_in', '<', $data['check_out'])->where('check_out', '>', $data['check_in'])->exists();
                if ($overlap) {
                    $this->fail('This apartment has another confirmed booking for those dates.');
                }
            }
            $db->table('reservations')->where('id', $reservationId)->update($data);
            if ($changed && $before['financials'] !== null && $this->cents($before['financials']['guest_total']) === $this->cents($booking->total_amount)) {
                $db->table('booking_financials')->where('reservation_id', $reservationId)->update([
                    'guest_total' => $this->money($total), 'updated_at' => now(),
                ]);
            }
            if ($invoice !== null) {
                $invoiceId = $invoice['invoice']['id'];
                $db->table('invoices')->where('id', $invoiceId)->update([
                    'subtotal' => $this->money($total), 'total_amount' => $this->money($total), 'updated_at' => now(),
                ]);
                $db->table('invoice_items')->where('id', $invoice['items'][0]['id'])->update([
                    'description' => 'Accommodation — booking #'.$reservationId.' ('.$data['check_in'].' to '.$data['check_out'].', '.$start->diff($end)->days.' nights)',
                    'unit_price' => $this->money($total), 'line_total' => $this->money($total), 'updated_at' => now(),
                ]);
                $db->table('booking_amendments')->insert([
                    'reservation_id' => $reservationId, 'invoice_id' => $invoiceId,
                    'actor_id' => $actor->id, 'actor_name' => $actor->name,
                    'reason' => trim($reason) ?: 'Draft invoice synchronized with booking edit.',
                    'before_snapshot' => json_encode($before, JSON_THROW_ON_ERROR),
                    'after_snapshot' => json_encode($this->state($reservationId), JSON_THROW_ON_ERROR), 'created_at' => now(),
                ]);
            }
        }, 3);
    }

    private function state(int $reservationId, bool $lock = false): array
    {
        $db = DB::connection('tenant');
        $invoices = $db->table('invoices')->where('reservation_id', $reservationId)->where('status', '!=', 'void')->orderBy('id')->get();
        $entries = [];
        foreach ($invoices as $invoice) {
            $items = $db->table('invoice_items')->where('invoice_id', $invoice->id)->orderBy('id');
            $allocations = $db->table('invoice_payment_allocations')->where('invoice_id', $invoice->id)->orderBy('id');
            if ($lock) {
                $items->lockForUpdate();
                $allocations->lockForUpdate();
            }
            $entries[] = ['invoice' => (array) $invoice, 'items' => $items->get()->map(fn (object $row): array => (array) $row)->all(),
                'allocations' => $allocations->get()->map(fn (object $row): array => (array) $row)->all()];
        }
        $financials = $db->table('booking_financials')->where('reservation_id', $reservationId);
        if ($lock) {
            $financials->lockForUpdate();
        }

        return ['reservation' => (array) $db->table('reservations')->find($reservationId), 'invoices' => $entries,
            'financials' => ($row = $financials->first()) ? (array) $row : null];
    }

    private function unavailableReason(array $state): ?string
    {
        if ($state['invoices'] === []) {
            return null;
        }
        if (! DB::connection('tenant')->getSchemaBuilder()->hasTable('booking_amendments')) {
            return 'Invoice amendments are not installed for this company yet. Ask an administrator to complete the update.';
        }
        if (count($state['invoices']) !== 1) {
            return 'This booking has several active invoices. Ask finance to reconcile them before changing the stay or charges.';
        }
        if (($state['reservation']['booking_type'] ?? 'managed') === 'partner') {
            return 'Partner booking amendments need an owner settlement review. Ask finance to review the guest invoice and owner charges together.';
        }
        $entry = $state['invoices'][0];
        $invoice = $entry['invoice'];
        $items = $entry['items'];
        $total = $this->cents($state['reservation']['total_amount']);
        if ($state['financials'] !== null && $this->cents($state['financials']['guest_total']) !== $total) {
            return 'The guest payment register total differs from this booking. Ask finance to reconcile the totals before amending the invoice.';
        }
        $stay = $state['reservation'];
        $nights = (new DateTimeImmutable($stay['check_in']))->diff(new DateTimeImmutable($stay['check_out']))->days;
        $description = 'booking #'.(int) $stay['id'].' ('.$stay['check_in'].' to '.$stay['check_out'].', '.$nights.' nights)';
        $generated = count($items) === 1 && in_array($items[0]['description'], ['Accommodation — '.$description, 'Accommodation - '.$description], true);
        if (! in_array($invoice['status'], ['draft', 'issued'], true) || $invoice['invoice_type'] !== 'reservation'
            || strtoupper($invoice['currency']) !== 'KES' || ! $generated
            || $this->cents($invoice['tax_amount']) !== 0 || $this->cents($invoice['discount_amount']) !== 0
            || $this->cents($invoice['total_amount']) !== $total || $this->cents($invoice['subtotal']) !== $total
            || (float) $items[0]['quantity'] !== 1.0 || $this->cents($items[0]['unit_price']) !== $total || $this->cents($items[0]['line_total']) !== $total) {
            return 'This invoice has custom charges or differs from the booking. Ask finance to reconcile it before changing the stay or charges.';
        }

        return null;
    }

    private function stayChanged(array $before, array $after): bool
    {
        foreach (['check_in', 'check_out', 'unit_id', 'partner_apartment_id', 'nightly_rate', 'total_amount', 'owner_net_amount'] as $field) {
            $old = $before[$field] ?? null;
            $new = $after[$field] ?? null;
            if (in_array($field, ['nightly_rate', 'total_amount', 'owner_net_amount'], true) ? $this->cents($old) !== $this->cents($new) : (string) $old !== (string) $new) {
                return true;
            }
        }

        return false;
    }

    private function assertPeriodsOpen(array $before, array $after, array $invoices, array $payments): void
    {
        $dates = [];
        foreach ([$before, $after] as $stay) {
            $start = new DateTimeImmutable($stay['check_in']);
            $lastNight = (new DateTimeImmutable($stay['check_out']))->modify('-1 day');
            for ($month = $start->modify('first day of this month'); $month <= $lastNight; $month = $month->modify('+1 month')) {
                $dates[] = $month->format('Y-m-d');
            }
        }
        foreach ($invoices as $entry) {
            if (! empty($entry['invoice']['issue_date'])) {
                $dates[] = $entry['invoice']['issue_date'];
            }
        }
        foreach ($payments as $payment) {
            $dates[] = $payment->payment_date;
        }
        $guard = new AccountingPeriodGuard;
        foreach (array_unique(array_filter([$before['unit_id'], $after['unit_id']])) as $unitId) {
            foreach (array_unique($dates) as $date) {
                try {
                    $guard->assertOpen((int) $unitId, $date);
                } catch (\RuntimeException $exception) {
                    $this->fail($exception->getMessage());
                }
            }
        }
    }

    private function snapshot(array $state): string
    {
        return hash('sha256', json_encode($state, JSON_THROW_ON_ERROR));
    }

    private function cents(mixed $amount): int
    {
        return (int) round((float) $amount * 100);
    }

    private function money(int $cents): string
    {
        return sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['amendment' => $message]);
    }
}
