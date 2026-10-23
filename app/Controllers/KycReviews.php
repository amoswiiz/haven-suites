<?php

namespace App\Controllers;

use CodeIgniter\HTTP\ResponseInterface;

/**
 * Staff-only KYC review. Routes must also use the session and permission filters.
 * Identity documents are served from WRITEPATH/private/kyc, never public/.
 */
class KycReviews extends BaseController
{
    private function authorize(bool $manage = false): ?ResponseInterface
    {
        if (! function_exists('auth') || ! auth()->loggedIn()) {
            return $this->response->setStatusCode(403)->setBody('Staff login required.');
        }

        $user = auth()->user();
        $permission = $manage ? 'finance.manage' : 'finance.view';
        if (! $user || ! $user->can($permission)) {
            return $this->response->setStatusCode(403)->setBody('You do not have permission to access KYC records.');
        }

        return null;
    }

    public function index()
    {
        if ($denied = $this->authorize()) {
            return $denied;
        }

        $db = db_connect();
        $status = (string) $this->request->getGet('status');
        $builder = $db->table('customer_kyc k')
            ->select('k.id, k.customer_id, k.reservation_id, k.status, k.submitted_at, k.reviewed_at, c.full_name, c.primary_phone')
            ->join('customers c', 'c.id = k.customer_id', 'left')
            ->where('k.submitted_at IS NOT NULL', null, false);

        if (in_array($status, ['pending', 'approved', 'rejected'], true)) {
            $builder->where('k.status', $status);
        }

        $records = $builder->orderBy('k.submitted_at', 'DESC')->limit(200)->get()->getResultArray();

        return view('kyc/reviews/index', [
            'records' => $records,
            'selectedStatus' => $status,
        ]);
    }

    public function show(int $id)
    {
        if ($denied = $this->authorize()) {
            return $denied;
        }

        $record = db_connect()->table('customer_kyc k')
            ->select('k.*, c.full_name, c.email, c.primary_phone, c.alternative_contact')
            ->join('customers c', 'c.id = k.customer_id', 'left')
            ->where('k.id', $id)
            ->where('k.submitted_at IS NOT NULL', null, false)
            ->get()->getRowArray();

        if (! $record) {
            return $this->response->setStatusCode(404)->setBody('Submitted KYC record not found.');
        }

        return view('kyc/reviews/show', ['record' => $record]);
    }

    public function document(int $id)
    {
        if ($denied = $this->authorize()) {
            return $denied;
        }

        $record = db_connect()->table('customer_kyc')
            ->select('document_storage_key, document_mime_type, submitted_at')
            ->where('id', $id)->get()->getRowArray();

        if (! $record || empty($record['submitted_at'])) {
            return $this->response->setStatusCode(404)->setBody('Document not found.');
        }

        $key = (string) ($record['document_storage_key'] ?? '');
        if (! preg_match('/^[a-f0-9]{48}\.(?:jpg|jpeg|png|pdf)$/i', $key)) {
            return $this->response->setStatusCode(404)->setBody('Document not found.');
        }

        $directory = realpath(WRITEPATH . 'private/kyc');
        $file = $directory ? realpath($directory . DIRECTORY_SEPARATOR . $key) : false;
        if (! $directory || ! $file || dirname($file) !== $directory || ! is_file($file) || ! is_readable($file)) {
            return $this->response->setStatusCode(404)->setBody('Document not found.');
        }

        $mime = (string) ($record['document_mime_type'] ?? '');
        if (! in_array($mime, ['image/jpeg', 'image/png', 'application/pdf'], true)) {
            return $this->response->setStatusCode(415)->setBody('Unsupported document type.');
        }

        $contents = file_get_contents($file);
        if ($contents === false) {
            return $this->response->setStatusCode(500)->setBody('Unable to read document.');
        }

        return $this->response
            ->setHeader('Content-Type', $mime)
            ->setHeader('Content-Disposition', 'inline; filename="kyc-document-' . $id . '"')
            ->setHeader('X-Content-Type-Options', 'nosniff')
            ->setHeader('Cache-Control', 'private, no-store, max-age=0')
            ->setHeader('Pragma', 'no-cache')
            ->setBody($contents);
    }

    public function decide(int $id)
    {
        if ($denied = $this->authorize(true)) {
            return $denied;
        }

        $decision = (string) $this->request->getPost('decision');
        $notes = trim((string) $this->request->getPost('review_notes'));
        if (! in_array($decision, ['approved', 'rejected'], true)) {
            return redirect()->back()->with('error', 'Select Approve or Reject.');
        }
        if (mb_strlen($notes) > 5000 || ($decision === 'rejected' && $notes === '')) {
            return redirect()->back()->with('error', 'A rejection reason is required (maximum 5,000 characters).');
        }

        $db = db_connect();
        $record = $db->table('customer_kyc')->where('id', $id)
            ->where('submitted_at IS NOT NULL', null, false)->get()->getRowArray();
        if (! $record) {
            return $this->response->setStatusCode(404)->setBody('Submitted KYC record not found.');
        }
        if ($record['status'] !== 'pending') {
            return redirect()->to(site_url('staff/kyc/' . $id))->with('error', 'This submission has already been reviewed.');
        }

        $now = date('Y-m-d H:i:s');
        $db->table('customer_kyc')->where('id', $id)->where('status', 'pending')->update([
            'status' => $decision,
            'review_notes' => $notes !== '' ? $notes : null,
            'reviewed_by' => (int) auth()->user()->id,
            'reviewed_at' => $now,
            'updated_at' => $now,
        ]);

        return redirect()->to(site_url('staff/kyc/' . $id))->with('success', 'KYC submission ' . $decision . '.');
    }
}
