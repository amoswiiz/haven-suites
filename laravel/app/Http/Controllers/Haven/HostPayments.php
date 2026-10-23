<?php

namespace App\Http\Controllers\Haven;

use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpFoundation\Response as ResponseInterface;

class HostPayments extends BaseController
{
    private function allowed(string $permission): bool
    {
        $user = haven_auth()->user();
        return $user !== null && $user->can($permission);
    }

    private function deny(): ResponseInterface
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

    private function reservation(int $id): ?array
    {
        $row = db_connect()->table('reservations r')
            ->select('r.id, r.unit_id, u.unit_number, p.name AS property_name')
            ->join('units u', 'u.id = r.unit_id', 'left')
            ->join('properties p', 'p.id = u.property_id', 'left')
            ->where('r.id', $id)->get()->getRowArray();
        return $row ?: null;
    }

    /**
     * Each collection is matched to the agreement effective on its receipt date.
     * A missing or ambiguous agreement blocks new host payments for that reservation.
     */
    private function commission(int $reservationId, int $unitId): array
    {
        $db = db_connect();
        $collections = $db->table('guest_payments')
            ->select('id, amount, payment_date')
            ->where('reservation_id', $reservationId)->get()->getResultArray();
        $earned = 0;
        $unmatched = [];
        $details = [];
        foreach ($collections as $collection) {
            $date = $collection['payment_date'];
            $agreements = $db->table('unit_host_agreements')
                ->select('id, host_name, commission_rate')
                ->where('unit_id', $unitId)
                ->where('effective_from <=', $date)
                ->groupStart()->where('effective_to', null)->orWhere('effective_to >=', $date)->groupEnd()
                ->get()->getResultArray();
            $amount = $this->cents($collection['amount']);
            if ($amount === null || count($agreements) !== 1) {
                $unmatched[] = $collection['id'];
                continue;
            }
            $agreement = $agreements[0];
            // Calculate each payment's commission in integer cents, rounding half up.
            $rateBasisPoints = (int) round(((float) $agreement['commission_rate']) * 100);
            $portion = intdiv($amount * $rateBasisPoints + 5000, 10000);
            $earned += $portion;
            $details[] = [
                'payment_date' => $date,
                'collection' => $this->money($amount),
                'host_name' => $agreement['host_name'],
                'rate' => $agreement['commission_rate'],
                'commission' => $this->money($portion),
            ];
        }
        $hosts = array_unique(array_map(static fn ($item) => $item['host_name'], $details));
        return ['earned_cents' => $earned, 'unmatched' => $unmatched, 'details' => $details, 'multiple_hosts' => count($hosts) > 1];
    }

    private function paid(int $reservationId): array
    {
        $payments = db_connect()->table('host_payments')
            ->where('reservation_id', $reservationId)
            ->orderBy('payment_date', 'ASC')->orderBy('id', 'ASC')
            ->get()->getResultArray();
        $total = 0;
        foreach ($payments as $payment) {
            $total += $this->cents($payment['amount']) ?? 0;
        }
        return ['payments' => $payments, 'cents' => $total];
    }

    public function index()
    {
        if (!$this->allowed('finance.view')) return $this->deny();
        $db = db_connect();
        $rows = $db->table('reservations r')
            ->select('r.id, r.unit_id, u.unit_number, p.name AS property_name')
            ->join('units u', 'u.id = r.unit_id', 'left')
            ->join('properties p', 'p.id = u.property_id', 'left')
            ->orderBy('r.id', 'DESC')->limit(200)->get()->getResultArray();
        foreach ($rows as &$row) {
            $commission = $this->commission((int) $row['id'], (int) $row['unit_id']);
            $paid = $this->paid((int) $row['id']);
            $row['earned'] = $this->money($commission['earned_cents']);
            $row['paid'] = $this->money($paid['cents']);
            $row['balance'] = $this->money(max(0, $commission['earned_cents'] - $paid['cents']));
            $row['needs_review'] = count($commission['unmatched']) > 0 || $commission['multiple_hosts'] || $paid['cents'] > $commission['earned_cents'];
        }
        unset($row);
        return haven_view('host_payments/index', ['pageTitle' => 'Host Payments', 'rows' => $rows]);
    }

