<?php

namespace App\Http\Controllers\Haven;

use App\Models\Haven\StaffModel;
use App\Support\Migration\PageNotFoundException;
use App\Models\User;
use App\Support\Migration\Database;

class StaffAccounts extends BaseController
{
    private array $allowedGroups = [
        'manager',
        'operations',
        'cleaner',
    ];

    public function create($staffId)
    {
        $staffModel = new StaffModel();
        $member = $staffModel->find($staffId);

        if (!$member) {
            throw PageNotFoundException::forPageNotFound(
                'Staff member not found.'
            );
        }

        if (!empty($member['user_id'])) {
            return redirect()->to(site_url('staff'))
                ->with('error', 'This staff member already has a login account.');
        }

        return haven_view('staff/account_form', [
            'member' => $member,
            'groups' => $this->allowedGroups,
        ]);
    }

    public function store($staffId)
    {
        $staffModel = new StaffModel();
        $member = $staffModel->find($staffId);

        if (!$member) {
            throw PageNotFoundException::forPageNotFound(
                'Staff member not found.'
            );
        }

        if (!empty($member['user_id'])) {
            return redirect()->to(site_url('staff'))
                ->with('error', 'This staff member already has a login account.');
        }

        $username = trim((string) $this->request->getPost('username'));
        $email = trim((string) $this->request->getPost('email'));
        $password = (string) $this->request->getPost('password');
        $group = (string) $this->request->getPost('group');

        if (!in_array($group, $this->allowedGroups, true)) {
            return redirect()->back()->withInput(request()->except(['password', 'password_confirmation', 'new_password', 'confirm_password']))
                ->with('error', 'Invalid user role selected.');
        }

        $validation = haven_service('validation');

        $validation->setRules([
            'username' => 'required|min_length[3]|max_length[30]|alpha_dash',
            'email' => 'required|valid_email|max_length[150]',
            'password' => 'required|min_length[5]',
        ]);

        if (!$validation->run([
            'username' => $username,
            'email' => $email,
            'password' => $password,
        ])) {
            return redirect()->back()->withInput(request()->except(['password', 'password_confirmation', 'new_password', 'confirm_password']))
                ->with('error', implode(' ', $validation->getErrors()));
        }

        $users = haven_auth()->getProvider();

        $existing = $users->findByCredentials([
            'email' => $email,
        ]);

        if ($existing) {
            return redirect()->back()->withInput(request()->except(['password', 'password_confirmation', 'new_password', 'confirm_password']))
                ->with('error', 'An account already exists with this email.');
        }

        $db = Database::connect();
        $db->transStart();

        try {
            $user = new User([
                'name' => $member['full_name'],
                'username' => $username,
                'email' => $email,
                'password' => $password,
                'active' => 1,
            ]);

            if (!$users->save($user)) {
                throw new \RuntimeException(
                    implode(' ', $users->errors())
                );
            }

            $userId = $users->getInsertID();
            $createdUser = $users->findById($userId);

            if (!$createdUser) {
                throw new \RuntimeException(
                    'Could not retrieve the newly created user.'
                );
            }

            $createdUser->addGroup($group);

            if (!$staffModel->update($staffId, [
                'user_id' => $userId,
            ])) {
                throw new \RuntimeException(
                    implode(' ', $staffModel->errors())
                );
            }

            $db->transComplete();

            if ($db->transStatus() === false) {
                throw new \RuntimeException(
                    'The account transaction failed.'
                );
            }

            return redirect()->to(site_url('staff'))
                ->with('success', 'Staff login account created successfully.');

        } catch (\Throwable $e) {
            $db->transRollback();

            log_message('error', 'Staff account creation failed: ' . $e->getMessage());

            return redirect()->back()->withInput(request()->except(['password', 'password_confirmation', 'new_password', 'confirm_password']))
                ->with('error', 'Unable to create the staff account. Check the application logs.');
        }
    }
}
