<?php



namespace App\Http\Controllers\Haven;



use App\Models\Haven\HousekeepingModel;

use App\Models\Haven\UnitModel;

use App\Models\Haven\StaffModel;

use App\Models\Haven\ReservationModel;

use App\Models\Haven\CleaningRateModel;

use App\Support\Migration\PageNotFoundException;



class Housekeeping extends BaseController

{

    /**

     * Determine whether the logged-in user is a cleaner.

     */

    private function isCleaner(): bool

    {

        $user = haven_auth()->user();



        return $user !== null && $user->inGroup('cleaner');

    }



    /**

     * Find the staff record linked to the logged-in user.

     */

    private function currentStaffId(): ?int

    {

        $user = haven_auth()->user();



        if (!$user) {

            return null;

        }



        $member = (new StaffModel())

            ->where('user_id', $user->id)

            ->first();



        return $member ? (int) $member['id'] : null;

    }



    /**

     * Check whether the user can manage housekeeping.

     */

    private function canManage(): bool

    {

        $user = haven_auth()->user();



        return $user !== null

            && !$this->isCleaner()

            && $user->can('housekeeping.manage');

    }



    /**

     * Return a 403 response for unauthorized actions.

     */

    private function forbidden()

    {

        return haven_service('response')

            ->setStatusCode(403)

            ->setContent('Permission denied.');

    }



    /**

     * Display housekeeping tasks.

     */

    public function index()

    {

        $user = haven_auth()->user();



        if (!$user || !$user->can('housekeeping.view')) {

            return $this->forbidden();

        }



        $model = new HousekeepingModel();



        $model

            ->select(

                'housekeeping.*, ' .

                'units.unit_number, ' .

                'units.bedrooms AS unit_bedrooms, ' .

                'properties.name AS property_name, ' .

                'assigned.full_name AS assigned_staff_name, ' .

                'verifier.full_name AS verified_by_name'

            )

            ->join(

                'units',

                'units.id = housekeeping.unit_id',

                'left'

            )

            ->join(

                'properties',

                'properties.id = units.property_id',

                'left'

            )

            ->join(

                'staff AS assigned',

                'assigned.id = housekeeping.assigned_staff_id',

                'left'

            )

            ->join(

                'staff AS verifier',

                'verifier.id = housekeeping.verified_by_staff_id',

                'left'

            );



        if ($this->isCleaner()) {

            $staffId = $this->currentStaffId();



            if ($staffId === null) {

                $model->where('housekeeping.id', 0);

            } else {

                $model->where(

                    'housekeeping.assigned_staff_id',

                    $staffId

                );

            }

        } elseif (!$this->canManage()) {

            return $this->forbidden();

        }



        // Validate filters before applying them to the database query.

        $month = trim((string) ($this->request->getGet('month') ?? ''));

        if ($month !== '' && (!preg_match('/^**\d**{4}-(0[1-9]|1[0-2])$/', $month))) {

            return $this->formError('Please select a valid month.');

        }



        $filters = ['month' => $month];

        foreach (['property_id', 'unit_id', 'staff_id'] as $field) {

            $raw = $this->request->getGet($field);

            if ($raw === null || $raw === '') {

                $filters[$field] = '';

                continue;

            }

            if (!is_scalar($raw) || !ctype_digit((string) $raw) || (int) $raw < 1) {

                return $this->formError('Please select a valid filter.');

            }

            $filters[$field] = (int) $raw;

        }



        if ($month !== '') {

            $firstDay = $month . '-01';

            $nextMonth = (new \DateTimeImmutable($firstDay))->modify('+1 month')->format('Y-m-d');

            $model->where('housekeeping.scheduled_at >=', $firstDay . ' 00:00:00');

            $model->where('housekeeping.scheduled_at <', $nextMonth . ' 00:00:00');

        }

        if ($filters['property_id'] !== '') {

            $model->where('units.property_id', $filters['property_id']);

        }

        if ($filters['unit_id'] !== '') {

            $model->where('housekeeping.unit_id', $filters['unit_id']);

        }

        if ($filters['staff_id'] !== '') {

            $model->where('housekeeping.assigned_staff_id', $filters['staff_id']);

        }



        if ($this->request->getGet('activity') === 'pending') {
            $model->whereIn('housekeeping.status', ['pending', 'assigned', 'in_progress']);
        }

        $tasks = $model->orderBy('housekeeping.scheduled_at', 'DESC')->findAll();



        return haven_view('housekeeping/index', [

            'tasks' => $tasks,

            'isCleaner' => $this->isCleaner(),

            'cleaningRates' => (new CleaningRateModel())->getRateOptions(),

            'filters' => $filters,

            'units' => $this->getUnits(),

            'staff' => $this->getStaff(),

            'properties' => db_connect()->table('properties')->select('id, name')->orderBy('name')->get()->getResultArray(),

        ]);

    }



