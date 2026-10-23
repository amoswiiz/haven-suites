<?php

namespace App\Controllers;

use CodeIgniter\HTTP\RedirectResponse;

/**
 * Public guest KYC entrypoint. Only a cryptographically random booking token
 * (stored as a SHA-256 hash) permits access. Do not add this controller to
 * authenticated route groups; its access is token-based.
 */
class Kyc extends BaseController
{
    private function submission(string $token): ?array
    {
        if (! preg_match('/^[a-f0-9]{64}$/i', $token)) {
            return null;
        }

        $db = db_connect();
        return $db->table('customer_kyc')
            ->where('submission_token_hash', hash('sha256', strtolower($token)))
            ->where('submitted_at', null)
            ->get()->getRowArray() ?: null;
    }

    public function form(string $token)
    {
        $submission = $this->submission($token);
        if (! $submission || empty($submission['reservation_id'])) {
            return $this->response->setStatusCode(404)->setBody('This KYC link is invalid or has already been used. Please contact Haven Suites.');
        }

        $reservation = db_connect()->table('reservations')->where('id', $submission['reservation_id'])->get()->getRowArray();
        if (! $reservation) {
            return $this->response->setStatusCode(404)->setBody('Reservation not found.');
        }

        return view('kyc/form', [
            'token' => $token,
            'reservation' => $reservation,
            'validationErrors' => session()->getFlashdata('kyc_errors') ?? [],
        ]);
    }

    public function submit(string $token): RedirectResponse
    {
        $submission = $this->submission($token);
        if (! $submission || empty($submission['reservation_id'])) {
            return redirect()->to(site_url('kyc/invalid'));
        }

        $rules = [
            'full_name' => 'required|min_length[3]|max_length[180]',
            'email' => 'permit_empty|valid_email|max_length[180]',
            'primary_phone' => 'required|min_length[7]|max_length[40]',
            'alternative_contact' => 'permit_empty|max_length[180]',
            'document_type' => 'required|in_list[national_id,passport,alien_id,driving_licence]',
            'identity_document' => 'uploaded[identity_document]|max_size[identity_document,5120]|ext_in[identity_document,jpg,jpeg,png,pdf]|mime_in[identity_document,image/jpeg,image/png,application/pdf]',
        ];

        if (! $this->validate($rules)) {
            return redirect()->back()->withInput()->with('kyc_errors', $this->validator->getErrors());
        }

        $file = $this->request->getFile('identity_document');
        if (! $file || ! $file->isValid() || $file->hasMoved()) {
            return redirect()->back()->withInput()->with('kyc_errors', ['identity_document' => 'Please upload a valid identification document.']);
        }

        $db = db_connect();
        $reservation = $db->table('reservations')->where('id', $submission['reservation_id'])->get()->getRowArray();
        if (! $reservation) {
            return redirect()->back()->with('kyc_errors', ['reservation' => 'Reservation no longer exists.']);
        }

        $storageDir = WRITEPATH . 'private/kyc';
        if (! is_dir($storageDir) && ! mkdir($storageDir, 0700, true) && ! is_dir($storageDir)) {
            return redirect()->back()->with('kyc_errors', ['identity_document' => 'Secure storage is unavailable. Please try again later.']);
        }

        $storageName = bin2hex(random_bytes(24)) . '.' . strtolower($file->getExtension());
        $originalName = substr($file->getClientName(), 0, 255);
        $mime = $file->getMimeType();
        try {
            $file->move($storageDir, $storageName);
        } catch (\Throwable $e) {
            log_message('error', 'KYC upload failed: ' . $e->getMessage());
            return redirect()->back()->with('kyc_errors', ['identity_document' => 'Upload failed. Please try again.']);
        }

        $path = $storageDir . DIRECTORY_SEPARATOR . $storageName;
        @chmod($path, 0600);
        $now = date('Y-m-d H:i:s');
        $name = trim((string) $this->request->getPost('full_name'));
        $phone = trim((string) $this->request->getPost('primary_phone'));
        $email = trim((string) $this->request->getPost('email'));
        $alternative = trim((string) $this->request->getPost('alternative_contact'));

        try {
            $db->transBegin();
            // Prefer a customer already attached to the reservation. Do not
            // merge customers based on a phone number alone.
            $customerId = ! empty($reservation['customer_id']) ? (int) $reservation['customer_id'] : null;
            $customer = $customerId ? $db->table('customers')->where('id', $customerId)->get()->getRowArray() : null;
            $customerData = [
                'full_name' => $name,
                'primary_phone' => $phone,
                'email' => $email !== '' ? $email : null,
                'alternative_contact' => $alternative !== '' ? $alternative : null,
                'updated_at' => $now,
            ];
            if ($customer) {
                $db->table('customers')->where('id', $customerId)->update($customerData);
            } else {
                $customerData['created_at'] = $now;
                $db->table('customers')->insert($customerData);
                $customerId = (int) $db->insertID();
            }

            $db->table('customer_kyc')->where('id', $submission['id'])->where('submitted_at', null)->update([
                'customer_id' => $customerId,
                'document_type' => $this->request->getPost('document_type'),
                'document_storage_key' => $storageName,
                'document_original_name' => $originalName,
                'document_mime_type' => $mime,
                'status' => 'pending',
                'submitted_at' => $now,
                'updated_at' => $now,
            ]);
            $db->table('reservations')->where('id', $submission['reservation_id'])->update([
                'customer_id' => $customerId,
                'guest_name' => $name,
                'guest_phone' => $phone,
                'guest_email' => $email !== '' ? $email : null,
                'updated_at' => $now,
            ]);

            if ($db->transStatus() === false) {
                throw new \RuntimeException('Database transaction failed');
            }
            $db->transCommit();
        } catch (\Throwable $e) {
            $db->transRollback();
            @unlink($path);
            log_message('error', 'KYC save failed: ' . $e->getMessage());
            return redirect()->back()->with('kyc_errors', ['general' => 'We could not save your details. Please contact Haven Suites.']);
        }

        return redirect()->to(site_url('kyc/thank-you'));
    }

    public function thankYou()
    {
        return $this->response->setBody('Thank you. Your Haven Suites registration has been submitted for review.');
    }

    public function invalid()
    {
        return $this->response->setStatusCode(404)->setBody('This KYC link is invalid or has already been used. Please contact Haven Suites.');
    }
}
