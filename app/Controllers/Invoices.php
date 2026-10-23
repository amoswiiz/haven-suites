<?php

namespace App\Controllers;

class Invoices extends BaseController
{
    public function index()
    {
        $db = db_connect();
        $allocationSql = '(SELECT invoice_id, SUM(amount) AS allocated_amount FROM invoice_payment_allocations GROUP BY invoice_id) AS allocations';
        $rows = $db->table('invoices AS i')
            ->select('i.id, i.invoice_number, i.invoice_type, i.reservation_id, i.customer_name, i.issue_date, i.due_date, i.currency, i.status, i.total_amount, COALESCE(allocations.allocated_amount, 0) AS paid_amount', false)
            ->join($allocationSql, 'allocations.invoice_id = i.id', 'left', false)
            ->orderBy('i.id', 'DESC')->get()->getResultArray();
        $summary = ['issued' => 0, 'draft' => 0, 'outstanding' => 0.0, 'paid' => 0.0];
        foreach ($rows as &$row) {
            $row['paid_amount'] = (float) $row['paid_amount'];
            $row['total_amount'] = (float) $row['total_amount'];
            $row['balance'] = max(0, round($row['total_amount'] - $row['paid_amount'], 2));
            if ($row['status'] === 'draft') {
                $summary['draft']++;
            } elseif ($row['status'] === 'issued') {
                $summary['issued']++;
                $summary['outstanding'] += $row['balance'];
                $summary['paid'] += $row['paid_amount'];
            }
        }
        unset($row);
        return view('invoices/index', ['invoices' => $rows, 'summary' => $summary]);
    }

    public function create()
    {
        $db = db_connect();
        $reservations = $db->table('reservations')
            ->select('id, guest_name, guest_email, guest_phone, check_in, check_out, total_amount, status')
            ->where('status !=', 'cancelled')
            ->orderBy('id', 'DESC')->get()->getResultArray();
        return view('invoices/create', ['reservations' => $reservations]);
    }