    /**

     * Display the task creation form.

     */

    /** Allow explicitly authorized operational roles to submit cleaning entries. */
    private function canEnterCleaning(): bool
    {
        $user = haven_auth()->user();
        return $user !== null && (
            $this->isCleaner()
            || $user->inGroup('superadmin', 'admin', 'manager', 'operations')
            || $this->canManage()
        );
    }

    /** Cleaner-only form. Route: GET housekeeping/my-cleanings/new */
    public function cleanerCreate()
    {
        if (!$this->canEnterCleaning()) {
            return $this->forbidden();
        }
        if ($this->isCleaner() && $this->currentStaffId() === null) {
            return haven_service('response')->setStatusCode(403)->setContent(
                'Cleaner account is not linked to a staff record. Ask an administrator to link staff.user_id to your login.'
            );
        }

        return haven_view('housekeeping/cleaner_entry', [
            'units' => $this->getUnits(),
            'cleaningRates' => (new CleaningRateModel())->getRateOptions(),
            'today' => date('Y-m-d'),
            'canManageCleanerEntry' => !$this->isCleaner(),
            'cleanerStaff' => $this->getStaff(),
        ]);
    }

    /** Cleaner submits a completed job; price and identity are calculated server-side. */
    public function cleanerStore()
    {
        if (!$this->canEnterCleaning()) {
            return $this->forbidden();
        }
        $staffId = $this->currentStaffId();
        if ($this->isCleaner() && $staffId === null) {
            return haven_service('response')->setStatusCode(403)->setContent(
                'Cleaner account is not linked to a staff record. Ask an administrator to link staff.user_id to your login.'
            );
        }
        if (!$this->isCleaner()) {
            $selectedStaff = $this->request->getPost('assigned_staff_id');
            if (!is_scalar($selectedStaff) || !ctype_digit((string) $selectedStaff) || (int) $selectedStaff < 1) {
                return $this->formError('Select the cleaner who performed the cleaning.');
            }
            $staffRecord = (new StaffModel())->find((int) $selectedStaff);
            if (!$staffRecord) {
                return $this->formError('The selected cleaner was not found.');
            }
            $staffId = (int) $selectedStaff;
        }

        $bedroomsRaw = $this->request->getPost('bedrooms_cleaned');
        $unitRaw = $this->request->getPost('unit_id');
        $date = trim((string) $this->request->getPost('cleaning_date'));
        $type = trim((string) ($this->request->getPost('cleaning_type') ?? 'checkout'));
        $notes = trim((string) $this->request->getPost('notes'));
        $bookingRaw = $this->request->getPost('reservation_id');
        $bookingId = ($bookingRaw === null || $bookingRaw === '') ? null : (int) $bookingRaw;
        $reason = trim((string) $this->request->getPost('repeat_reason'));
        $back = site_url('housekeeping/my-cleanings/new');

        if (!is_scalar($unitRaw) || !ctype_digit((string) $unitRaw) || (int) $unitRaw < 1) {
            return redirect()->to($back)->withInput()->with('error', 'Select a valid apartment.');
        }
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$parsed || $parsed->format('Y-m-d') !== $date || $date > date('Y-m-d')) {
            return redirect()->to($back)->withInput()->with('error', 'Enter a valid cleaning date, not in the future.');
        }
        if (!in_array($type, ['checkout', 'pre_checkin', 'routine', 'deep'], true)
            || mb_strlen($notes) > 5000 || mb_strlen($reason) > 1000) {
            return redirect()->to($back)->withInput()->with('error', 'Check the cleaning type and notes length.');
        }
        if ($bookingRaw !== null && $bookingRaw !== ''
            && (!is_scalar($bookingRaw) || !ctype_digit((string) $bookingRaw) || $bookingId < 1)) {
            return redirect()->to($back)->withInput()->with('error', 'Enter a valid booking number.');
        }

