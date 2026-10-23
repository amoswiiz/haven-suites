<?php

namespace App\Controllers;

use App\Models\StaffModel;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Shield\Models\UserModel;

class Staff extends BaseController
{
    public function index()
    {
        $model = new StaffModel();
        return view('staff/index', ['staff' => $model->orderBy('full_name', 'ASC')->findAll()]);
    }

    public function create()
    {
        return view('staff/form', ['member' => null, 'canResetPassword' => false, 'hasLoginAccount' => false]);
    }

    public function store()
    {
        $model = new StaffModel();
        if (!$model->insert($this->formData())) {
            return redirect()->back()->withInput()->with('error', implode(' ', $model->errors()));
        }
        return redirect()->to('/staff')->with('success', 'Staff member added successfully.');
    }

    public function edit($id)
    {
        $model = new StaffModel();
        $member = $model->find($id);
        if (!$member) {
            throw PageNotFoundException::forPageNotFound('Staff member not found.');
        }
        $linkedAccount = !empty($member['user_id'])
            ? model(UserModel::class)->find((int) $member['user_id'])
            : null;
        return view('staff/form', [
            'member' => $member,
            'hasLoginAccount' => $linkedAccount !== null,
            'canResetPassword' => $linkedAccount !== null && $this->canResetStaffPassword(),
        ]);
    }

    public function update($id)
    {
        $model = new StaffModel();
        $member = $model->find($id);
        if (!$member) {
            throw PageNotFoundException::forPageNotFound('Staff member not found.');
        }

        $password = (string) $this->request->getPost('new_password');
        $confirmation = (string) $this->request->getPost('confirm_password');
        $changePassword = $password !== '' || $confirmation !== '';
        $users = null;
        $account = null;

        if ($changePassword) {
            if (!$this->canResetStaffPassword()) {
                return redirect()->back()->withInput()->with('error', 'You are not authorized to reset staff passwords.');
            }
            if (empty($member['user_id'])) {
                return redirect()->back()->withInput()->with('error', 'This staff member is not linked to a login account.');
            }
            $users = model(UserModel::class);
            $account = $users->find((int) $member['user_id']);
            if (!$account) {
                return redirect()->back()->withInput()->with('error', 'The linked login account could not be found.');
            }
            if (strlen($password) < 5 || strlen($password) > 128) {
                return redirect()->back()->withInput()->with('error', 'The new password must contain between 5 and 128 characters.');
            }
            if ($password !== $confirmation) {
                return redirect()->back()->withInput()->with('error', 'The new password and confirmation do not match.');
            }
        }

        $db = db_connect();
        $db->transBegin();
        try {
            if (!$model->update($id, $this->formData())) {
                $db->transRollback();
                return redirect()->back()->withInput()->with('error', implode(' ', $model->errors()));
            }
            if ($changePassword) {
                // Shield's User entity hashes the password when it is assigned.
                $account->password = $password;
                if (!$users->save($account)) {
                    $db->transRollback();
                    return redirect()->back()->withInput()->with('error', 'Unable to update the login password.');
                }
            }
            if ($db->transStatus() === false) {
                throw new \RuntimeException('Database transaction failed.');
            }
            $db->transCommit();
        } catch (\Throwable $e) {
            $db->transRollback();
            log_message('error', 'Staff update failed for staff ID {id}: {error}', ['id' => $id, 'error' => $e->getMessage()]);
            return redirect()->back()->withInput()->with('error', 'Unable to update this staff member.');
        }

        return redirect()->to('/staff')->with('success', $changePassword
            ? 'Staff member and login password updated successfully.'
            : 'Staff member updated successfully.');
    }

    private function canResetStaffPassword(): bool
    {
        $user = auth()->user();
        return $user !== null && (
            $user->can('staff.manage') || $user->inGroup('superadmin', 'admin')
        );
    }

    private function formData(): array
    {
        return [
            'full_name' => trim((string) $this->request->getPost('full_name')),
            'phone' => trim((string) $this->request->getPost('phone')),
            'email' => trim((string) $this->request->getPost('email')),
            'role' => trim((string) $this->request->getPost('role')),
            'location' => trim((string) $this->request->getPost('location')),
            'status' => $this->request->getPost('status'),
            'notes' => trim((string) $this->request->getPost('notes')),
        ];
    }
}
