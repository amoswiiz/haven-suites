<?php

namespace App\Http\Controllers\Haven;

use App\Models\Haven\ReservationModel;
use App\Models\Haven\UnitModel;
use App\Services\ApartmentReadiness;
use App\Support\Migration\PageNotFoundException;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

class Reservations extends BaseController
{
    public function index()
    {
        $model = new ReservationModel;
        $activity = $this->request->getGet('activity');
        if ($activity === 'checkins') {
            $model->where('reservations.check_in', date('Y-m-d'))
                ->whereIn('reservations.status', ['confirmed', 'checked_in']);
        } elseif ($activity === 'checkouts') {
            $model->where('reservations.check_out', date('Y-m-d'))
                ->whereIn('reservations.status', ['confirmed', 'checked_in', 'checked_out']);
        } elseif (in_array($activity, ['checkouts_pending', 'checkouts_completed'], true)) {
            $model->where('reservations.check_out', date('Y-m-d'))
                ->whereIn('reservations.status', $activity === 'checkouts_pending' ? ['confirmed', 'checked_in'] : ['checked_out']);
        }
        $reservations = $model
            ->select('reservations.*, COALESCE((SELECT SUM(gp.amount) FROM guest_payments gp WHERE gp.reservation_id = reservations.id),0) AS recorded_paid, units.unit_number, properties.name AS property_name, '
                .'pa.apartment_name AS partner_name, pa.building_name AS partner_building, '
                .'pa.location AS partner_location, pa.owner_name AS partner_owner')
            ->join('units', 'units.id = reservations.unit_id', 'left')
            ->join('properties', 'properties.id = units.property_id', 'left')
            ->join('partner_apartments pa', 'pa.id = reservations.partner_apartment_id', 'left')
            ->orderBy('reservations.check_in', 'DESC')->findAll();

        return haven_view('reservations/index', ['reservations' => $reservations]);
    }

    /**
     * Display an individual reservation without modifying its financial records.
     * Route: GET reservations/(:num) -> Reservations::show/$1
     */
    public function show(int $id)
    {
        $db = db_connect();
        $reservation = $db->table('reservations AS r')
            ->select('r.*, u.unit_number, p.name AS property_name, '
                .'pa.apartment_name AS partner_name, pa.building_name AS partner_building, '
                .'pa.location AS partner_location, pa.owner_name AS partner_owner')
            ->join('units AS u', 'u.id = r.unit_id', 'left')
            ->join('properties AS p', 'p.id = u.property_id', 'left')
            ->join('partner_apartments AS pa', 'pa.id = r.partner_apartment_id', 'left')
            ->where('r.id', $id)->get()->getRowArray();

        if (! $reservation) {
            throw PageNotFoundException::forPageNotFound('Reservation not found.');
        }

        $payments = $db->table('guest_payments')
            ->where('reservation_id', $id)
            ->orderBy('payment_date', 'DESC')->orderBy('id', 'DESC')
            ->get()->getResultArray();

        $invoices = $db->table('invoices')
            ->where('reservation_id', $id)
            ->orderBy('id', 'DESC')->get()->getResultArray();

        $paymentInvoice = null;
        $allocatedTotal = 0.0;
        foreach ($invoices as $invoice) {
            $allocated = $db->table('invoice_payment_allocations')->selectSum('amount', 'total')->where('invoice_id', $invoice['id'])->get()->getRowArray();
            $allocatedTotal += (float) ($allocated['total'] ?? 0);
            $invoice['balance'] = max(0, round((float) $invoice['total_amount'] - (float) ($allocated['total'] ?? 0), 2));
            if ($paymentInvoice === null && $invoice['status'] === 'issued' && $invoice['balance'] > 0) {
                $paymentInvoice = $invoice;
            }
        }
        $recordedTotal = array_sum(array_map(static fn (array $payment): float => (float) $payment['amount'], $payments));
        $operational = DB::connection('tenant');
        $unitId = (int) ($reservation['unit_id'] ?? 0);
        $workspaceCleaning = auth()->user()->can('housekeeping.view') ? $operational->table('housekeeping')->where(function ($query) use ($id, $unitId): void {
            $query->where('reservation_id', $id);
            if ($unitId) {
                $query->orWhere('unit_id', $unitId);
            }
        })->latest('scheduled_at')->limit(15)->get() : collect();
        $workspaceMaintenance = $unitId && auth()->user()->can('maintenance.view') ? $operational->table('maintenance')->where('unit_id', $unitId)->whereIn('status', ['open', 'assigned', 'in_progress', 'on_hold'])->latest('reported_at')->limit(15)->get() : collect();
        $workspaceKyc = haven_can_access(site_url('staff/kyc'), 'GET') ? $operational->table('customer_kyc')->where('reservation_id', $id)->latest('id')->value('status') : null;
        $workspaceReceipts = auth()->user()->can('finance.view') ? $operational->table('invoice_payment_allocations as a')->join('guest_payments as gp', 'gp.id', '=', 'a.guest_payment_id')->where('gp.reservation_id', $id)->select('a.id', 'a.amount', 'gp.payment_date')->latest('a.id')->get() : collect();
        $readiness = $unitId && auth()->user()->can('units.view') ? app(ApartmentReadiness::class)->overview($unitId)->first() : null;

        return haven_view('reservations/show', [
            'pageTitle' => 'Booking #'.$id,
            'workspaceCleaning' => $workspaceCleaning, 'workspaceMaintenance' => $workspaceMaintenance, 'workspaceKyc' => $workspaceKyc, 'workspaceReceipts' => $workspaceReceipts, 'readiness' => $readiness,
            'paymentInvoice' => $paymentInvoice,
            'unallocatedPayments' => max(0, round($recordedTotal - $allocatedTotal, 2)),
            'reservation' => $reservation,
            'payments' => $payments,
            'invoices' => $invoices,
        ]);
    }