        $unitId = (int) $unitRaw;
        $db = db_connect();
        $db->transBegin();
        try {
            $this->lockAccountingUnits($db, [$unitId]);
            $unit = (new UnitModel())->find($unitId);
            if (!$unit || (int) ($unit['bedrooms'] ?? 0) < 1) {
                throw new \RuntimeException('This apartment has no valid bedroom count.');
            }
            // Price the actual bedrooms cleaned, never the unit's full capacity by default.
            if (!is_scalar($bedroomsRaw) || !ctype_digit((string) $bedroomsRaw)) {
                throw new \RuntimeException('Select the number of bedrooms actually cleaned.');
            }
            $bedrooms = (int) $bedroomsRaw;
            if ($bedrooms < 1 || $bedrooms > (int) $unit['bedrooms']) {
                throw new \RuntimeException('Bedrooms cleaned must be between 1 and the apartment bedroom count.');
            }
            $charge = (new CleaningRateModel())->getRate($bedrooms);
            if ($charge === null) {
                throw new \RuntimeException('No cleaning rate is configured for this apartment.');
            }
            if ($bookingId !== null) {
                $reservation = (new ReservationModel())->find($bookingId);
                if (!$reservation || (int) ($reservation['unit_id'] ?? 0) !== $unitId) {
                    throw new \RuntimeException('Booking number does not belong to the selected apartment.');
                }
            }
            $start = $date . ' 00:00:00';
            $end = (new \DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d') . ' 00:00:00';
            $existing = $db->table('housekeeping')
                ->where('unit_id', $unitId)
                ->where('assigned_staff_id', $staffId)
                ->where('scheduled_at >=', $start)
                ->where('scheduled_at <', $end)
                ->where('status !=', 'cancelled')
                ->countAllResults();
            if ($existing > 0 && $bookingId === null && $reason === '') {
                throw new \RuntimeException('For a second cleaning of this apartment on the same day, provide a booking number or reason.');
            }
            $completedAt = $date . ' 12:00:00';
            $this->guardCleaningChange($db, null, [
                'unit_id' => $unitId, 'completed_at' => $completedAt,
            ]);
            $combinedNotes = $notes;
            if ($reason !== '') {
                $combinedNotes = 'Additional cleaning reason: ' . $reason
                    . ($notes !== '' ? "\n" . $notes : '');
            }
            if (mb_strlen($combinedNotes) > 5000) {
                throw new \RuntimeException('Combined notes exceed 5000 characters.');
            }
            $model = new HousekeepingModel();
            $taskId = $model->insert([
                'unit_id' => $unitId,
                'reservation_id' => $bookingId,
                'assigned_staff_id' => $staffId,
                'verified_by_staff_id' => null,
                'cleaning_type' => $type,
                'scheduled_at' => $completedAt,
                'status' => 'completed',
                'notes' => $combinedNotes,
                'bedrooms_cleaned' => $bedrooms,
                'cleaning_charge' => $charge,
                'charge_adjusted_by' => null,
                'completed_at' => $completedAt,
                'verified_at' => null,
            ], true);
            if (!$taskId) {
                throw new \RuntimeException(implode(' ', $model->errors()) ?: 'Unable to record cleaning.');
            }
            (new \App\Services\HousekeepingExpenseSync())->syncTask((int) $taskId);
            if ($db->transStatus() === false) {
                throw new \RuntimeException('Unable to save cleaning transaction.');
            }
            $db->transCommit();
            return redirect()->to(site_url('housekeeping/my-cleanings/new'))
                ->with('success', 'Cleaning submitted for management verification. Fee: KES ' . number_format((float) $charge, 2));
        } catch (\Throwable $e) {
            $db->transRollback();
            log_message('error', 'Cleaner submission failed: {error}', ['error' => $e->getMessage()]);
            return redirect()->to($back)->withInput()->with('error', $e->getMessage());
        }
    }

    public function create()