    public function store()
    {
        $request = $this->request;
        $type = (string) $request->getPost('invoice_type');
        if (!in_array($type, ['reservation', 'standalone'], true)) {
            return redirect()->back()->withInput()->with('error', 'Select a valid invoice type.');
        }

        $customerName = trim((string) $request->getPost('customer_name'));
        $customerEmail = trim((string) $request->getPost('customer_email'));
        $customerPhone = trim((string) $request->getPost('customer_phone'));
        $reservationId = null;
        $items = [];
        $db = db_connect();

        if ($type === 'reservation') {
            $reservationId = filter_var($request->getPost('reservation_id'), FILTER_VALIDATE_INT);
            $reservation = $reservationId ? $db->table('reservations')->where('id', $reservationId)
                ->where('status !=', 'cancelled')->get()->getRowArray() : null;
            if (!$reservation) {
                return redirect()->back()->withInput()->with('error', 'Select an existing non-cancelled reservation.');
            }
            $existingInvoice = $db->table('invoices')->select('id')
                ->where('reservation_id', $reservationId)->where('status !=', 'void')
                ->orderBy('id', 'DESC')->get()->getRowArray();
            if ($existingInvoice) {
                return redirect()->to(site_url('invoices/' . $existingInvoice['id']))
                    ->with('success', 'This reservation already has an invoice.');
            }
            $customerName = trim((string) $reservation['guest_name']);
            $customerEmail = trim((string) ($reservation['guest_email'] ?? ''));
            $customerPhone = trim((string) ($reservation['guest_phone'] ?? ''));
            if (!empty($reservation['customer_id'])) {
                $submittedKyc = $db->table('customer_kyc')->select('id')
                    ->where('reservation_id', $reservationId)
                    ->where('customer_id', $reservation['customer_id'])
                    ->where('submitted_at IS NOT NULL', null, false)
                    ->orderBy('id', 'DESC')->get()->getRowArray();
                if ($submittedKyc) {
                    $kycCustomer = $db->table('customers')->where('id', $reservation['customer_id'])->get()->getRowArray();
                    if ($kycCustomer) {
                        $customerName = trim((string) $kycCustomer['full_name']);
                        $customerEmail = trim((string) ($kycCustomer['email'] ?? ''));
                        $customerPhone = trim((string) ($kycCustomer['primary_phone'] ?? ''));
                    }
                }
            }
            $checkIn = new \DateTimeImmutable($reservation['check_in']);
            $checkOut = new \DateTimeImmutable($reservation['check_out']);
            $nights = (int) $checkIn->diff($checkOut)->format('%r%a');
            $total = round((float) $reservation['total_amount'], 2);
            if ($nights < 1 || $total < 0) {
                return redirect()->back()->withInput()->with('error', 'Reservation dates or amount are invalid.');
            }
            // Use a single accommodation line to preserve the booking total exactly,
            // including any reservation-level adjustments already in that total.
            $items[] = ['description' => 'Accommodation — booking #' . $reservationId . ' (' . $reservation['check_in'] . ' to ' . $reservation['check_out'] . ', ' . $nights . ' nights)',
                'quantity' => 1, 'unit_price' => $total, 'line_total' => $total];
        } else {
            if ($customerName === '') {
                return redirect()->back()->withInput()->with('error', 'Customer name is required.');
            }
            $descriptions = $request->getPost('item_description');
            $quantities = $request->getPost('item_quantity');
            $prices = $request->getPost('item_price');
            if (!is_array($descriptions) || !is_array($quantities) || !is_array($prices)
                || count($descriptions) !== count($quantities) || count($descriptions) !== count($prices)
                || count($descriptions) < 1 || count($descriptions) > 30) {
                return redirect()->back()->withInput()->with('error', 'Provide between 1 and 30 valid invoice items.');
            }
            foreach ($descriptions as $index => $description) {
                $description = trim((string) $description);
                $quantity = $quantities[$index];
                $price = $prices[$index];
                if ($description === '' || strlen($description) > 1000 || !is_numeric($quantity) || !is_numeric($price)
                    || (float) $quantity <= 0 || (float) $price < 0 || (float) $quantity > 100000 || (float) $price > 100000000) {
                    return redirect()->back()->withInput()->with('error', 'Each item needs a description, positive quantity and nonnegative price.');
                }
                $quantity = round((float) $quantity, 2);
                $price = round((float) $price, 2);
                $items[] = ['description' => $description, 'quantity' => $quantity, 'unit_price' => $price,
                    'line_total' => round($quantity * $price, 2)];
            }
        }

        if ($customerEmail !== '' && !filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
            return redirect()->back()->withInput()->with('error', 'Enter a valid customer email or leave it blank.');
        }
        $issueDate = (string) $request->getPost('issue_date');
        $dueDate = (string) $request->getPost('due_date');
        foreach ([$issueDate, $dueDate] as $date) {
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            if (!$parsed || $parsed->format('Y-m-d') !== $date) {
                return redirect()->back()->withInput()->with('error', 'Enter valid invoice and due dates.');
            }
        }
        if ($dueDate < $issueDate) {
            return redirect()->back()->withInput()->with('error', 'Due date cannot precede invoice date.');
        }
        $subtotal = round(array_sum(array_column($items, 'line_total')), 2);
        if ($subtotal > 9999999999.99) {
            return redirect()->back()->withInput()->with('error', 'Invoice total exceeds the supported limit.');
        }
        $now = date('Y-m-d H:i:s');
        $db->transBegin();
        try {
            $saved = $db->table('invoices')->insert([
                'invoice_number' => null, 'invoice_type' => $type, 'reservation_id' => $reservationId,
                'customer_name' => $customerName, 'customer_email' => $customerEmail ?: null,
                'customer_phone' => $customerPhone ?: null, 'issue_date' => $issueDate, 'due_date' => $dueDate,
                'currency' => 'KES', 'status' => 'draft', 'subtotal' => $subtotal,
                'discount_amount' => 0, 'tax_amount' => 0, 'total_amount' => $subtotal,
                'notes' => trim((string) $request->getPost('notes')) ?: null,
                'created_by' => auth()->id(), 'created_at' => $now, 'updated_at' => $now,
            ]);
            if (!$saved) {
                throw new \RuntimeException('Could not save invoice.');
            }
            $invoiceId = $db->insertID();
            foreach ($items as $order => $item) {
                $item['invoice_id'] = $invoiceId;
                $item['sort_order'] = $order;
                $item['created_at'] = $now;
                $item['updated_at'] = $now;
                if (!$db->table('invoice_items')->insert($item)) {
                    throw new \RuntimeException('Could not save invoice item.');
                }
            }
            if ($db->transStatus() === false) {
                throw new \RuntimeException('Invoice transaction failed.');
            }
            $db->transCommit();
        } catch (\Throwable $e) {
            $db->transRollback();
            log_message('error', 'Invoice draft creation failed: ' . $e->getMessage());
            return redirect()->back()->withInput()->with('error', 'Invoice could not be saved. Check the application log.');
        }
        return redirect()->to(site_url('invoices'))->with('success', 'Invoice draft #' . $invoiceId . ' saved. No invoice number or payment has been issued.');
    }


