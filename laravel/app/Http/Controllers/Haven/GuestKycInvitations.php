<?php

namespace App\Http\Controllers\Haven;

use App\Services\KycInvitationValidity;
use App\Services\RoyceTalkSms;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class GuestKycInvitations extends BaseController
{
    private function authorizeInvitation(): void
    {
        abort_unless(auth()->user()?->can('finance.manage'), 403);
    }

    private function sessionKey(): string
    {
        return 'guest_kyc_invitation.'.app('currentTenant')->id.'.'.auth()->id();
    }

    public function index()
    {
        $this->authorizeInvitation();
        $invitation = session()->get($this->sessionKey());
        if ($invitation && $invitation['expires'] <= time()) {
            session()->forget($this->sessionKey());
            $invitation = null;
        }
        return response()->view('kyc/invite', [
            'invitation' => $invitation,
            'smsReady' => auth()->user()->can('messaging.manage')
                && config('services.roycetalk.manual_live') && app(RoyceTalkSms::class)->configured(),
            'hours' => KycInvitationValidity::HOURS,
        ])->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function generate()
    {
        $this->authorizeInvitation();
        $data = request()->validate([
            'full_name' => ['required', 'string', 'min:3', 'max:180'],
            'phone' => ['required', 'string', 'regex:/^\+[1-9][0-9]{7,14}$/'],
        ]);
        $token = bin2hex(random_bytes(32));
        $db = DB::connection('tenant');
        $now = date('Y-m-d H:i:s');
        $previous = session()->get($this->sessionKey());
        $id = $db->transaction(function () use ($db, $data, $token, $now, $previous) {
            // Revoke only this operator's previous unused invitation for the same contact.
            if ($previous && $previous['phone'] === $data['phone'] && $previous['name'] === trim($data['full_name'])) {
                $db->table('customer_kyc')->where('id', $previous['id'])
                    ->where('submission_token_hash', hash('sha256', $previous['token']))
                    ->whereNull('submitted_at')->update(['submission_token_hash' => null, 'updated_at' => $now]);
            }
            // Never merge guest identities based only on an unverified phone number.
            $customerId = $db->table('customers')->insertGetId([
                'full_name' => trim($data['full_name']), 'primary_phone' => $data['phone'],
                'created_at' => $now, 'updated_at' => $now,
            ]);
            return $db->table('customer_kyc')->insertGetId([
                'customer_id' => $customerId, 'reservation_id' => null,
                'submission_token_hash' => hash('sha256', $token), 'status' => 'pending',
                'created_at' => $now, 'updated_at' => $now,
            ]);
        });
        session()->put($this->sessionKey(), [
            'id' => $id, 'token' => $token, 'name' => trim($data['full_name']), 'phone' => $data['phone'],
            'send_token' => (string) Str::uuid(), 'expires' => strtotime($now) + KycInvitationValidity::HOURS * 3600,
            'url' => rtrim(config('app.url'), '/').'/t/'.rawurlencode(app('currentTenant')->slug).'/kyc/'.$token,
        ]);
        return redirect()->to(site_url('staff/kyc/invite'))->with('success', 'KYC link generated. No SMS has been sent.');
    }

    public function send()
    {
        $this->authorizeInvitation();
        abort_unless(auth()->user()->can('messaging.manage'), 403);
        $data = request()->validate(['send_token' => ['required', 'uuid']]);
        $invitation = session()->get($this->sessionKey());
        abort_unless($invitation && hash_equals($invitation['send_token'], $data['send_token']), 409, 'Generate or reopen your current invitation first.');
        $record = DB::connection('tenant')->table('customer_kyc')->where('id', $invitation['id'])->first();
        if (! $record || ! (new KycInvitationValidity)->usable((array) $record)
            || ! hash_equals((string) $record->submission_token_hash, hash('sha256', $invitation['token']))) {
            return back()->with('error', 'This invitation has expired, was revoked or has already been submitted.');
        }
        $transport = app(RoyceTalkSms::class);
        if (! config('services.roycetalk.manual_live') || ! $transport->configured()) {
            return back()->with('error', 'SMS delivery is disabled or not configured. You can still copy the link.');
        }
        $tenant = app('currentTenant');
        $message = $tenant->name.': Please complete your guest registration: '.$invitation['url']
            .' This private link expires 72 hours after creation. Registration does not confirm a booking.';
        // Keep the access token out of broadly accessible SMS history.
        $inserted = DB::table('manual_sms_messages')->insertOrIgnore([
            'tenant_id' => $tenant->id, 'sent_by' => auth()->id(), 'send_token' => $data['send_token'],
            'phone' => $invitation['phone'], 'body' => 'Guest KYC invitation #'.$invitation['id'].' [private link omitted]',
            'sender_id' => config('services.roycetalk.sender_id'), 'status' => 'processing',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        if (! $inserted) {
            return back()->with('success', 'This SMS request is already recorded. Check SMS history; no duplicate was sent.');
        }
        $id = DB::table('manual_sms_messages')->where('tenant_id', $tenant->id)->where('send_token', $data['send_token'])->value('id');
        $update = ['status' => 'unknown', 'last_error' => 'Acceptance is uncertain. Check RoyceTalk before sending again.'];
        try {
            $response = $transport->send($invitation['phone'], $message, 'manual-'.$tenant->id.'-'.$id);
            $reference = $response->json('data.message_id');
            if ($response->successful() && $response->json('success') === true && is_string($reference)
                && preg_match('/^[a-zA-Z0-9-]{1,100}$/', $reference)) {
                $update = ['status' => 'submitted', 'provider_reference' => $reference,
                    'provider_status' => 'pending', 'submitted_at' => now(), 'last_error' => null];
            } elseif (in_array($response->status(), [400, 401, 403, 404, 422, 429], true)) {
                $update = ['status' => 'rejected', 'last_error' => 'SMS provider rejected the request. Check the phone, sender, balance and rate limit.'];
            }
        } catch (\Throwable $exception) {
            // Do not log the provider request or its private KYC URL.
        }
        DB::table('manual_sms_messages')->where('tenant_id', $tenant->id)->where('id', $id)
            ->update($update + ['updated_at' => now()]);
        return back()->with($update['status'] === 'submitted' ? 'success' : 'error',
            $update['status'] === 'submitted' ? 'KYC SMS submitted to RoyceTalk. Check SMS history for delivery confirmation.' : $update['last_error']);
    }
}