    {

        if (!$this->canManage()) {

            return $this->forbidden();

        }



        return haven_view('housekeeping/form', [

            'task' => null,

            'units' => $this->getUnits(),

            'staff' => $this->getStaff(),

            'reservations' => $this->getReservations(),

            'cleaningRates' => (new CleaningRateModel())

                ->getRateOptions(),

        ]);

    }



    /**

     * Create a new housekeeping task.

     */

    public function store()

    {

        if (!$this->canManage()) {

            return $this->forbidden();

        }



        return $this->saveTask(null);

    }



    /**

     * Display the task editing form.

     */

    public function edit($id)

    {

        if (!$this->canManage()) {

            return $this->forbidden();

        }



        $task = (new HousekeepingModel())->find($id);



        if (!$task) {

            throw PageNotFoundException::forPageNotFound(

                'Housekeeping task not found.'

            );

        }



        return haven_view('housekeeping/form', [

            'task' => $task,

            'units' => $this->getUnits(),

            'staff' => $this->getStaff(),

            'reservations' => $this->getReservations(),

            'cleaningRates' => (new CleaningRateModel())

                ->getRateOptions(),

        ]);

    }



    /**

     * Update an existing task.

     */

    public function update($id)

    {

        if (!$this->canManage()) {

            return $this->forbidden();

        }



        return $this->saveTask((int) $id);

    }



    /**

     * Allow an assigned cleaner to update progress and cleaning scope.

     */

    public function updateProgress($id)

    {

        $user = haven_auth()->user();



        if (!$user || !$this->isCleaner()) {

            return $this->forbidden();

        }



        $staffId = $this->currentStaffId();



        if ($staffId === null) {

            return $this->forbidden();

        }



        $model = new HousekeepingModel();



        $task = $model->find((int) $id);



        if (

            !$task ||

            (int) $task['assigned_staff_id'] !== $staffId

        ) {

            return $this->forbidden();

        }



        $status = (string) $this->request->getPost('status');



        $allowedTransitions = [

            'pending' => ['in_progress'],

            'assigned' => ['in_progress'],

            'in_progress' => ['in_progress', 'completed'],

            'completed' => [],

            'verified' => [],

            'cancelled' => [],

        ];



        if (!in_array(

            $status,

            $allowedTransitions[$task['status']] ?? [],

            true

        )) {

            return $this->formError(

                'This cleaning status change is not allowed.'

            );

        }



        $notes = trim(

            (string) $this->request->getPost('notes')

        );



        if (mb_strlen($notes) > 5000) {

            return $this->formError(

                'Notes must not exceed 5000 characters.'

            );

        }



        $unit = (new UnitModel())

            ->find((int) $task['unit_id']);



        if (!$unit) {

            return $this->formError(

                'The apartment linked to this task was not found.'

            );

        }



        $bedroomsInput = $this->request

            ->getPost('bedrooms_cleaned');



        if (

            !is_scalar($bedroomsInput) ||

            !ctype_digit((string) $bedroomsInput)

        ) {

            return $this->formError(

                'Please select the number of bedrooms cleaned.'

            );

        }



        $bedroomsCleaned = (int) $bedroomsInput;



        if (

            $bedroomsCleaned < 1 ||

            $bedroomsCleaned > (int) $unit['bedrooms']

        ) {

            return $this->formError(

                'The bedrooms cleaned cannot exceed the apartment capacity.'

            );

        }



        $existingBedrooms = isset($task['bedrooms_cleaned'])

            ? (int) $task['bedrooms_cleaned']

            : null;



        $existingCharge = $task['cleaning_charge'] ?? null;



        /*

         * Preserve a manager-adjusted charge when the

         * cleaner has not changed the cleaning scope.

         */

        if (

            $existingBedrooms === $bedroomsCleaned &&

            $existingCharge !== null

        ) {

            $charge = (float) $existingCharge;

            $adjustedBy = $task['charge_adjusted_by'] ?? null;

        } else {

            $charge = (new CleaningRateModel())

                ->getRate($bedroomsCleaned);



            if ($charge === null) {

                return $this->formError(

                    'No cleaning rate is configured for this bedroom count.'

                );

            }



            $adjustedBy = null;

        }



        $data = [

            'status' => $status,

            'notes' => $notes,

            'bedrooms_cleaned' => $bedroomsCleaned,

            'cleaning_charge' => $charge,

            'charge_adjusted_by' => $adjustedBy,

        ];



        if ($status === 'completed') {

            $data['completed_at'] = date('Y-m-d H:i:s');

        }



        $db = db_connect();

        $db->transBegin();

        try {

            $unitId = (int)$task['unit_id'];

            $this->lockAccountingUnits($db, [$unitId]);

            $fresh = $db->query('SELECT * FROM housekeeping WHERE id = ? FOR UPDATE', [(int)$id])->getRowArray();

            if (!$fresh || (int)$fresh['assigned_staff_id'] !== $staffId

                || $fresh['status'] !== $task['status']) {

                throw new \RuntimeException('Task changed. Refresh and try again.');

            }

            $this->guardCleaningChange($db, $fresh, $data);

            if (!$model->update((int)$id, $data)) {

                throw new \RuntimeException(implode(' ', $model->errors()) ?: 'Unable to update progress.');

            }

            (new \App\Services\HousekeepingExpenseSync())->syncTask((int)$id);

            if ($db->transStatus() === false) throw new \RuntimeException('Database transaction failed.');

            $db->transCommit();

            return redirect()->to(site_url('housekeeping'))

                ->with('success', 'Cleaning progress and billing updated successfully.');

        } catch (\Throwable $e) {

            $db->transRollback();

            log_message('error', 'Cleaning progress failed: {error}', ['error'=>$e->getMessage()]);

            return $this->formError($e->getMessage());

        }

    }



