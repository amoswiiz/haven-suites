<?php
namespace App\Http\Controllers\Haven;

class Customers extends BaseController
{
    public function index()
    {
        $db = db_connect();
        $customers = $db->table('customers AS c')
            ->select('c.id, c.full_name, c.email, c.primary_phone, c.alternative_contact, c.created_at')
            ->orderBy('c.id', 'DESC')->get()->getResultArray();
        return haven_view('customers/index', ['customers' => $customers]);
    }

    public function create()
    {
        return haven_view('customers/create');
    }

    public function store()
    {
        $name = trim((string) $this->request->getPost('full_name'));
        $phone = trim((string) $this->request->getPost('primary_phone'));
        $email = trim((string) $this->request->getPost('email'));
        $alternative = trim((string) $this->request->getPost('alternative_contact'));
        if ($name === '' || mb_strlen($name) > 180 || $phone === '' || mb_strlen($phone) > 40 || mb_strlen($alternative) > 180) {
            return redirect()->back()->withInput()->with('error', 'Enter a valid full name and primary phone number.');
        }
        if ($email !== '' && (mb_strlen($email) > 180 || !filter_var($email, FILTER_VALIDATE_EMAIL))) {
            return redirect()->back()->withInput()->with('error', 'Enter a valid email or leave it blank.');
        }
        $db = db_connect();
        $now = date('Y-m-d H:i:s');
        $ok = $db->table('customers')->insert([
            'full_name' => $name,
            'primary_phone' => $phone,
            'email' => $email !== '' ? $email : null,
            'alternative_contact' => $alternative !== '' ? $alternative : null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        if (!$ok) {
            return redirect()->back()->withInput()->with('error', 'Unable to save customer.');
        }
        return redirect()->to(site_url('customers/' . $db->insertID()))->with('success', 'Customer created successfully.');
    }

    public function show(int $id)
    {
        $db = db_connect();
        $customer = $db->table('customers')->where('id', $id)->get()->getRowArray();
        if (!$customer) {
            throw \App\Support\Migration\PageNotFoundException::forPageNotFound('Customer not found');
        }

        $kyc = $db->table('customer_kyc')
            ->select('id, reservation_id, status, submitted_at, reviewed_at')
            ->where('customer_id', $id)
            ->orderBy('id', 'DESC')->get()->getResultArray();

        $reservations = $db->table('reservations')
            ->select('id, check_in, check_out, guest_name, status, total_amount, booking_type, unit_id, partner_apartment_id')
            ->where('customer_id', $id)
            ->orderBy('id', 'DESC')->get()->getResultArray();

        // Match invoices using the reservation relationship, not name or phone,
        // so different customers with similar details never share invoices.
        $reservationIds = array_map(static fn ($r) => (int) $r['id'], $reservations);
        $invoices = [];
        if ($reservationIds !== []) {
            $invoices = $db->table('invoices')
                ->select('id, invoice_number, reservation_id, invoice_type, customer_name, issue_date, due_date, status, currency, total_amount')
                ->whereIn('reservation_id', $reservationIds)
                ->orderBy('id', 'DESC')->get()->getResultArray();
        }

        return haven_view('customers/show', [
            'customer' => $customer,
            'kyc' => $kyc,
            'reservations' => $reservations,
            'invoices' => $invoices,
        ]);
    }
}
