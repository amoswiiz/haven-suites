<?php

namespace App\Http\Controllers\Haven;

use App\Models\Haven\MaintenanceModel;
use App\Models\Haven\StaffModel;
use App\Models\Haven\UnitModel;
use App\Support\Migration\PageNotFoundException;

class Maintenance extends BaseController
{
    /**
     * Display all maintenance issues.
     */
    public function index()
    {
        $model = new MaintenanceModel;
        if (request()->filled('unit_id')) {
            request()->validate(['unit_id' => 'required|integer|min:1']);
            $model->where('maintenance.unit_id', request()->integer('unit_id'));
        }
        if ($this->request->getGet('activity') === 'open') {
            $model->whereIn('maintenance.status', ['open', 'assigned', 'in_progress', 'on_hold']);
        }

        $maintenance = $model
            ->select(
                'maintenance.*, '.
                'units.unit_number, '.
                'properties.name AS property_name, '.
                'staff.full_name AS assigned_staff_name'
            )
            ->join(
                'units',
                'units.id = maintenance.unit_id',
                'left'
            )
            ->join(
                'properties',
                'properties.id = units.property_id',
                'left'
            )
            ->join(
                'staff',
                'staff.id = maintenance.assigned_staff_id',
                'left'
            )
            ->orderBy('maintenance.reported_at', 'DESC')
            ->findAll();

        return haven_view('maintenance/index', [
            'maintenance' => $maintenance,
        ]);
    }

    /**
     * Display the Add Maintenance form.
     */
    public function create()
    {
        return haven_view('maintenance/form', [
            'issue' => null,
            'units' => $this->getUnits(),
            'staff' => $this->getStaff(),
        ]);
    }

    /**
     * Save a new maintenance issue.
     */
    public function store()
    {
        return $this->saveIssue(null);
    }

    /**
     * Display the Edit Maintenance form.
     */
    public function edit($id)
    {
        $model = new MaintenanceModel;

        $issue = $model->find($id);

        if (! $issue) {
            throw PageNotFoundException::forPageNotFound(
                'Maintenance issue not found.'
            );
        }

        return haven_view('maintenance/form', [
            'issue' => $issue,
            'units' => $this->getUnits(),
            'staff' => $this->getStaff(),
        ]);
    }

    /**
     * Update an existing maintenance issue.
     */
    public function update($id)
    {
        return $this->saveIssue((int) $id);
    }

    /**
     * Handle both creating and updating maintenance issues.
     */
    private function saveIssue(?int $id)
    {
        $model = new MaintenanceModel;
        $unitModel = new UnitModel;
        $staffModel = new StaffModel;

        if ($id !== null && ! $model->find($id)) {
            throw PageNotFoundException::forPageNotFound(
                'Maintenance issue not found.'
            );
        }

        $unitId = (int) $this->request->getPost('unit_id');

        if (! $unitModel->find($unitId)) {
            return $this->formError(
                'Please select a valid apartment.'
            );
        }

        $assignedStaffInput = $this->request
            ->getPost('assigned_staff_id');

        $assignedStaffId = (
            $assignedStaffInput !== null &&
            $assignedStaffInput !== ''
        )
            ? (int) $assignedStaffInput
            : null;

        if (
            $assignedStaffId !== null &&
            ! $staffModel->find($assignedStaffId)
        ) {
            return $this->formError(
                'Please select a valid staff member.'
            );
        }

        $title = trim(
            (string) $this->request->getPost('title')
        );

        $description = trim(
            (string) $this->request->getPost('description')
        );

        if ($title === '' || $description === '') {
            return $this->formError(
                'Issue title and description are required.'
            );
        }

        $priority = (string) $this->request
            ->getPost('priority');

        if (! in_array($priority, [
            'low',
            'medium',
            'high',
            'urgent',
        ], true)) {
            return $this->formError(
                'Please select a valid priority.'
            );
        }

        $status = (string) $this->request
            ->getPost('status');

        if (! in_array($status, [
            'open',
            'assigned',
            'in_progress',
            'on_hold',
            'completed',
            'cancelled',
        ], true)) {
            return $this->formError(
                'Please select a valid maintenance status.'
            );
        }

        $costInput = $this->request->getPost('cost');

        $cost = (
            $costInput === null ||
            $costInput === ''
        ) ? 0 : $costInput;

        if (
            ! is_numeric($cost) ||
            (float) $cost < 0 ||
            ! is_finite((float) $cost)
        ) {
            return $this->formError(
                'Please enter a valid maintenance cost.'
            );
        }

        $existing = $id !== null
            ? $model->find($id)
            : null;

        $completedAt = $existing['completed_at'] ?? null;

        if ($status === 'completed' && ! $completedAt) {
            $completedAt = date('Y-m-d H:i:s');
        }

        if ($status !== 'completed') {
            $completedAt = null;
        }

        $data = [
            'unit_id' => $unitId,
            'assigned_staff_id' => $assignedStaffId,
            'title' => $title,
            'category' => trim(
                (string) $this->request->getPost('category')
            ),
            'description' => $description,
            'priority' => $priority,
            'status' => $status,
            'provider_name' => trim(
                (string) $this->request->getPost('provider_name')
            ),
            'provider_phone' => trim(
                (string) $this->request->getPost('provider_phone')
            ),
            'work_notes' => trim(
                (string) $this->request->getPost('work_notes')
            ),
            'cost' => (float) $cost,
            'reported_at' => $existing['reported_at']
                ?? date('Y-m-d H:i:s'),
            'completed_at' => $completedAt,
        ];

        try {
            $saved = $id === null
                ? $model->insert($data)
                : $model->update($id, $data);
        } catch (\Throwable $e) {
            log_message('error', 'Maintenance save failed: {error}', [
                'error' => $e->getMessage(),
            ]);

            return $this->formError(
                'Unable to save the maintenance issue.'
            );
        }

        if ($saved === false) {
            return $this->formError(
                implode(' ', $model->errors())
                ?: 'Unable to save the maintenance issue.'
            );
        }

        return redirect()->to(site_url('maintenance'))
            ->with(
                'success',
                $id === null
                    ? 'Maintenance issue created successfully.'
                    : 'Maintenance issue updated successfully.'
            );
    }

    /**
     * Retrieve apartment units with property names.
     */
    private function getUnits(): array
    {
        return (new UnitModel)
            ->select(
                'units.*, properties.name AS property_name'
            )
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
     * Retrieve internal staff members.
     */
    private function getStaff(): array
    {
        return (new StaffModel)
            ->where('status', 'active')
            ->orderBy('full_name', 'ASC')
            ->findAll();
    }

    /**
     * Return to the form with a validation error.
     */
    private function formError(string $message)
    {
        return redirect()->back()
            ->withInput()
            ->with('error', $message);
    }
}
