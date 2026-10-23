<?php

namespace App\Http\Controllers\Haven;

use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpFoundation\Response as ResponseInterface;

class GuestPayments extends BaseController
{
    private function allowed(string $permission): bool
    {
        $user = haven_auth()->user();
        return $user !== null && $user->can($permission);
    }

    private function forbidden(): ResponseInterface
    {
        return haven_service('response')->setStatusCode(403)->setContent('Permission denied.');
    }

    private function cents($value): ?int
    {
        if (!is_scalar($value) || !preg_match('/^\d{1,10}(?:\.\d{1,2})?$/', trim((string) $value))) {
            return null;
        }
        $parts = explode('.', trim((string) $value), 2);
        return ((int) $parts[0] * 100) + (int) str_pad($parts[1] ?? '', 2, '0');
    }

    private function money(int $cents): string
    {
        return sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
    }

    private function reservationQuery($db)
    {
        return $db->table('reservations r')
            ->select('r.id, r.guest_name, r.unit_id, r.check_in, r.check_out, u.unit_number, p.name AS property_name, bf.guest_total')
            ->join('units u', 'u.id = r.unit_id', 'left')
            ->join('properties p', 'p.id = u.property_id', 'left')
            ->join('booking_financials bf', 'bf.reservation_id = r.id', 'left');
    }

    public function index()
    {
        if (!$this->allowed('finance.view')) {
            return $this->forbidden();
        }

        $db = db_connect();
        $search = trim((string) $this->request->getGet('q'));
        $query = $this->reservationQuery($db);
        if ($search !== '') {
            $query->groupStart()
                ->like('r.guest_name', $search)
                ->orLike('u.unit_number', $search)
                ->orLike('p.name', $search)
                ->groupEnd();
        }

        $reservations = $query->orderBy('r.id', 'DESC')->limit(200)->get()->getResultArray();
        $ids = array_map(static fn ($row) => (int) $row['id'], $reservations);
        $paidByReservation = [];
        if ($ids !== []) {
            $totals = $db->table('guest_payments')
                ->select('reservation_id, SUM(amount) AS paid')
                ->whereIn('reservation_id', $ids)
                ->groupBy('reservation_id')->get()->getResultArray();
            foreach ($totals as $row) {
                $paidByReservation[(int) $row['reservation_id']] = $row['paid'];
            }
        }

        foreach ($reservations as &$reservation) {
            $reservation['paid'] = $paidByReservation[(int) $reservation['id']] ?? '0.00';
            $total = $this->cents($reservation['guest_total']);
            $paid = $this->cents($reservation['paid']) ?? 0;
            $reservation['balance'] = $total === null ? null : $this->money(max(0, $total - $paid));
            $reservation['overpaid'] = $total !== null && $paid > $total;
        }
        unset($reservation);

        return haven_view('guest_payments/index', [
            'pageTitle' => 'Guest Payments',
            'reservations' => $reservations,
            'search' => $search,
            'canManage' => $this->allowed('finance.manage'),
        ]);
    }

    public function show(int $id)
    {
        if (!$this->allowed('finance.view')) {
            return $this->forbidden();
        }

        $db = db_connect();
        $reservation = $this->reservationQuery($db)->where('r.id', $id)->get()->getRowArray();
        if (!$reservation) {
            return haven_service('response')->setStatusCode(404)->setContent('Reservation not found.');
        }

        $payments = $db->table('guest_payments')->where('reservation_id', $id)
            ->orderBy('payment_date', 'ASC')->orderBy('id', 'ASC')->get()->getResultArray();
        $paid = 0;
        foreach ($payments as $payment) {
            $paid += $this->cents($payment['amount']) ?? 0;
        }
        $total = $this->cents($reservation['guest_total']);

        return haven_view('guest_payments/show', [
            'pageTitle' => 'Guest Payments',
            'reservation' => $reservation,
            'payments' => $payments,
            'paid' => $this->money($paid),
            'total' => $total === null ? null : $this->money($total),
            'balance' => $total === null ? null : $this->money(max(0, $total - $paid)),
            'overpaid' => $total !== null && $paid > $total,
            'canManage' => $this->allowed('finance.manage'),
        ]);
    }