    /**

     * Handle task creation and management updates.

     */

    private function saveTask(?int $id)

    {

        $model = new HousekeepingModel();

        $unitModel = new UnitModel();

        $staffModel = new StaffModel();

        $reservationModel = new ReservationModel();

        $rateModel = new CleaningRateModel();



        $existing = null;



        if ($id !== null) {

            $existing = $model->find($id);



            if (!$existing) {

                throw PageNotFoundException::forPageNotFound(

                    'Housekeeping task not found.'

                );

            }

        }



        $unitId = (int) $this->request->getPost('unit_id');

        $unit = $unitModel->find($unitId);



        if (!$unit) {

            return $this->formError(

                'Please select a valid apartment.'

            );

        }



        $reservationInput = $this->request

            ->getPost('reservation_id');



        $reservationId = (

            $reservationInput !== null &&

            $reservationInput !== ''

        ) ? (int) $reservationInput : null;



        if ($reservationId !== null) {

            $reservation = $reservationModel->find($reservationId);



            if (

                !$reservation ||

                (int) $reservation['unit_id'] !== $unitId

            ) {

                return $this->formError(

                    'The selected reservation must belong to the apartment.'

                );

            }

        }



        $assignedInput = $this->request

            ->getPost('assigned_staff_id');



        $assignedStaffId = (

            $assignedInput !== null &&

            $assignedInput !== ''

        ) ? (int) $assignedInput : null;



        if (

            $assignedStaffId !== null &&

            !$staffModel->find($assignedStaffId)

        ) {

            return $this->formError(

                'Please select a valid assigned staff member.'

            );

        }



        $cleaningType = (string) $this->request

            ->getPost('cleaning_type');



        if (!in_array($cleaningType, [

            'checkout',

            'pre_checkin',

            'routine',

            'deep',

        ], true)) {

            return $this->formError(

                'Please select a valid cleaning type.'

            );

        }



        $status = (string) $this->request

            ->getPost('status');



        if (!in_array($status, [

            'pending',

            'assigned',

            'in_progress',

            'completed',

            'verified',

            'cancelled',

        ], true)) {

            return $this->formError(

                'Please select a valid housekeeping status.'

            );

        }



        $scheduledInput = trim(

            (string) $this->request->getPost('scheduled_at')

        );



        $scheduled = \DateTimeImmutable::createFromFormat(

            '!Y-m-d\TH:i',

            $scheduledInput

        );



        if (

            !$scheduled ||

            $scheduled->format('Y-m-d\TH:i') !== $scheduledInput

        ) {

            return $this->formError(

                'Please enter a valid scheduled date and time.'

            );

        }



        /*

         * Bedroom count may be selected by a manager or cleaner.

         * Existing scope is retained when the management form

         * does not submit a bedroom count.

         */

        $bedroomsInput = $this->request

            ->getPost('bedrooms_cleaned');



        if ($bedroomsInput === null || $bedroomsInput === '') {

            $bedroomsCleaned = $existing['bedrooms_cleaned'] ?? null;



            if ($bedroomsCleaned !== null) {

                $bedroomsCleaned = (int) $bedroomsCleaned;

            }

        } else {

            if (

                !is_scalar($bedroomsInput) ||

                !ctype_digit((string) $bedroomsInput)

            ) {

                return $this->formError(

                    'Please select a valid bedroom count.'

                );

            }



            $bedroomsCleaned = (int) $bedroomsInput;

        }



        if ($bedroomsCleaned !== null) {

            if (

                $bedroomsCleaned < 1 ||

                $bedroomsCleaned > (int) $unit['bedrooms']

            ) {

                return $this->formError(

                    'Bedrooms cleaned cannot exceed apartment capacity.'

                );

            }

        }



        /*

         * Determine cleaning charge.

         */

        $charge = null;

        $adjustedBy = null;



        if ($bedroomsCleaned !== null) {

            $existingBedrooms = isset($existing['bedrooms_cleaned'])

                ? (int) $existing['bedrooms_cleaned']

                : null;



            if (

                $existing !== null &&

                $existingBedrooms === $bedroomsCleaned &&

                $existing['cleaning_charge'] !== null

            ) {

                $charge = (float) $existing['cleaning_charge'];

                $adjustedBy = $existing['charge_adjusted_by'] ?? null;

            } else {

                $charge = $rateModel->getRate($bedroomsCleaned);



                if ($charge === null) {

                    return $this->formError(

                        'No cleaning rate is configured for this bedroom count.'

                    );

                }

            }



            /*

             * Managers can explicitly override an individual charge.

             */

            $overrideInput = $this->request

                ->getPost('cleaning_charge_override');



            if (

                $overrideInput !== null &&

                $overrideInput !== ''

            ) {

                if (

                    !is_scalar($overrideInput) ||

                    !is_numeric($overrideInput) ||

                    (float) $overrideInput < 0

                ) {

                    return $this->formError(

                        'Please enter a valid cleaning charge.'

                    );

                }



                $currentStaffId = $this->currentStaffId();



                if ($currentStaffId === null) {

                    return $this->formError(

                        'A linked staff account is required to adjust charges.'

                    );

                }



                $charge = round((float) $overrideInput, 2);

                $adjustedBy = $currentStaffId;

            }

        }



        $completedAt = $existing['completed_at'] ?? null;

        $verifiedAt = $existing['verified_at'] ?? null;

        $verifiedByStaffId = $existing['verified_by_staff_id'] ?? null;



        if (in_array($status, ['completed', 'verified'], true)) {

            if (!$completedAt) {

                $completedAt = date('Y-m-d H:i:s');

            }

        } else {

            $completedAt = null;

        }



        /*

         * Verification requires explicit permission.

         */

        if ($status === 'verified') {

            $user = haven_auth()->user();



            if (!$user || !$user->can('housekeeping.verify')) {

                return $this->formError(

                    'You do not have permission to verify cleaning.'

                );

            }



            if (

                $existing === null ||

                $existing['status'] !== 'completed'

            ) {

                return $this->formError(

                    'Only completed tasks can be verified.'

                );

            }



            $currentStaffId = $this->currentStaffId();



            if ($currentStaffId === null) {

                return $this->formError(

                    'Your account must be linked to a staff record to verify.'

                );

            }



            if ($assignedStaffId === $currentStaffId) {

                return $this->formError(

                    'You cannot verify your own cleaning task.'

                );

            }



            $verifiedByStaffId = $currentStaffId;

            $verifiedAt = date('Y-m-d H:i:s');

        } elseif ($existing === null || $existing['status'] !== 'verified') {

            $verifiedAt = null;

            $verifiedByStaffId = null;

        } elseif ($status !== 'verified') {

            return $this->formError(

                'Verified tasks cannot be reopened through this form.'

            );

        }



        $data = [

            'unit_id' => $unitId,

            'reservation_id' => $reservationId,

            'assigned_staff_id' => $assignedStaffId,

            'verified_by_staff_id' => $verifiedByStaffId,

            'cleaning_type' => $cleaningType,

            'scheduled_at' => $scheduled->format('Y-m-d H:i:s'),

            'status' => $status,

            'notes' => trim(

                (string) $this->request->getPost('notes')

            ),

            'bedrooms_cleaned' => $bedroomsCleaned,

            'cleaning_charge' => $charge,

            'charge_adjusted_by' => $adjustedBy,

            'completed_at' => $completedAt,

            'verified_at' => $verifiedAt,

        ];



        $db = db_connect();

        $db->transBegin();

        try {

            $affectedUnits = [$unitId];

            if ($existing !== null) $affectedUnits[] = (int)$existing['unit_id'];

            $this->lockAccountingUnits($db, $affectedUnits);

            if ($id !== null) {

                $fresh = $db->query('SELECT * FROM housekeeping WHERE id = ? FOR UPDATE', [$id])->getRowArray();

                if (!$fresh) throw new \RuntimeException('Task not found.');

                if ($fresh['updated_at'] !== $existing['updated_at']) {

                    throw new \RuntimeException('Task changed. Refresh and try again.');

                }

                $existing = $fresh;

            }

            $this->guardCleaningChange($db, $existing, $data);

            $saved = $id === null ? $model->insert($data) : $model->update($id, $data);

            if ($saved === false) {

                throw new \RuntimeException(implode(' ', $model->errors()) ?: 'Unable to save housekeeping task.');

            }

            $taskId = $id ?? (int)$model->getInsertID();

            (new \App\Services\HousekeepingExpenseSync())->syncTask($taskId);

            if ($db->transStatus() === false) throw new \RuntimeException('Database transaction failed.');

            $db->transCommit();

            return redirect()->to(site_url('housekeeping'))

                ->with('success', $id === null ? 'Housekeeping task created successfully.' : 'Housekeeping task updated successfully.');

        } catch (\Throwable $e) {

            $db->transRollback();

            log_message('error', 'Housekeeping save failed: {error}', ['error'=>$e->getMessage()]);

            return $this->formError($e->getMessage());

        }

    }



