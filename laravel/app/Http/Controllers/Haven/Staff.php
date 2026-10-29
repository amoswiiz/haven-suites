<?php

namespace App\Http\Controllers\Haven;

use App\Models\Haven\StaffModel;
use App\Support\Migration\PageNotFoundException;
use App\Support\Migration\UserProvider as UserModel;

class Staff extends BaseController
{
    public function index()
    {
        $model = new StaffModel();
        return haven_view('staff/index', ['staff' => $model->orderBy('full_name', 'ASC')->findAll()]);
    }

    public function create()
    {
        return haven_view('staff/form', ['member' => null, 'canResetPassword' => false, 'hasLoginAccount' => false]);
    }

    public function store()
    {
        $model = new StaffModel();
        $data = $this->formData();
        $actor = haven_auth()->user();
        $canCreateLogin = $actor !== null && $actor->can('staff.accounts.manage');
        $back = static function (string $message) {
            return redirect()->back()
                ->withInput(request()->except(['password', 'password_confirmation', 'new_password', 'confirm_password']))
                ->with('error', $message);
        };

        if (!$canCreateLogin) {
            foreach (['username', 'password', 'password_confirmation', 'login_role'] as $field) {
                if (request()->filled($field)) {
                    return $back('You are not authorized to create staff logins.');
                }
            }
            if (!$model->insert($data)) {
                return $back(implode(' ', $model->errors()));
            }
            return redirect()->to(site_url('staff'))->with('success', 'Staff member added successfully.');
        }

        $data['email'] = strtolower($data['email']);
        $roleGroups = [
            'Manager' => 'manager',
            'Operations' => 'operations',
            'Housekeeping' => 'cleaner',
            'Sales' => 'sales',
        ];
        if ($actor->inGroup('superadmin')) {
            $roleGroups['Administrator'] = 'admin';
        }
        if (!isset($roleGroups[$data['role']])) {
            return $back('Select Manager, Operations, Housekeeping or Sales for a staff login. Only a company superadministrator can assign Administrator access. Other job roles do not yet have login permissions configured.');
        }
        $loginGroup = $roleGroups[$data['role']];
        $validator = \Illuminate\Support\Facades\Validator::make([
            'username' => trim((string) request()->input('username')),
            'email' => $data['email'],
            'password' => request()->input('password'),
            'password_confirmation' => request()->input('password_confirmation'),
        ], [
            'username' => ['required', 'string', 'min:3', 'max:30', 'regex:/^[a-zA-Z0-9_-]+$/'],
            'email' => ['required', 'email', 'max:150'],
            'password' => ['required', 'string', 'min:5', 'max:128', 'confirmed'],
        ]);
        if ($validator->fails()) {
            return $back(implode(' ', $validator->errors()->all()));
        }
        $login = $validator->validated();
        if (\App\Models\User::where('email', $login['email'])->exists()) {
            return $back('This email already belongs to a login account. Use a different email.');
        }
        if (\App\Models\User::where('username', $login['username'])->exists()) {
            return $back('This username is already in use.');
        }

        $user = new \App\Models\User([
            'name' => $data['full_name'],
            'username' => $login['username'],
            'email' => $login['email'],
            'password' => $login['password'],
            'active' => $data['status'] === 'active',
        ]);
        $central = $user->getConnection();
        $tenant = db_connect();
        $centralStarted = false;
        $tenantStarted = false;
        try {
            $central->beginTransaction();
            $centralStarted = true;
            $tenant->transBegin();
            $tenantStarted = true;
            $users = model(UserModel::class);
            if (!$users->save($user)) {
                throw new \RuntimeException('Unable to save the login account.');
            }
            $legacyId = (int) $users->getInsertID();
            $account = $users->find($legacyId);
            if (!$account) {
                throw new \RuntimeException('Unable to retrieve the new login account.');
            }
            $account->addGroup($loginGroup);
            $data['user_id'] = $legacyId;
            if (!$model->insert($data)) {
                $message = implode(' ', $model->errors());
                $tenant->transRollback();
                $tenantStarted = false;
                $central->rollBack();
                $centralStarted = false;
                return $back($message);
            }
            if ($tenant->transStatus() === false) {
                throw new \RuntimeException('Staff database transaction failed.');
            }
            $tenant->transCommit();
            $tenantStarted = false;
            $central->commit();
            $centralStarted = false;
        } catch (\Throwable $e) {
            if ($tenantStarted) {
                $tenant->transRollback();
            }
            if ($centralStarted) {
                $central->rollBack();
            }
            log_message('error', 'Staff and login creation failed: {type}', ['type' => get_class($e)]);
            return $back('Unable to save the staff member and login. Please check the application logs.');
        }

        return redirect()->to(site_url('staff'))
            ->with('success', 'Staff member and login created successfully.');
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
        return haven_view('staff/form', [
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

        return redirect()->to(site_url('staff'))->with('success', $changePassword
            ? 'Staff member and login password updated successfully.'
            : 'Staff member updated successfully.');
    }

    private function canResetStaffPassword(): bool
    {
        $user = haven_auth()->user();
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