    /** Set or revise the guest total without overwriting host financial details. */
    public function saveTotal(int $id): RedirectResponse|ResponseInterface
    {
        if (!$this->allowed('finance.manage')) {
            return $this->forbidden();
        }

        $amount = $this->cents($this->request->getPost('guest_total'));
        if ($amount === null || $amount <= 0) {
            return redirect()->to(site_url('payments/guests/' . $id))
                ->with('error', 'Enter a valid guest total greater than zero (up to 2 decimal places).');
        }

        $db = db_connect();
        $db->transBegin();
        try {
            // Same lock order as GuestPayments::store: reservation, then unit.
            $reservation = $db->query('SELECT id, unit_id FROM reservations WHERE id = ? FOR UPDATE', [$id])->getRowArray();
            if (!$reservation || empty($reservation['unit_id'])) {
                throw new \RuntimeException('Reservation or assigned unit not found.');
            }
            $unitId = (int) $reservation['unit_id'];
            if (!$db->query('SELECT id FROM units WHERE id = ? FOR UPDATE', [$unitId])->getRowArray()) {
                throw new \RuntimeException('Reservation unit not found.');
            }

            $payments = $db->table('guest_payments')
                ->select('payment_date, amount')->where('reservation_id', $id)->get()->getResultArray();
            $paid = 0;
            $guard = new \App\Services\AccountingPeriodGuard($db);
            foreach ($payments as $payment) {
                // Never revise a total when a payment belongs to a closed month.
                $guard->assertOpen($unitId, $payment['payment_date']);
                $value = $this->cents($payment['amount']);
                if ($value === null) {
                    throw new \RuntimeException('An existing payment has an invalid amount.');
                }
                $paid += $value;
            }
            if ($amount < $paid) {
                throw new \RuntimeException('Guest total cannot be lower than payments already collected.');
            }

            $now = date('Y-m-d H:i:s');
            $existing = $db->table('booking_financials')
                ->where('reservation_id', $id)->get()->getRowArray();
            if ($existing) {
                $saved = $db->table('booking_financials')->where('reservation_id', $id)->update([
                    'guest_total' => $this->money($amount),
                    'updated_at' => $now,
                ]);
            } else {
                $saved = $db->table('booking_financials')->insert([
                    'reservation_id' => $id,
                    'guest_total' => $this->money($amount),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
            if (!$saved || $db->transStatus() === false) {
                throw new \RuntimeException('Could not save reservation guest total.');
            }
            $db->transCommit();
            return redirect()->to(site_url('payments/guests/' . $id))
                ->with('success', 'Reservation guest total saved successfully.');
        } catch (\Throwable $e) {
            $db->transRollback();
            log_message('error', 'Guest total update failed: {message}', ['message' => $e->getMessage()]);
            return redirect()->to(site_url('payments/guests/' . $id))
                ->with('error', $e->getMessage());
        }
    }

    public function store(int $id): RedirectResponse|ResponseInterface
    {
        if (!$this->allowed('finance.manage')) {
            return $this->forbidden();
        }

        $date = trim((string) $this->request->getPost('payment_date'));
        $amount = $this->cents($this->request->getPost('amount'));
        $method = trim((string) $this->request->getPost('payment_method'));
        $reference = trim((string) $this->request->getPost('reference'));
        $notes = trim((string) $this->request->getPost('notes'));
        $dateObject = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        if (!$dateObject || $dateObject->format('Y-m-d') !== $date || $amount === null || $amount <= 0
            || !in_array($method, ['mpesa', 'bank', 'cash', 'card', 'ota', 'other'], true)
            || strlen($reference) > 100 || strlen($notes) > 10000) {
            return redirect()->back()->withInput()->with('error', 'Check the payment date, amount and payment method.');
        }

        $db = db_connect();
        $db->transBegin();
        try {
            // Serialize payment inserts for the same reservation.
            $reservation = $db->query('SELECT id FROM reservations WHERE id = ? FOR UPDATE', [$id])->getRowArray();
            if (!$reservation) {
                throw new \RuntimeException('Reservation not found.');
            }
            // The reservation row is locked, serializing changes with month closing.
            $unitRow = $db->table('reservations')->select('unit_id')->where('id', $id)->get()->getRowArray();
            if (empty($unitRow['unit_id'])) {
                throw new \RuntimeException('Reservation has no assigned unit.');
            }
            if (!$db->query('SELECT id FROM units WHERE id = ? FOR UPDATE', [(int)$unitRow['unit_id']])->getRowArray()) {
                throw new \RuntimeException('Reservation unit not found.');
            }
            (new \App\Services\AccountingPeriodGuard($db))->assertOpen((int)$unitRow['unit_id'], $date);

            $financials = $db->table('booking_financials')->where('reservation_id', $id)->get()->getRowArray();
            $total = $financials ? $this->cents($financials['guest_total']) : null;
            if ($total === null || $total <= 0) {
                throw new \RuntimeException('Set the reservation guest total in booking_financials before recording payments.');
            }

            $existing = $db->table('guest_payments')->select('amount')->where('reservation_id', $id)->get()->getResultArray();
            $paid = 0;
            foreach ($existing as $payment) {
                $value = $this->cents($payment['amount']);
                if ($value === null) {
                    throw new \RuntimeException('An existing payment has an invalid amount.');
                }
                $paid += $value;
            }
            if ($paid + $amount > $total) {
                throw new \RuntimeException('Payment exceeds the outstanding reservation balance.');
            }

            $now = date('Y-m-d H:i:s');
            $saved = $db->table('guest_payments')->insert([
                'reservation_id' => $id,
                'payment_date' => $date,
                'amount' => $this->money($amount),
                'payment_method' => $method,
                'reference' => $reference !== '' ? $reference : null,
                'notes' => $notes !== '' ? $notes : null,
                'recorded_by' => (int) haven_auth()->user()->id,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            if (!$saved || $db->transStatus() === false) {
                throw new \RuntimeException('Could not save guest payment.');
            }
            $db->transCommit();
            return redirect()->to(site_url('payments/guests/' . $id))
                ->with('success', 'Guest payment recorded successfully.');
        } catch (\Throwable $e) {
            $db->transRollback();
            log_message('error', 'Guest payment failed: {message}', ['message' => $e->getMessage()]);
            return redirect()->to(site_url('payments/guests/' . $id))->with('error', $e->getMessage());
        }
    }
}