    /** Serialize financial changes with statement approval (unit-row locks). */


    /** Verify one or more completed jobs from the earnings dashboard. */
    public function verifyFromEarnings()
    {
        $user = haven_auth()->user();
        if (!$user || $this->isCleaner() || !$user->can('housekeeping.verify') || !$this->canManage()) {
            return redirect()->to(site_url('dashboard'))->with('error', 'You are not authorized to verify cleaning jobs.');
        }
        $staffId = $this->currentStaffId();
        if ($staffId === null) {
            return redirect()->back()->with('error', 'Your login must be linked to a staff record before verification.');
        }
        $rawIds = $this->request->getPost('job_ids');
        if (!is_array($rawIds) || !$rawIds || count($rawIds) > 100) {
            return redirect()->back()->with('error', 'Select between 1 and 100 cleaning jobs to verify.');
        }
        $ids = [];
        foreach ($rawIds as $rawId) {
            if (!is_scalar($rawId) || !ctype_digit((string)$rawId) || (int)$rawId < 1) {
                return redirect()->back()->with('error', 'Invalid cleaning job selection.');
            }
            $ids[] = (int)$rawId;
        }
        $ids = array_values(array_unique($ids));
        sort($ids, SORT_NUMERIC);
        $db = db_connect();
        $db->transBegin();
        try {
            // Lock the affected unit rows first, matching the existing accounting locking order.
            $preflight = $db->table('housekeeping')->select('id, unit_id')->whereIn('id', $ids)->get()->getResultArray();
            if (count($preflight) !== count($ids)) {
                throw new \RuntimeException('A selected cleaning job no longer exists. Refresh and try again.');
            }
            $this->lockAccountingUnits($db, array_column($preflight, 'unit_id'));
            $model = new HousekeepingModel();
            foreach ($ids as $id) {
                $job = $db->query('SELECT * FROM housekeeping WHERE id = ? FOR UPDATE', [$id])->getRowArray();
                if (!$job || $job['status'] !== 'completed' || empty($job['completed_at'])) {
                    throw new \RuntimeException('A selected job is not awaiting verification. Refresh and try again.');
                }
                if ((int)($job['assigned_staff_id'] ?? 0) === $staffId) {
                    throw new \RuntimeException('You cannot verify your own cleaning job.');
                }
                if (empty($job['assigned_staff_id']) || empty($job['bedrooms_cleaned']) || $job['cleaning_charge'] === null) {
                    throw new \RuntimeException('A selected job has missing cleaner, bedroom or fee information.');
                }
                $data = [
                    'status' => 'verified',
                    'verified_by_staff_id' => $staffId,
                    'verified_at' => date('Y-m-d H:i:s'),
                ];
                $this->guardCleaningChange($db, $job, $data + ['unit_id' => (int)$job['unit_id'], 'completed_at' => $job['completed_at']]);
                if (!$model->update($id, $data)) {
                    throw new \RuntimeException(implode(' ', $model->errors()) ?: 'Could not verify cleaning job.');
                }
                (new \App\Services\HousekeepingExpenseSync())->syncTask($id);
            }
            if ($db->transStatus() === false) {
                throw new \RuntimeException('Database transaction failed.');
            }
            $db->transCommit();
            return redirect()->to(site_url('payments/cleaners/earnings') . '?' . http_build_query([
                'month' => $this->request->getPost('month') ?: date('Y-m'),
                'staff_id' => $this->request->getPost('staff_id') ?: '',
            ]))->with('success', count($ids) . ' cleaning job(s) verified successfully.');
        } catch (\Throwable $exception) {
            $db->transRollback();
            log_message('error', 'Cleaner verification failed: {error}', ['error' => $exception->getMessage()]);
            return redirect()->back()->with('error', $exception->getMessage());
        }
    }

