<?php

namespace App\Http\Controllers\Haven;

/**
 * Staff-only KYC invitation generator for an existing reservation.
 * IMPORTANT: Register routes behind the existing authenticated/authorized group.
 */
class KycLinks extends BaseController
{
    public function show(int $reservationId)
    {
        $db = db_connect();
        $reservation = $db->table('reservations')->where('id', $reservationId)->get()->getRowArray();
        if (! $reservation) {
            return $this->response->setStatusCode(404)->setContent('Reservation not found.');
        }

        $recent = $db->table('customer_kyc')
            ->select('id, status, submitted_at, created_at')
            ->where('reservation_id', $reservationId)
            ->orderBy('id', 'DESC')->limit(5)->get()->getResultArray();

        $url = haven_session()->getFlashdata('kyc_invitation_url');
        $error = haven_session()->getFlashdata('kyc_invitation_error');
        $action = site_url('reservations/' . $reservationId . '/kyc-link');
        $back = site_url('reservations');
        $guest = esc($reservation['guest_name'] ?? 'Guest');
        $title = 'KYC Invitation — Reservation #' . $reservationId;
        $html = '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>' . esc($title) . '</title><style>body{font:16px system-ui,sans-serif;background:#f3f6fa;color:#172c49;margin:0;padding:36px 16px}'
            . '.card{max-width:690px;margin:auto;background:white;border-radius:14px;padding:28px;box-shadow:0 5px 25px #182b4514}'
            . 'h1{font-size:24px;margin-top:0}button{background:#ee7d23;color:white;border:0;border-radius:7px;padding:12px 20px;font-weight:700;cursor:pointer}'
            . 'input{width:100%;box-sizing:border-box;padding:12px;border:1px solid #ccd6e0;border-radius:6px;margin:10px 0}a{color:#174f83}'
            . '.notice{padding:12px;border-radius:7px;background:#fff3df;margin:16px 0}.error{background:#ffe8e8}'
            . 'table{width:100%;border-collapse:collapse;margin-top:18px}td,th{text-align:left;border-bottom:1px solid #e5eaf0;padding:10px 6px}</style></head><body>'
            . '<main class="card"><p><a href="' . esc($back, 'attr') . '">← Reservations</a></p><h1>' . esc($title) . '</h1>'
            . '<p>Guest: <strong>' . $guest . '</strong></p>';
        if ($error) {
            $html .= '<div class="notice error">' . esc($error) . '</div>';
        }
        if ($url) {
            $html .= '<div class="notice"><strong>New invitation link — copy it now.</strong> For privacy, the full link is shown only once.</div>'
                . '<input aria-label="Guest KYC link" readonly onclick="this.select()" value="' . esc($url, 'attr') . '">'
                . '<p>Send this link privately to the guest via WhatsApp. Do not publish it publicly.</p>';
        }
        $html .= '<form method="post" action="' . esc($action, 'attr') . '">'
            . csrf_field()
            . '<p>Generate a new one-time guest registration link for this reservation. Any older unused link for this reservation will stop working.</p>'
            . '<button type="submit">Generate KYC Link</button></form>'
            . '<h2 style="font-size:18px;margin-top:28px">Recent KYC requests</h2>';
        if ($recent) {
            $html .= '<table><thead><tr><th>ID</th><th>Status</th><th>Submitted</th></tr></thead><tbody>';
            foreach ($recent as $item) {
                $html .= '<tr><td>#' . (int) $item['id'] . '</td><td>' . esc($item['status']) . '</td><td>'
                    . esc($item['submitted_at'] ?: 'Not yet') . '</td></tr>';
            }
            $html .= '</tbody></table>';
        } else {
            $html .= '<p>No KYC requests yet.</p>';
        }
        $html .= '</main></body></html>';
        return $this->response->setContent($html);
    }

    public function generate(int $reservationId)
    {
        $db = db_connect();
        $reservation = $db->table('reservations')->where('id', $reservationId)->get()->getRowArray();
        if (! $reservation) {
            return $this->response->setStatusCode(404)->setContent('Reservation not found.');
        }
        $now = date('Y-m-d H:i:s');
        $token = bin2hex(random_bytes(32));
        $hash = hash('sha256', $token);

        try {
            $db->transBegin();
            $customerId = (int) ($reservation['customer_id'] ?? 0);
            $customer = $customerId > 0 ? $db->table('customers')->where('id', $customerId)->get()->getRowArray() : null;
            if (! $customer) {
                // Provisional customer from reservation. Guest KYC updates official details later.
                $db->table('customers')->insert([
                    'full_name' => trim((string) ($reservation['guest_name'] ?: 'Guest')),
                    'primary_phone' => trim((string) ($reservation['guest_phone'] ?: 'Pending')),
                    'email' => $reservation['guest_email'] ?: null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $customerId = (int) $db->insertID();
                $db->table('reservations')->where('id', $reservationId)->update(['customer_id' => $customerId, 'updated_at' => $now]);
            }

            // Revoke any unused tokens for this reservation, without touching completed KYC.
            $db->table('customer_kyc')->where('reservation_id', $reservationId)
                ->where('submitted_at', null)->where('submission_token_hash !=', null)
                ->update(['submission_token_hash' => null, 'updated_at' => $now]);

            $db->table('customer_kyc')->insert([
                'customer_id' => $customerId,
                'reservation_id' => $reservationId,
                'submission_token_hash' => $hash,
                'status' => 'pending',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            if ($db->transStatus() === false) {
                throw new \RuntimeException('Database transaction failed');
            }
            $db->transCommit();
        } catch (\Throwable $e) {
            $db->transRollback();
            log_message('error', 'KYC invitation generation failed: ' . $e->getMessage());
            return redirect()->to(site_url('reservations/' . $reservationId . '/kyc-link'))
                ->with('kyc_invitation_error', 'Could not create the KYC link. Check that reservations.customer_id exists.');
        }

        return redirect()->to(site_url('reservations/' . $reservationId . '/kyc-link'))
            ->with('kyc_invitation_url', site_url('kyc/' . $token));
    }
}