    public function show(int $id)
    {
        if (!$this->allowed('finance.view')) return $this->deny();
        $reservation = $this->reservation($id);
        if (!$reservation) return haven_service('response')->setStatusCode(404)->setContent('Reservation not found.');
        $commission = $this->commission($id, (int) $reservation['unit_id']);
        $paid = $this->paid($id);
        return haven_view('host_payments/show', [
            'pageTitle' => 'Host Payments - Reservation #' . $id,
            'reservation' => $reservation,
            'collections' => $commission['details'],
            'payments' => $paid['payments'],
            'earned' => $this->money($commission['earned_cents']),
            'paid' => $this->money($paid['cents']),
            'balance' => $this->money(max(0, $commission['earned_cents'] - $paid['cents'])),
            'needsReview' => count($commission['unmatched']) > 0 || $commission['multiple_hosts'] || $paid['cents'] > $commission['earned_cents'],
            'canManage' => $this->allowed('finance.manage'),
        ]);
    }

    public function store(int $id): RedirectResponse|ResponseInterface
    {
        if (!$this->allowed('finance.manage')) return $this->deny();
        $date = trim((string) $this->request->getPost('payment_date'));
        $method = trim((string) $this->request->getPost('payment_method'));
        $reference = trim((string) $this->request->getPost('reference'));
        $notes = trim((string) $this->request->getPost('notes'));
        $amount = $this->cents($this->request->getPost('amount'));
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$parsed || $parsed->format('Y-m-d') !== $date || $date > date('Y-m-d')
            || !in_array($method, ['mpesa', 'bank', 'cash'], true)
            || $amount === null || $amount <= 0 || strlen($reference) > 100 || strlen($notes) > 10000) {
            return redirect()->back()->withInput()->with('error', 'Check the payment date, amount and method.');
        }
        $db = db_connect();
        $db->transBegin();
        try {
            // Serialize payments against the same reservation.
            $locked = $db->query('SELECT id, unit_id FROM reservations WHERE id = ? FOR UPDATE', [$id])->getRowArray();
            if (!$locked) throw new \RuntimeException('Reservation not found.');
            $commission = $this->commission($id, (int) $locked['unit_id']);
            if ($commission['multiple_hosts']) {
                throw new \RuntimeException('Collections for this reservation belong to multiple hosts. Split or allocate the commissions before recording payments.');
            }
            if ($commission['unmatched']) {
                throw new \RuntimeException('Some guest collections have no unique host agreement. Resolve the agreements first.');
            }
            $paid = $this->paid($id);
            if ($paid['cents'] > $commission['earned_cents']) {
                throw new \RuntimeException('Existing host payments exceed commission earned. Reconcile first.');
            }
            if ($amount > $commission['earned_cents'] - $paid['cents']) {
                throw new \RuntimeException('Payment exceeds outstanding commission for this reservation.');
            }
            $now = date('Y-m-d H:i:s');
            if (!$db->table('host_payments')->insert([
                'reservation_id' => $id,
                'payment_date' => $date,
                'amount' => $this->money($amount),
                'payment_method' => $method,
                'reference' => $reference !== '' ? $reference : null,
                'notes' => $notes !== '' ? $notes : null,
                'recorded_by' => (int) haven_auth()->user()->id,
                'created_at' => $now,
                'updated_at' => $now,
            ]) || $db->transStatus() === false) throw new \RuntimeException('Unable to save host payment.');
            $db->transCommit();
            return redirect()->to(site_url('payments/hosts/' . $id))->with('success', 'Host payment recorded.');
        } catch (\Throwable $e) {
            $db->transRollback();
            log_message('error', 'Host payment error: {message}', ['message' => $e->getMessage()]);
            return redirect()->to(site_url('payments/hosts/' . $id))->with('error', $e->getMessage());
        }
    }
}