    public function create()
    {
        $salesLead = null;
        if (request()->filled('lead_id')) {
            abort_unless(auth()->user()->can('sales.view'), 403);
            $salesLead = DB::table('sales_leads')->where('tenant_id', app('currentTenant')->id)->where('id', request()->integer('lead_id'))->firstOrFail();
        }
        $units = (new UnitModel)
            ->select('units.*, properties.name AS property_name')
            ->join('properties', 'properties.id = units.property_id', 'left')
            ->where('units.status', 'active')
            ->orderBy('properties.name', 'ASC')
            ->orderBy('units.unit_number', 'ASC')->findAll();

        $partners = db_connect()->table('partner_apartments')
            ->where('status', 'active')
            ->orderBy('apartment_name', 'ASC')->get()->getResultArray();

        return haven_view('reservations/form', ['pageTitle' => 'New booking', 'units' => $units, 'partners' => $partners, 'salesLead' => $salesLead]);
    }

    public function store()
    {
        $db = db_connect();
        $reservationModel = new ReservationModel;
        $sourceLead = null;
        if (request()->filled('lead_id')) {
            abort_unless(auth()->user()->can('sales.view'), 403);
            $sourceLead = DB::table('sales_leads')->where('tenant_id', app('currentTenant')->id)->where('id', request()->integer('lead_id'))->whereNull('reservation_id')->firstOrFail();
        }
        $type = trim((string) $this->request->getPost('booking_type'));
        if (! in_array($type, ['managed', 'partner'], true)) {
            return $this->formError('Select a valid booking type.');
        }

        $unitId = null;
        $partnerId = null;
        $newPartner = null;
        $unit = null;
        if ($type === 'managed') {
            $unitId = (int) $this->request->getPost('unit_id');
            $unit = (new UnitModel)->find($unitId);
            if (! $unit || $unit['status'] !== 'active') {
                return $this->formError('Please select an active managed apartment.');
            }
        } else {
            $choice = (string) $this->request->getPost('partner_choice');
            if ($choice === 'existing') {
                $partnerId = (int) $this->request->getPost('partner_apartment_id');
                $partner = $db->table('partner_apartments')->where('id', $partnerId)
                    ->where('status', 'active')->get()->getRowArray();
                if (! $partner) {
                    return $this->formError('Please select an active partner apartment.');
                }
            } elseif ($choice === 'new') {
                $newPartner = [
                    'owner_name' => trim((string) $this->request->getPost('partner_owner_name')),
                    'owner_phone' => trim((string) $this->request->getPost('partner_owner_phone')),
                    'owner_email' => trim((string) $this->request->getPost('partner_owner_email')),
                    'apartment_name' => trim((string) $this->request->getPost('partner_apartment_name')),
                    'building_name' => trim((string) $this->request->getPost('partner_building_name')),
                    'unit_number' => trim((string) $this->request->getPost('partner_unit_number')),
                    'location' => trim((string) $this->request->getPost('partner_location')),
                    'bedrooms' => $this->request->getPost('partner_bedrooms'),
                    'bathrooms' => $this->request->getPost('partner_bathrooms'),
                    'notes' => trim((string) $this->request->getPost('partner_notes')),
                    'status' => 'active',
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ];
                foreach (['owner_name', 'apartment_name', 'location'] as $required) {
                    if ($newPartner[$required] === '' || mb_strlen($newPartner[$required]) > 150) {
                        return $this->formError('Please complete the partner owner, apartment name and location (maximum 150 characters).');
                    }
                }
                foreach (['owner_phone' => 40, 'owner_email' => 150, 'building_name' => 150, 'unit_number' => 50] as $field => $limit) {
                    if (mb_strlen($newPartner[$field]) > $limit) {
                        return $this->formError('Partner details are too long: '.$field);
                    }
                }
                if ($newPartner['owner_email'] !== '' && ! filter_var($newPartner['owner_email'], FILTER_VALIDATE_EMAIL)) {
                    return $this->formError('Please enter a valid partner email address.');
                }
                if (filter_var($newPartner['bedrooms'], FILTER_VALIDATE_INT) === false || (int) $newPartner['bedrooms'] < 0) {
                    return $this->formError('Enter a valid bedroom count.');
                }
                $newPartner['bedrooms'] = (int) $newPartner['bedrooms'];
                if ($newPartner['bathrooms'] === '' || $newPartner['bathrooms'] === null) {
                    $newPartner['bathrooms'] = null;
                } elseif (filter_var($newPartner['bathrooms'], FILTER_VALIDATE_INT) === false || (int) $newPartner['bathrooms'] < 0) {
                    return $this->formError('Enter a valid bathroom count.');
                } else {
                    $newPartner['bathrooms'] = (int) $newPartner['bathrooms'];
                }
            } else {
                return $this->formError('Select an existing partner or add a new one.');
            }
        }

        $checkIn = trim((string) $this->request->getPost('check_in'));
        $checkOut = trim((string) $this->request->getPost('check_out'));
        $start = DateTimeImmutable::createFromFormat('!Y-m-d', $checkIn);
        $end = DateTimeImmutable::createFromFormat('!Y-m-d', $checkOut);
        if (! $start || ! $end || $start->format('Y-m-d') !== $checkIn
            || $end->format('Y-m-d') !== $checkOut || $end <= $start) {
            return $this->formError('Check-out must be later than check-in.');
        }
        $nights = (int) $start->diff($end)->days;
        $status = trim((string) $this->request->getPost('status'));
        if (! in_array($status, ['pending', 'confirmed', 'checked_in', 'checked_out', 'cancelled'], true)) {
            return $this->formError('Invalid reservation status.');
        }
        $adults = $this->request->getPost('adults');
        $children = $this->request->getPost('children');
        if (filter_var($adults, FILTER_VALIDATE_INT) === false || (int) $adults < 1
            || filter_var($children, FILTER_VALIDATE_INT) === false || (int) $children < 0) {
            return $this->formError('Please enter valid guest numbers.');
        }
        $guestName = trim((string) $this->request->getPost('guest_name'));
        $guestPhone = trim((string) $this->request->getPost('guest_phone'));
        $guestEmail = trim((string) $this->request->getPost('guest_email'));
        if ($guestName === '' || mb_strlen($guestName) > 150 || $guestPhone === '' || mb_strlen($guestPhone) > 40
            || ($guestEmail !== '' && (mb_strlen($guestEmail) > 150 || ! filter_var($guestEmail, FILTER_VALIDATE_EMAIL)))) {
            return $this->formError('Please enter valid guest contact details.');
        }
        $amountPaid = $this->request->getPost('amount_paid');
        $amountPaid = ($amountPaid === '' || $amountPaid === null) ? 0 : $amountPaid;
        if (! is_numeric($amountPaid) || ! is_finite((float) $amountPaid) || (float) $amountPaid !== 0.0) {
            return $this->formError('Save the booking first, then record money received against its invoice to generate a receipt.');
        }
        $ownerNet = null;
        if ($type === 'partner') {
            // Partner prices are negotiated PER NIGHT, never for the entire stay.
            $guestNightly = $this->request->getPost('guest_total');
            $ownerNightly = $this->request->getPost('owner_net_amount');
            if (! is_numeric($guestNightly) || ! is_numeric($ownerNightly)
                || ! is_finite((float) $guestNightly) || ! is_finite((float) $ownerNightly)
                || (float) $guestNightly <= 0 || (float) $ownerNightly < 0
                || (float) $ownerNightly > (float) $guestNightly) {
                return $this->formError('Guest nightly rate must be positive and at least the owner net nightly rate.');
            }
            $rate = round((float) $guestNightly, 2);
            $ownerNightly = round((float) $ownerNightly, 2);
            $totalAmount = round($nights * $rate, 2);
            // Existing column stores the owner's total for the full booking.
            $ownerNet = round($nights * $ownerNightly, 2);
        } else {
            $rate = $this->request->getPost('nightly_rate');
            if ($rate === '' || $rate === null) {
                $rate = $unit['nightly_rate'];
            }
            if (! is_numeric($rate) || ! is_finite((float) $rate) || (float) $rate < 0) {
                return $this->formError('Please enter a valid nightly rate.');
            }
            $rate = round((float) $rate, 2);
            $totalAmount = round($nights * $rate, 2);
        }
        if ((float) $amountPaid > $totalAmount) {
            return $this->formError('Amount paid cannot exceed the booking total.');
        }
        $data = [
            'booking_type' => $type,
            'unit_id' => $unitId,
            'partner_apartment_id' => $partnerId,
            'owner_net_amount' => $ownerNet,
            'guest_name' => $guestName,
            'guest_phone' => $guestPhone,
            'guest_email' => $guestEmail,
            'check_in' => $checkIn,
            'check_out' => $checkOut,
            'adults' => (int) $adults,
            'children' => (int) $children,
            'booking_source' => trim((string) $this->request->getPost('booking_source')) ?: 'Direct',
            'nightly_rate' => $rate,
            'total_amount' => $totalAmount,
            'amount_paid' => round((float) $amountPaid, 2),
            'status' => $status,
            'notes' => trim((string) $this->request->getPost('notes')),
        ];
        $db->transBegin();
        try {
            if ($newPartner !== null) {
                if (! $db->table('partner_apartments')->insert($newPartner)) {
                    throw new \RuntimeException('Could not create partner apartment.');
                }
                $partnerId = (int) $db->insertID();
                $data['partner_apartment_id'] = $partnerId;
            }
            // Only confirmed/checked-in reservations block dates, consistent with existing logic.
            if (in_array($status, ['confirmed', 'checked_in'], true)) {
                $overlap = $db->table('reservations')
                    ->where($type === 'managed' ? 'unit_id' : 'partner_apartment_id',
                        $type === 'managed' ? $unitId : $partnerId)
                    ->whereIn('status', ['confirmed', 'checked_in'])
                    ->where('check_in <', $checkOut)
                    ->where('check_out >', $checkIn)->countAllResults();
                if ($overlap > 0) {
                    throw new \RuntimeException('This apartment already has a confirmed booking for those dates.');
                }
            }
            if ($reservationModel->insert($data) === false) {
                throw new \RuntimeException(implode(' ', $reservationModel->errors()) ?: 'Reservation could not be saved.');
            }
            if ($db->transStatus() === false) {
                throw new \RuntimeException('Database transaction failed.');
            }
            $db->transCommit();
        } catch (\Throwable $e) {
            $db->transRollback();
            log_message('error', 'Reservation save failed: {error}', ['error' => $e->getMessage()]);

            return $this->formError($e instanceof \RuntimeException ? $e->getMessage()
                : 'Unable to save reservation. Please check the details.');
        }

        return redirect()->to(site_url('reservations/'.(int) $reservationModel->getInsertID().($sourceLead ? '?lead_id='.$sourceLead->id : '')))
            ->with('success', 'Booking saved. Next, prepare its invoice. Record payments only after money has been received.');
    }

