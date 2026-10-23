<?php







namespace App\Controllers;







use CodeIgniter\HTTP\RedirectResponse;



use CodeIgniter\HTTP\ResponseInterface;







class PartnerOwnerPayments extends BaseController



{



    private function authorized(string $permission): bool



    {



        $user = auth()->user();



        return $user !== null && $user->can($permission);



    }







    private function cents($value): int



    {



        $value = trim((string) ($value ?? ''));



        if (!preg_match('/^\d{1,10}(?:\.\d{1,2})?$/', $value)) {



            throw new \InvalidArgumentException('Enter a valid amount with up to two decimal places.');



        }



        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');



        return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');



    }







    private function money(int $cents): string



    {



        return sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);



    }







    private function bookings(): array



    {



        $db = db_connect();



        $rows = $db->table('reservations r')



            ->select('r.id, r.partner_apartment_id, r.guest_name, r.check_in, r.check_out, r.status, '



                . 'r.owner_net_amount, pa.apartment_name, pa.building_name, pa.owner_name, '



                . 'COALESCE(SUM(pop.amount), 0) AS paid', false)



            ->join('partner_apartments pa', 'pa.id = r.partner_apartment_id', 'inner')



            ->join('partner_owner_payments pop', 'pop.reservation_id = r.id', 'left')



            ->where('r.booking_type', 'partner')



            ->groupBy('r.id, r.partner_apartment_id, r.guest_name, r.check_in, r.check_out, '



                . 'r.status, r.owner_net_amount, pa.apartment_name, pa.building_name, pa.owner_name')



            ->orderBy('r.check_out', 'DESC')



            ->get()->getResultArray();







        foreach ($rows as &$row) {



            $owed = $this->cents($row['owner_net_amount'] ?? '0');



            $paid = $this->cents($row['paid']);



            $row['owed'] = $this->money($owed);



            $row['paid'] = $this->money($paid);



            $row['balance'] = $this->money(max(0, $owed - $paid));



        }



        unset($row);



        return $rows;



    }







    public function index(): string|ResponseInterface



    {



        if (!$this->authorized('finance.view')) {



            return service('response')->setStatusCode(403)->setBody('Permission denied.');



        }



        $bookings = $this->bookings();



        $totals = ['owed' => 0, 'paid' => 0, 'balance' => 0];



        foreach ($bookings as $booking) {



            // Cancelled bookings are excluded from active payout obligations.



            if ($booking['status'] === 'cancelled') {



                continue;



            }



            $totals['owed'] += $this->cents($booking['owed']);



            $totals['paid'] += $this->cents($booking['paid']);



            $totals['balance'] += $this->cents($booking['balance']);



        }



        foreach ($totals as $key => $value) {



            $totals[$key] = $this->money($value);



        }



        return view('payments/partners/index', [



            'pageTitle' => 'Partner Owner Payments',



            'bookings' => $bookings,



            'totals' => $totals,



        ]);



    }











    public function ownerBalances(): string|ResponseInterface



    {



        if (!$this->authorized('finance.view')) {



            return service('response')->setStatusCode(403)->setBody('Permission denied.');



        }







        $owners = [];



        $overall = ['owed' => 0, 'paid' => 0, 'balance' => 0];



        foreach ($this->bookings() as $booking) {



            // A cancelled booking is not an active owner liability.



            if ($booking['status'] === 'cancelled') {



                continue;



            }



            $key = (int) $booking['partner_apartment_id'];



            if (!isset($owners[$key])) {



                $owners[$key] = [



                    'partner_apartment_id' => $key,



                    'apartment_name' => $booking['apartment_name'],



                    'building_name' => $booking['building_name'],



                    'owner_name' => $booking['owner_name'],



                    'bookings' => 0,



                    'owed_cents' => 0,



                    'paid_cents' => 0,



                    'balance_cents' => 0,



                ];



            }



            $owners[$key]['bookings']++;



            $owners[$key]['owed_cents'] += $this->cents($booking['owed']);



            $owners[$key]['paid_cents'] += $this->cents($booking['paid']);



            $owners[$key]['balance_cents'] += $this->cents($booking['balance']);



        }







        foreach ($owners as &$owner) {



            foreach (['owed', 'paid', 'balance'] as $field) {



                $cents = $owner[$field . '_cents'];



                $overall[$field] += $cents;



                $owner[$field] = $this->money($cents);



                unset($owner[$field . '_cents']);



            }



        }



        unset($owner);







        foreach ($overall as $field => $cents) {



            $overall[$field] = $this->money($cents);



        }







        $owners = array_values($owners);



        usort($owners, static function (array $a, array $b): int {



            return strcmp(mb_strtolower($a['owner_name']), mb_strtolower($b['owner_name']))



                ?: strcmp(mb_strtolower($a['apartment_name']), mb_strtolower($b['apartment_name']));



        });







        return view('payments/partners/owner_balances', [



            'pageTitle' => 'Partner Owner Balances',



            'owners' => $owners,



            'totals' => $overall,



        ]);



    }











    public function ownerStatement(int $id): string|ResponseInterface



    {



        if (!$this->authorized('finance.view')) {



            return service('response')->setStatusCode(403)->setBody('Permission denied.');



        }







        $apartment = db_connect()->table('partner_apartments')



            ->where('id', $id)->get()->getRowArray();



        if (!$apartment) {



            return service('response')->setStatusCode(404)->setBody('Partner apartment not found.');



        }







        $bookings = array_values(array_filter(



            $this->bookings(),



            static fn (array $row): bool => (int) $row['partner_apartment_id'] === $id



        ));







        $payments = db_connect()->table('partner_owner_payments pop')



            ->select('pop.id, pop.reservation_id, pop.amount, pop.payment_date, '



                . 'pop.payment_method, pop.payment_reference, pop.notes')



            ->join('reservations r', 'r.id = pop.reservation_id', 'inner')



            ->where('r.booking_type', 'partner')



            ->where('r.partner_apartment_id', $id)



            ->where('pop.partner_apartment_id', $id)



            ->orderBy('pop.payment_date', 'DESC')



            ->orderBy('pop.id', 'DESC')



            ->get()->getResultArray();







        $totals = ['owed' => 0, 'paid' => 0, 'balance' => 0];



        $cancelledPaid = 0;



        foreach ($bookings as $booking) {



            if ($booking['status'] === 'cancelled') {



                $cancelledPaid += $this->cents($booking['paid']);



                continue;



            }



            foreach (array_keys($totals) as $key) {



                $totals[$key] += $this->cents($booking[$key]);



            }



        }



        foreach ($totals as $key => $cents) {



            $totals[$key] = $this->money($cents);



        }







        return view('payments/partners/owner_statement', [



            'pageTitle' => 'Partner Owner Statement',



            'apartment' => $apartment,



            'bookings' => $bookings,



            'payments' => $payments,



            'totals' => $totals,



            'cancelledPaid' => $this->money($cancelledPaid),



        ]);



    }







    public function show(int $id): string|ResponseInterface



    {



        if (!$this->authorized('finance.view')) {



            return service('response')->setStatusCode(403)->setBody('Permission denied.');



        }



        $booking = null;



        foreach ($this->bookings() as $item) {



            if ((int) $item['id'] === $id) {



                $booking = $item;



                break;



            }



        }



        if ($booking === null) {



            return service('response')->setStatusCode(404)->setBody('Partner booking not found.');



        }



        $payments = db_connect()->table('partner_owner_payments')



            ->where('reservation_id', $id)



            ->orderBy('payment_date', 'DESC')



            ->orderBy('id', 'DESC')



            ->get()->getResultArray();







        $editingPayment = null;

        $editId = (int) $this->request->getGet('edit_payment');

        if ($editId > 0 && $this->authorized('finance.manage')) {

            $editingPayment = db_connect()->table('partner_owner_payments')

                ->where('id', $editId)->where('reservation_id', $id)

                ->get()->getRowArray();

        }



        $auditHistory = db_connect()->table('partner_owner_payment_audits')
            ->where('reservation_id', $id)
            ->orderBy('changed_at', 'DESC')
            ->orderBy('id', 'DESC')
            ->get()->getResultArray();

        return view('payments/partners/show', [



            'pageTitle' => 'Partner Owner Payment Details',



            'booking' => $booking,



            'payments' => $payments,



            'canManage' => $this->authorized('finance.manage'),

            'editingPayment' => $editingPayment,
            'auditHistory' => $auditHistory,



        ]);



    }







    public function store(int $id): RedirectResponse|ResponseInterface



    {



        if (!$this->authorized('finance.manage')) {



            return service('response')->setStatusCode(403)->setBody('Permission denied.');



        }



        $back = site_url('payments/partners/' . $id);



        $amountInput = trim((string) $this->request->getPost('amount'));



        $date = trim((string) $this->request->getPost('payment_date'));



        $method = trim((string) $this->request->getPost('payment_method'));



        $reference = trim((string) $this->request->getPost('payment_reference'));



        $notes = trim((string) $this->request->getPost('notes'));







        try {



            $amount = $this->cents($amountInput);



            if ($amount <= 0) {



                throw new \InvalidArgumentException('Payment amount must be greater than zero.');



            }



            $parsedDate = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);



            if (!$parsedDate || $parsedDate->format('Y-m-d') !== $date) {



                throw new \InvalidArgumentException('Enter a valid payment date.');



            }



            if (!in_array($method, ['mpesa', 'bank_transfer', 'cash'], true)) {



                throw new \InvalidArgumentException('Select a valid payment method.');



            }



            if (($method !== 'cash' && $reference === '') || mb_strlen($reference) > 120) {



                throw new \InvalidArgumentException('A payment reference is required for M-Pesa and bank transfers (maximum 120 characters).');



            }



            if (mb_strlen($notes) > 5000) {



                throw new \InvalidArgumentException('Notes are too long.');



            }



        } catch (\InvalidArgumentException $e) {



            return redirect()->to($back)->withInput()->with('error', $e->getMessage());



        }







        $db = db_connect();



        $db->transBegin();



        try {



            // Lock the reservation to serialize payments for the same booking.



            $reservation = $db->query(



                'SELECT id, booking_type, partner_apartment_id, owner_net_amount, status '



                . 'FROM reservations WHERE id = ? FOR UPDATE', [$id]



            )->getRowArray();



            if (!$reservation || $reservation['booking_type'] !== 'partner'



                || empty($reservation['partner_apartment_id'])) {



                throw new \RuntimeException('Valid partner booking not found.');



            }



            if (!in_array($reservation['status'], ['confirmed', 'checked_in', 'checked_out'], true)) {



                throw new \RuntimeException('Payments are allowed only for confirmed, checked-in or checked-out partner bookings.');



            }



            if ($reservation['owner_net_amount'] === null) {



                throw new \RuntimeException('Owner payout amount is missing.');



            }



            $owed = $this->cents($reservation['owner_net_amount']);



            $paidRow = $db->table('partner_owner_payments')



                ->select('COALESCE(SUM(amount), 0) AS paid', false)



                ->where('reservation_id', $id)->get()->getRowArray();



            $paid = $this->cents($paidRow['paid'] ?? '0');



            if ($amount > $owed - $paid) {



                throw new \RuntimeException('Payment exceeds the outstanding owner balance.');



            }



            if ($reference !== '') {



                $duplicate = $db->table('partner_owner_payments')



                    ->where('payment_method', $method)



                    ->where('payment_reference', $reference)



                    ->countAllResults();



                if ($duplicate > 0) {



                    throw new \RuntimeException('This payment reference has already been recorded.');



                }



            }



            if (!$db->table('partner_owner_payments')->insert([



                'partner_apartment_id' => (int) $reservation['partner_apartment_id'],



                'reservation_id' => $id,



                'amount' => $this->money($amount),



                'payment_date' => $date,



                'payment_method' => $method,



                'payment_reference' => $reference === '' ? null : $reference,



                'notes' => $notes === '' ? null : $notes,



                'recorded_by' => (int) auth()->user()->id,



                'created_at' => date('Y-m-d H:i:s'),



                'updated_at' => date('Y-m-d H:i:s'),



            ]) || $db->transStatus() === false) {



                throw new \RuntimeException('Could not save the payment.');



            }



            $db->transCommit();



        } catch (\Throwable $e) {



            $db->transRollback();



            log_message('error', 'Partner owner payment failed: {error}', ['error' => $e->getMessage()]);



            $message = $e instanceof \InvalidArgumentException || $e instanceof \RuntimeException



                ? $e->getMessage() : 'Unable to save payment.';



            return redirect()->to($back)->withInput()->with('error', $message);



        }



        return redirect()->to($back)->with('success', 'Partner owner payment recorded successfully.');



    }





    /**

     * Save corrections to payment metadata. The amount is deliberately immutable.

     * POST payments/partners/payments/{paymentId}/update

     */

    public function update(int $paymentId): RedirectResponse|ResponseInterface

    {

        if (!$this->authorized('finance.manage')) {

            return service('response')->setStatusCode(403)->setBody('Permission denied.');

        }



        $db = db_connect();

        $payment = $db->table('partner_owner_payments')

            ->select('id, reservation_id')->where('id', $paymentId)->get()->getRowArray();

        if (!$payment) {

            return service('response')->setStatusCode(404)->setBody('Payment not found.');

        }

        $back = site_url('payments/partners/' . (int) $payment['reservation_id'])

            . '?edit_payment=' . $paymentId;

        $date = trim((string) $this->request->getPost('payment_date'));

        $method = trim((string) $this->request->getPost('payment_method'));

        $reference = trim((string) $this->request->getPost('payment_reference'));

        $notes = trim((string) $this->request->getPost('notes'));
        $reason = trim((string) $this->request->getPost('change_reason'));



        $parsedDate = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        if (!$parsedDate || $parsedDate->format('Y-m-d') !== $date) {

            return redirect()->to($back)->withInput()->with('error', 'Enter a valid payment date.');

        }

        if (!in_array($method, ['mpesa', 'bank_transfer', 'cash'], true)) {

            return redirect()->to($back)->withInput()->with('error', 'Select a valid payment method.');

        }

        if (($method !== 'cash' && $reference === '') || mb_strlen($reference) > 120) {

            return redirect()->to($back)->withInput()->with('error', 'A reference is required for M-Pesa and bank transfers (maximum 120 characters).');

        }

        if (mb_strlen($notes) > 5000) {

            return redirect()->to($back)->withInput()->with('error', 'Notes are too long.');

        }



        if ($reason === '' || mb_strlen($reason) > 2000) {
            return redirect()->to($back)->withInput()->with('error', 'Enter a reason for this correction (maximum 2000 characters).');
        }

        $db->transBegin();

        try {

            $current = $db->query(

                'SELECT id, reservation_id, amount, payment_date, payment_method, payment_reference, notes '

                . 'FROM partner_owner_payments WHERE id = ? FOR UPDATE', [$paymentId]

            )->getRowArray();

            if (!$current) {

                throw new \RuntimeException('Payment no longer exists.');

            }

            $previous = [
                'amount' => $current['amount'],
                'payment_date' => $current['payment_date'],
                'payment_method' => $current['payment_method'],
                'payment_reference' => $current['payment_reference'],
                'notes' => $current['notes'],
            ];

            if ($reference !== '') {

                $duplicate = $db->table('partner_owner_payments')

                    ->where('payment_method', $method)

                    ->where('payment_reference', $reference)

                    ->where('id !=', $paymentId)

                    ->countAllResults();

                if ($duplicate > 0) {

                    throw new \RuntimeException('This payment reference is already used by another payment.');

                }

            }

            // Never accept an edited amount from the form.

            $changes = [

                'payment_date' => $date,

                'payment_method' => $method,

                'payment_reference' => $reference === '' ? null : $reference,

                'notes' => $notes === '' ? null : $notes,

                'updated_at' => date('Y-m-d H:i:s'),

            ];

            if (!$db->table('partner_owner_payments')->where('id', $paymentId)->update($changes)

                || $db->transStatus() === false) {

                throw new \RuntimeException('Could not update the payment.');

            }

            $newValues = [
                'amount' => $current['amount'],
                'payment_date' => $date,
                'payment_method' => $method,
                'payment_reference' => $reference === '' ? null : $reference,
                'notes' => $notes === '' ? null : $notes,
            ];

            if (!$db->table('partner_owner_payment_audits')->insert([
                'payment_id' => $paymentId,
                'reservation_id' => (int) $current['reservation_id'],
                'action' => 'updated',
                'old_values' => json_encode($previous, JSON_THROW_ON_ERROR),
                'new_values' => json_encode($newValues, JSON_THROW_ON_ERROR),
                'change_reason' => $reason,
                'changed_by' => (int) auth()->user()->id,
                'changed_at' => date('Y-m-d H:i:s'),
            ]) || $db->transStatus() === false) {
                throw new \RuntimeException('Could not save the payment audit record. No changes were applied.');
            }

            // Log metadata corrections without exposing sensitive data in the UI.

            log_message('info', 'Partner owner payment {id} edited by user {user}', [

                'id' => $paymentId, 'user' => (int) auth()->user()->id,

            ]);

            $db->transCommit();

        } catch (\Throwable $e) {

            $db->transRollback();

            log_message('error', 'Partner owner payment update failed: {error}', ['error' => $e->getMessage()]);

            $message = $e instanceof \RuntimeException ? $e->getMessage() : 'Unable to update payment.';

            return redirect()->to($back)->withInput()->with('error', $message);

        }

        return redirect()->to(site_url('payments/partners/' . (int) $current['reservation_id']))

            ->with('success', 'Payment details updated successfully. The payment amount was not changed.');

    }



}