    private function lockAccountingUnits($db, array $unitIds): void

    {

        $unitIds = array_values(array_unique(array_map('intval', $unitIds)));

        sort($unitIds, SORT_NUMERIC);

        foreach ($unitIds as $unitId) {

            if (!$db->query('SELECT id FROM units WHERE id = ? FOR UPDATE', [$unitId])->getRowArray()) {

                throw new \RuntimeException('Housekeeping unit not found.');

            }

        }

    }



    /** Protect the month of an existing or newly completed cleaning expense. */

    private function guardCleaningChange($db, ?array $old, array $new): void

    {

        $guard = new \App\Services\AccountingPeriodGuard($db);

        if ($old !== null && !empty($old['completed_at'])) {

            $guard->assertOpen((int)$old['unit_id'], substr((string)$old['completed_at'], 0, 10));

        }

        if (!empty($new['completed_at'])) {

            $guard->assertOpen((int)$new['unit_id'], substr((string)$new['completed_at'], 0, 10));

        }

    }



    /** Synchronize accounting without hiding a successful housekeeping save. */

    private function syncAccountingTask(int $taskId): ?string

    {

        try {

            (new \App\Services\HousekeepingExpenseSync())->syncTask($taskId);

            return null;

        } catch (\Throwable $e) {

            log_message('error', 'Cleaning accounting sync failed for task {id}: {error}', [

                'id' => $taskId, 'error' => $e->getMessage(),

            ]);

            return 'Housekeeping saved, but accounting synchronization failed: ' . $e->getMessage();

        }

    }



    /**

     * Retrieve apartments and property names.

     */

    private function getUnits(): array

    {

        return (new UnitModel())

            ->select('units.*, properties.name AS property_name')

            ->join(

                'properties',

                'properties.id = units.property_id',

                'left'

            )

            ->orderBy('properties.name', 'ASC')

            ->orderBy('units.unit_number', 'ASC')

            ->findAll();

    }



    /**

     * Retrieve staff members.

     */

    private function getStaff(): array

    {

        return (new StaffModel())

            ->orderBy('full_name', 'ASC')

            ->findAll();

    }



    /**

     * Retrieve reservations for linking cleaning tasks.

     */

    private function getReservations(): array

    {

        return (new ReservationModel())

            ->select(

                'reservations.id, ' .

                'reservations.unit_id, ' .

                'reservations.guest_name, ' .

                'reservations.check_in, ' .

                'reservations.check_out'

            )

            ->whereIn('status', [

                'pending',

                'confirmed',

                'checked_in',

                'checked_out',

            ])

            ->orderBy('check_in', 'DESC')

            ->findAll();

    }



    /**

     * Redirect back with validation errors.

     */

    private function formError(string $message)

    {

        return redirect()->back()

            ->withInput()

            ->with('error', $message);

    }

}