    /** Edit an existing reservation. Financial payments are managed separately. */
    public function edit(int $id)
    {
        $db = db_connect();
        $reservation = $db->table('reservations')->where('id', $id)->get()->getRowArray();
        if (! $reservation) {
            throw PageNotFoundException::forPageNotFound('Reservation not found.');
        }
        $reservation['recorded_paid'] = (float) DB::connection('tenant')->table('guest_payments')->where('reservation_id', $id)->sum('amount');
        $units = (new UnitModel)
            ->select('units.*, properties.name AS property_name')
            ->join('properties', 'properties.id = units.property_id', 'left')
            ->groupStart()->where('units.status', 'active')->orWhere('units.id', $reservation['unit_id'] ?: 0)->groupEnd()
            ->orderBy('properties.name', 'ASC')->orderBy('units.unit_number', 'ASC')->findAll();
        $partners = $db->table('partner_apartments')
            ->groupStart()->where('status', 'active')->orWhere('id', $reservation['partner_apartment_id'] ?: 0)->groupEnd()
            ->orderBy('apartment_name', 'ASC')->get()->getResultArray();

        return haven_view('reservations/edit', compact('reservation', 'units', 'partners'));
    }

    /** Update reservation details without modifying payment ledgers. */
    public function update(int $id)
    {
        $db = db_connect();
        $original = $db->table('reservations')->where('id', $id)->get()->getRowArray();
        if (! $original) {
            throw PageNotFoundException::forPageNotFound('Reservation not found.');
        }
        $type = (string) ($original['booking_type'] ?: 'managed');
        $name = trim((string) $this->request->getPost('guest_name'));
        $phone = trim((string) $this->request->getPost('guest_phone'));
        $email = trim((string) $this->request->getPost('guest_email'));
        if ($name === '' || mb_strlen($name) > 150 || $phone === '' || mb_strlen($phone) > 40
            || ($email !== '' && (mb_strlen($email) > 150 || ! filter_var($email, FILTER_VALIDATE_EMAIL)))) {
            return $this->formError('Please enter valid guest contact details.');
        }
        $checkIn = trim((string) $this->request->getPost('check_in'));
        $checkOut = trim((string) $this->request->getPost('check_out'));
        $start = DateTimeImmutable::createFromFormat('!Y-m-d', $checkIn);
        $end = DateTimeImmutable::createFromFormat('!Y-m-d', $checkOut);
        if (! $start || ! $end || $start->format('Y-m-d') !== $checkIn
            || $end->format('Y-m-d') !== $checkOut || $end <= $start) {
            return $this->formError('Check-out must be later than check-in.');
        }
        $adults = $this->request->getPost('adults');
        $children = $this->request->getPost('children');
        if (filter_var($adults, FILTER_VALIDATE_INT) === false || (int) $adults < 1
            || filter_var($children, FILTER_VALIDATE_INT) === false || (int) $children < 0) {
            return $this->formError('Please enter valid guest numbers.');
        }
        $status = trim((string) $this->request->getPost('status'));
        if (! in_array($status, ['pending', 'confirmed', 'checked_in', 'checked_out', 'cancelled'], true)) {
            return $this->formError('Invalid reservation status.');
        }
        $unitId = null;
        $partnerId = null;
        if ($type === 'partner') {
            $partnerId = (int) $this->request->getPost('partner_apartment_id');
            $partner = $db->table('partner_apartments')->where('id', $partnerId)->get()->getRowArray();
            if (! $partner || ($partner['status'] !== 'active' && $partnerId !== (int) $original['partner_apartment_id'])) {
                return $this->formError('Please select an active partner apartment.');
            }
        } else {
            $unitId = (int) $this->request->getPost('unit_id');
            $unit = (new UnitModel)->find($unitId);
            if (! $unit || ($unit['status'] !== 'active' && $unitId !== (int) $original['unit_id'])) {
                return $this->formError('Please select an active managed apartment.');
            }
        }
        $rateInput = $this->request->getPost('nightly_rate');
        if (! is_numeric($rateInput) || ! is_finite((float) $rateInput) || (float) $rateInput < 0
            || ($type === 'partner' && (float) $rateInput <= 0)) {
            return $this->formError('Please enter a valid nightly rate.');
        }
        $rate = round((float) $rateInput, 2);
        $nights = (int) $start->diff($end)->days;
        $total = round($nights * $rate, 2);
        $ownerNet = $original['owner_net_amount'];
        if ($type === 'partner') {
            $ownerNightlyInput = $this->request->getPost('owner_nightly_rate');
            if (! is_numeric($ownerNightlyInput) || ! is_finite((float) $ownerNightlyInput)
                || (float) $ownerNightlyInput < 0 || (float) $ownerNightlyInput > $rate) {
                return $this->formError('Owner nightly rate must be between zero and guest nightly rate.');
            }
            $ownerNet = round($nights * (float) $ownerNightlyInput, 2);
        }
        $registeredPaid = (float) DB::connection('tenant')->table('guest_payments')->where('reservation_id', $id)->sum('amount');
        if ($total < max((float) $original['amount_paid'], $registeredPaid)) {
            return $this->formError('Booking total cannot be less than the amount already paid.');
        }
        // Issued invoices must be adjusted through invoice workflows, not silently changed here.
        $financialChange = abs($total - (float) $original['total_amount']) > 0.009;
        if ($financialChange && $db->table('invoices')->where('reservation_id', $id)
            ->where('status !=', 'draft')->countAllResults() > 0) {
            return $this->formError('This booking has a non-draft invoice. Adjust the invoice before changing the booking total.');
        }
        $data = [
            'unit_id' => $unitId,
            'partner_apartment_id' => $partnerId,
            'guest_name' => $name,
            'guest_phone' => $phone,
            'guest_email' => $email,
            'check_in' => $checkIn,
            'check_out' => $checkOut,
            'adults' => (int) $adults,
            'children' => (int) $children,
            'booking_source' => trim((string) $this->request->getPost('booking_source')) ?: 'Direct',
            'nightly_rate' => $rate,
            'total_amount' => $total,
            'owner_net_amount' => $ownerNet,
            'status' => $status,
            'notes' => trim((string) $this->request->getPost('notes')),
            'updated_at' => date('Y-m-d H:i:s'),
        ];
        $db->transBegin();
        try {
            $db->query('SELECT id FROM reservations WHERE id = ? FOR UPDATE', [$id]);
            $latestPaid = (float) DB::connection('tenant')->table('guest_payments')->where('reservation_id', $id)->sum('amount');
            if ($total < max((float) $original['amount_paid'], $latestPaid)) {
                throw new \RuntimeException('The booking total cannot be lower than money already received.');
            }
            if (in_array($status, ['confirmed', 'checked_in'], true)) {
                $overlap = $db->table('reservations')
                    ->where('id !=', $id)
                    ->where($type === 'partner' ? 'partner_apartment_id' : 'unit_id', $type === 'partner' ? $partnerId : $unitId)
                    ->whereIn('status', ['confirmed', 'checked_in'])
                    ->where('check_in <', $checkOut)
                    ->where('check_out >', $checkIn)
                    ->countAllResults();
                if ($overlap > 0) {
                    throw new \RuntimeException('This apartment has another confirmed booking for those dates.');
                }
            }
            if (! $db->table('reservations')->where('id', $id)->update($data) || $db->transStatus() === false) {
                throw new \RuntimeException('Reservation could not be updated.');
            }
            $db->transCommit();
        } catch (\Throwable $e) {
            $db->transRollback();
            log_message('error', 'Reservation update failed: {error}', ['error' => $e->getMessage()]);

            return $this->formError($e instanceof \RuntimeException ? $e->getMessage() : 'Unable to update reservation.');
        }

        return redirect()->to(site_url('reservations/'.$id))
            ->with('success', 'Reservation updated successfully.');
    }

    private function formError(string $message)
    {
        return redirect()->back()->withInput()->with('error', $message);
    }
}
