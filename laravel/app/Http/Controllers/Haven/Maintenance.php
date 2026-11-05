<?php

namespace App\Http\Controllers\Haven;

use App\Models\Haven\MaintenanceModel;
use App\Models\Haven\StaffModel;
use App\Models\Haven\UnitModel;
use App\Support\Migration\PageNotFoundException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class Maintenance extends BaseController
{
    private const REPORT_CATEGORIES = [
        'plumbing' => 'Plumbing',
        'electrical' => 'Electrical',
        'air_conditioning' => 'Air conditioning',
        'internet' => 'Internet / Wi-Fi',
        'appliances' => 'Appliances',
        'furniture' => 'Furniture',
        'cleaning' => 'Cleaning',
        'painting' => 'Painting',
        'security' => 'Security',
        'general' => 'General maintenance',
        'other' => 'Other',
    ];

    private function authorizeMaintenance(string $permission): void
    {
        abort_unless(auth()->user()?->active && auth()->user()->can($permission), 403);
    }

    public function report(): View
    {
        $this->authorizeMaintenance('maintenance.report');

        return view('maintenance.report', [
            'pageTitle' => 'Report maintenance',
            'units' => $this->getUnits(),
            'categories' => self::REPORT_CATEGORIES,
        ]);
    }

    public function storeReport(): RedirectResponse
    {
        $this->authorizeMaintenance('maintenance.report');
        $data = request()->validate([
            'unit_id' => ['required', 'integer', 'min:1', Rule::exists('tenant.units', 'id')],
            'title' => 'required|string|max:180',
            'category' => ['required', 'string', Rule::in(array_keys(self::REPORT_CATEGORIES))],
            'description' => 'required|string|max:5000',
            'priority' => ['required', 'string', Rule::in(['low', 'medium', 'high', 'urgent'])],
        ]);

        try {
            $saved = (new MaintenanceModel)->insert($data + [
                'status' => 'open',
                'cost' => 0,
                'assigned_staff_id' => null,
                'provider_name' => null,
                'provider_phone' => null,
                'work_notes' => null,
                'completed_at' => null,
                'reported_at' => now()->format('Y-m-d H:i:s'),
                'reported_by_user_id' => auth()->id(),
                'reported_by_name' => mb_substr(auth()->user()->name, 0, 255),
            ]);
            if ($saved === false) {
                throw new \RuntimeException('Maintenance report could not be saved.');
            }
        } catch (\Throwable $e) {
            log_message('error', 'Maintenance report failed: {error}', ['error' => $e->getMessage()]);

            return $this->formError('Unable to submit your report. Please try again or contact your manager.');
        }

        return redirect()->route('maintenance.index', app('currentTenant')->slug)
            ->with('success', 'Maintenance issue reported. Your team can now review it and arrange the repair.');
    }

    private function reporterIndex(): View
    {
        $filters = request()->validate([
            'unit_id' => ['nullable', 'integer', 'min:1', Rule::exists('tenant.units', 'id')],
            'activity' => ['nullable', Rule::in(['open', 'all'])],
        ]);
        $issues = DB::connection('tenant')->table('maintenance as m')
            ->leftJoin('units as u', 'u.id', '=', 'm.unit_id')
            ->leftJoin('properties as p', 'p.id', '=', 'u.property_id')
            ->select('m.id', 'm.unit_id', 'm.title', 'm.category', 'm.description', 'm.priority', 'm.status',
                'm.reported_at', 'm.completed_at', 'm.reported_by_user_id', 'm.reported_by_name', 'u.unit_number', 'p.name as property_name')
            ->when(! empty($filters['unit_id']), fn ($query) => $query->where('m.unit_id', $filters['unit_id']))
            ->when(($filters['activity'] ?? '') === 'open', fn ($query) => $query->whereIn('m.status', ['open', 'assigned', 'in_progress', 'on_hold']))
            ->orderByDesc('m.reported_at')->orderByDesc('m.id')->paginate(20)->withQueryString();

        return view('maintenance.reporter_index', ['pageTitle' => 'Maintenance issues', 'issues' => $issues, 'units' => $this->getUnits()]);
    }

    /**
     * Display all maintenance issues.
     */
    public function index()
    {
        $this->authorizeMaintenance('maintenance.view');
        if (! auth()->user()->can('maintenance.manage')) {
            return $this->reporterIndex();
        }

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
            'pageTitle' => 'Maintenance',
            'maintenance' => $maintenance,
        ]);
    }

    /**
     * Display the Add Maintenance form.
     */
    public function create()
    {
        $this->authorizeMaintenance('maintenance.manage');

        return haven_view('maintenance/form', [
            'pageTitle' => 'Report maintenance',
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
        $this->authorizeMaintenance('maintenance.manage');

        $model = new MaintenanceModel;

        $issue = $model->find($id);

        if (! $issue) {
            throw PageNotFoundException::forPageNotFound(
                'Maintenance issue not found.'
            );
        }

        return haven_view('maintenance/form', [
            'pageTitle' => 'Edit maintenance issue',
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
        $this->authorizeMaintenance('maintenance.manage');

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
        if ($id === null) {
            $data['reported_by_user_id'] = auth()->id();
            $data['reported_by_name'] = mb_substr(auth()->user()->name, 0, 255);
        }

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
                'units.id, units.unit_number, properties.name AS property_name'
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