    /**
     * Create an invoice draft directly from a submitted KYC record.
     * Requires a protected POST route with finance.manage permission.
     * Existing invoices are always reused instead of duplicated.
     */
    public function fromKyc(int $kycId)
    {
        $db = db_connect();
        $kyc = $db->table('customer_kyc')->where('id', $kycId)
            ->where('submitted_at IS NOT NULL', null, false)->get()->getRowArray();
        if (!$kyc || empty($kyc['reservation_id']) || empty($kyc['customer_id'])) {
            return redirect()->to(site_url('staff/kyc'))->with('error', 'A submitted, reservation-linked KYC record is required.');
        }

        $reservationId = (int) $kyc['reservation_id'];
        $db->transBegin();
        try {
            // Lock the reservation so simultaneous clicks cannot both create a draft.
            $reservation = $db->query('SELECT * FROM reservations WHERE id = ? FOR UPDATE', [$reservationId])->getRowArray();
            if (!$reservation || $reservation['status'] === 'cancelled') {
                throw new \RuntimeException('The linked reservation is unavailable or cancelled.');
            }
            if ((int) ($reservation['customer_id'] ?? 0) !== (int) $kyc['customer_id']) {
                throw new \RuntimeException('KYC customer does not match the reservation.');
            }

            $existing = $db->table('invoices')->select('id')
                ->where('reservation_id', $reservationId)
                ->where('status !=', 'void')->orderBy('id', 'DESC')->get()->getRowArray();
            if ($existing) {
                $db->transCommit();
                return redirect()->to(site_url('invoices/' . $existing['id']))
                    ->with('success', 'This reservation already has an invoice. Opened the existing invoice.');
            }

            $customer = $db->table('customers')->where('id', $kyc['customer_id'])->get()->getRowArray();
            if (!$customer || trim((string) $customer['full_name']) === '') {
                throw new \RuntimeException('Customer details are missing.');
            }
            $checkIn = new \DateTimeImmutable($reservation['check_in']);
            $checkOut = new \DateTimeImmutable($reservation['check_out']);
            $nights = (int) $checkIn->diff($checkOut)->format('%r%a');
            $total = round((float) $reservation['total_amount'], 2);
            if ($nights < 1 || $total <= 0 || $total > 9999999999.99) {
                throw new \RuntimeException('Reservation dates or invoice total are invalid.');
            }
            $email = trim((string) ($customer['email'] ?? ''));
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new \RuntimeException('Customer email is invalid.');
            }

            $now = date('Y-m-d H:i:s');
            $saved = $db->table('invoices')->insert([
                'invoice_number' => null,
                'invoice_type' => 'reservation',
                'reservation_id' => $reservationId,
                'customer_name' => trim((string) $customer['full_name']),
                'customer_email' => $email !== '' ? $email : null,
                'customer_phone' => trim((string) $customer['primary_phone']) ?: null,
                'issue_date' => date('Y-m-d'),
                'due_date' => date('Y-m-d'),
                'currency' => 'KES',
                'status' => 'draft',
                'subtotal' => $total,
                'discount_amount' => 0,
                'tax_amount' => 0,
                'total_amount' => $total,
                'notes' => 'Prepared from submitted KYC #' . $kycId,
                'created_by' => auth()->id(),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            if (!$saved) {
                throw new \RuntimeException('Could not save invoice draft.');
            }
            $invoiceId = (int) $db->insertID();
            $itemSaved = $db->table('invoice_items')->insert([
                'invoice_id' => $invoiceId,
                'description' => 'Accommodation — booking #' . $reservationId . ' ('
                    . $reservation['check_in'] . ' to ' . $reservation['check_out'] . ', ' . $nights . ' nights)',
                'quantity' => 1,
                'unit_price' => $total,
                'line_total' => $total,
                'sort_order' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            if (!$itemSaved || $db->transStatus() === false) {
                throw new \RuntimeException('Could not save invoice line item.');
            }
            $db->transCommit();
            return redirect()->to(site_url('invoices/' . $invoiceId))
                ->with('success', 'Draft invoice created from KYC #' . $kycId . '. Review before issuing.');
        } catch (\Throwable $e) {
            $db->transRollback();
            log_message('error', 'KYC invoice creation failed: ' . $e->getMessage());
            return redirect()->to(site_url('staff/kyc/' . $kycId))
                ->with('error', 'Invoice not created: ' . $e->getMessage());
        }
    }

    public function show(int $id)
    {
        $db = db_connect();
        $invoice = $db->table('invoices')->where('id', $id)->get()->getRowArray();
        if (!$invoice) {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound('Invoice not found.');
        }
        $items = $db->table('invoice_items')->where('invoice_id', $id)->orderBy('sort_order', 'ASC')->orderBy('id', 'ASC')->get()->getResultArray();
        $settings = $db->table('invoice_settings')->where('id', 1)->get()->getRowArray() ?? [];
        $allocated = $db->table('invoice_payment_allocations')->selectSum('amount', 'total')->where('invoice_id', $id)->get()->getRowArray();
        $paid = (float) ($allocated['total'] ?? 0);
        // Each allocation is a separate receipt, including deposits and partial payments.
        $paymentHistory = $db->table('invoice_payment_allocations AS a')
            ->select('a.id, a.guest_payment_id, a.amount, a.allocated_at, p.payment_date, p.payment_method, p.reference')
            ->join('guest_payments AS p', 'p.id = a.guest_payment_id', 'inner')
            ->where('a.invoice_id', $id)
            ->orderBy('a.allocated_at', 'ASC')
            ->orderBy('a.id', 'ASC')
            ->get()->getResultArray();
        $customers = $db->table('customers')
            ->select('id, full_name, primary_phone, email')
            ->orderBy('full_name', 'ASC')->get()->getResultArray();
        $linkedCustomer = null;
        if (!empty($invoice['customer_id'])) {
            $linkedCustomer = $db->table('customers')
                ->where('id', (int) $invoice['customer_id'])->get()->getRowArray();
        }
        return view('invoices/show', [
            'customers' => $customers,
            'linkedCustomer' => $linkedCustomer,
            'invoice' => $invoice,
            'items' => $items,
            'settings' => $settings,
            'paid' => $paid,
            'paymentHistory' => $paymentHistory,
            'balance' => max(0, round((float) $invoice['total_amount'] - $paid, 2)),
            'canManage' => auth()->user()?->can('finance.manage') ?? false,
        ]);
    }

    public function issue(int $id)
    {
        $db = db_connect();
        $db->transBegin();
        try {
            // Serialize numbering across concurrent requests by locking the settings row.
            $settings = $db->query('SELECT id, invoice_prefix, next_sequence FROM invoice_settings WHERE id = 1 FOR UPDATE')->getRowArray();
            if (!$settings) {
                throw new \RuntimeException('Invoice settings are missing.');
            }
            $invoice = $db->query('SELECT id, status, invoice_number, total_amount, discount_amount, tax_amount, issue_date, due_date FROM invoices WHERE id = ? FOR UPDATE', [$id])->getRowArray();
            if (!$invoice || $invoice['status'] !== 'draft' || $invoice['invoice_number'] !== null) {
                throw new \RuntimeException('Only unnumbered draft invoices can be issued.');
            }
            $items = $db->table('invoice_items')->where('invoice_id', $id)->get()->getResultArray();
            if (!$items || (float) $invoice['total_amount'] <= 0) {
                throw new \RuntimeException('An invoice must have items and a positive total before issuing.');
            }
            $sum = 0;
            foreach ($items as $item) {
                $sum += (float) $item['line_total'];
            }
            $expected = round($sum - (float) $invoice['discount_amount'] + (float) $invoice['tax_amount'], 2);
            if (abs($expected - (float) $invoice['total_amount']) > 0.01) {
                throw new \RuntimeException('Invoice line items do not match the invoice total.');
            }
            $prefix = trim((string) $settings['invoice_prefix']);
            $sequence = (int) $settings['next_sequence'];
            if ($prefix === '' || $sequence < 1 || $sequence >= 4294967295) {
                throw new \RuntimeException('Invoice numbering settings are invalid.');
            }
            $date = date('Y-m-d');
            $number = $prefix . '-' . date('Y') . '-' . str_pad((string) $sequence, 6, '0', STR_PAD_LEFT);
            if (strlen($number) > 40) {
                throw new \RuntimeException('Invoice number exceeds the allowed length.');
            }
            if (!$db->table('invoices')->where('id', $id)->where('status', 'draft')->update([
                'invoice_number' => $number, 'status' => 'issued', 'issue_date' => $date,
                'issued_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
            ])) {
                throw new \RuntimeException('Could not issue invoice.');
            }
            if (!$db->table('invoice_settings')->where('id', 1)->update(['next_sequence' => $sequence + 1, 'updated_at' => date('Y-m-d H:i:s')])) {
                throw new \RuntimeException('Could not advance invoice sequence.');
            }
            if ($db->transStatus() === false) {
                throw new \RuntimeException('Invoice transaction failed.');
            }
            $db->transCommit();
            return redirect()->to(site_url('invoices/' . $id))->with('success', 'Invoice ' . $number . ' issued successfully.');
        } catch (\Throwable $e) {
            $db->transRollback();
            log_message('error', 'Invoice issue failed: ' . $e->getMessage());
            return redirect()->to(site_url('invoices/' . $id))->with('error', 'Invoice could not be issued: ' . $e->getMessage());
        }
    }

    /**
     * Link an existing customer to an invoice without modifying the issued
     * invoice's billing snapshot, amounts, or payment allocations.
     * Route: POST invoices/(:num)/customer, finance.manage permission.
     */
    public function linkCustomer(int $id)
    {
        if (!(auth()->user()?->can('finance.manage') ?? false)) {
            return redirect()->to(site_url('invoices/' . $id))
                ->with('error', 'You do not have permission to link customers.');
        }

        $customerId = filter_var($this->request->getPost('customer_id'), FILTER_VALIDATE_INT);
        if ($customerId === false || $customerId < 1) {
            return redirect()->to(site_url('invoices/' . $id))
                ->with('error', 'Please select a valid existing customer.');
        }

        $db = db_connect();
        $invoice = $db->table('invoices')->select('id, status, customer_id')
            ->where('id', $id)->get()->getRowArray();
        if (!$invoice) {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound('Invoice not found.');
        }
        if ($invoice['status'] === 'void') {
            return redirect()->to(site_url('invoices/' . $id))
                ->with('error', 'Void invoices cannot be linked to customers.');
        }
        $customer = $db->table('customers')->select('id, full_name')
            ->where('id', $customerId)->get()->getRowArray();
        if (!$customer) {
            return redirect()->to(site_url('invoices/' . $id))
                ->with('error', 'The selected customer no longer exists.');
        }
        if (!$db->table('invoices')->where('id', $id)->update([
            'customer_id' => $customerId,
            'updated_at' => date('Y-m-d H:i:s'),
        ])) {
            return redirect()->to(site_url('invoices/' . $id))
                ->with('error', 'Could not link this customer.');
        }
        return redirect()->to(site_url('invoices/' . $id))
            ->with('success', 'Invoice linked to customer ' . $customer['full_name'] . '. Billing details remain unchanged.');
    }

}
