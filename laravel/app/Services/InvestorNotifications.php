<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

class InvestorNotifications
{
    public function record(object $investor, string $key, string $type, string $title, string $body, bool $external = true): int
    {
        $key = $investor->tenant_id.':'.$investor->id.':'.$key;

        return DB::transaction(function () use ($investor, $key, $type, $title, $body, $external): int {
            $tenant = Tenant::findOrFail($investor->tenant_id);
            DB::table('investor_events')->insertOrIgnore(['tenant_id' => $investor->tenant_id, 'investor_id' => $investor->id, 'event_key' => $key, 'type' => $type, 'title' => $title, 'body' => $body, 'portal_url' => route('investor.portal', $tenant->slug), 'created_at' => now(), 'updated_at' => now()]);
            $event = DB::table('investor_events')->where('event_key', $key)->firstOrFail();
            if ($external) {
                foreach (['email', 'sms'] as $channel) {
                    DB::table('investor_deliveries')->insertOrIgnore(['tenant_id' => $investor->tenant_id, 'event_id' => $event->id, 'channel' => $channel, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
                }
            }

            return $event->id;
        });
    }

    public function deliver(int $id): void
    {
        $delivery = DB::table('investor_deliveries')->where('id', $id)->first();
        if (! $delivery || ! in_array($delivery->status, ['pending', 'failed', 'blocked'], true) || $delivery->attempts >= 5) {
            return;
        }
        if ($delivery->next_attempt_at && $delivery->next_attempt_at > now()->toDateTimeString()) {
            return;
        }
        $event = DB::table('investor_events')->where('id', $delivery->event_id)->firstOrFail();
        $investor = DB::table('investors')->where('id', $event->investor_id)->where('tenant_id', $delivery->tenant_id)->firstOrFail();
        $tenant = Tenant::findOrFail($delivery->tenant_id);
        $settings = DB::table('investor_notification_settings')->where('tenant_id', $tenant->id)->first();
        $member = $investor->user_id ? User::find($investor->user_id) : null;
        $eligible = $tenant->active && ($tenant->modules['investors'] ?? true) && ($tenant->modules['notifications'] ?? true) && $investor->active && $member?->active && $member->hasVerifiedEmail() && strtolower($member->email) === strtolower($investor->email) && $member->tenants()->whereKey($tenant->id)->exists();
        $membership = $member?->tenants()->whereKey($tenant->id)->first();
        $eligible = $eligible && in_array('owner', json_decode($membership?->pivot->roles ?? '[]', true), true);
        if (! $eligible) {
            $this->status($id, 'blocked', 'Recipient access or verified email unavailable.');

            return;
        }
        $enabled = $delivery->channel === 'email' ? ($settings->email_enabled ?? true) && $investor->email_notifications : ($settings->sms_enabled ?? false) && $investor->sms_notifications && $investor->sms_consented_at && $investor->phone_verified_at && $investor->phone;
        if (! $enabled) {
            $this->status($id, 'skipped', 'Channel disabled or contact consent/verification missing.');

            return;
        }
        if (! DB::table('investor_deliveries')->where('id', $id)->where('status', $delivery->status)->update(['status' => 'processing', 'attempts' => $delivery->attempts + 1, 'updated_at' => now()])) {
            return;
        }
        if (! config('investors.live_delivery')) {
            $this->status($id, 'simulated', 'Live delivery is disabled.');

            return;
        }
        if ($delivery->channel === 'sms') {
            $this->sendSms($delivery, $investor, $event, $tenant);

            return;
        }
        if (in_array(config('mail.default'), ['log', 'array'], true)) {
            $this->status($id, 'simulated', 'Mail transport does not send externally.');

            return;
        }
        try {
            Mail::raw($tenant->name.': '.$event->title."\nA new investor update is available. Sign in securely to view the details: ".$event->portal_url, fn ($message) => $message->to($investor->email)->subject($event->title));
            DB::table('investor_deliveries')->where('id', $id)->update(['status' => 'submitted', 'submitted_at' => now(), 'last_error' => null, 'updated_at' => now()]);
        } catch (\Throwable $exception) {
            report($exception);
            $this->status($id, 'unknown', 'Transport failed; delivery is uncertain. Review before retrying.');
        }
    }

    private function status(int $id, string $status, ?string $error = null): void
    {
        DB::table('investor_deliveries')->where('id', $id)->update(['status' => $status, 'last_error' => $error, 'updated_at' => now()]);
    }

    private function sendSms(object $delivery, object $investor, object $event, Tenant $tenant): void
    {
        $driver = config('investors.sms_driver');
        if ($driver === 'log') {
            $this->status($delivery->id, 'simulated', 'SMS log mode; no message sent.');

            return;
        }
        $message = mb_substr($tenant->name, 0, 50).': You have a new investor update. Sign in securely: '.$event->portal_url;
        try {
            if ($driver === 'roycetalk') {
                $this->sendRoyceTalk($delivery, $investor, $message);

                return;
            } elseif ($driver === 'twilio' && config('investors.twilio_sid') && config('investors.twilio_token') && config('investors.sms_sender')) {
                $response = Http::asForm()->withBasicAuth(config('investors.twilio_sid'), config('investors.twilio_token'))->connectTimeout(5)->timeout(20)->post('https://api.twilio.com/2010-04-01/Accounts/'.rawurlencode(config('investors.twilio_sid')).'/Messages.json', ['To' => $investor->phone, 'From' => config('investors.sms_sender'), 'Body' => $message]);
                $reference = $response->json('sid');
            } elseif ($driver === 'africastalking' && config('investors.sms_api_key') && config('investors.sms_username')) {
                $response = Http::asForm()->acceptJson()->withHeaders(['apiKey' => config('investors.sms_api_key')])->connectTimeout(5)->timeout(20)->post('https://api.africastalking.com/version1/messaging', array_filter(['username' => config('investors.sms_username'), 'to' => $investor->phone, 'message' => $message, 'from' => config('investors.sms_sender')]));
                $reference = $response->json('SMSMessageData.Recipients.0.status') === 'Success' ? $response->json('SMSMessageData.Recipients.0.messageId') : null;
            } else {
                $this->status($delivery->id, 'blocked', 'SMS provider credentials are not configured.');

                return;
            }
            if ($response->status() === 429) {
                DB::table('investor_deliveries')->where('id', $delivery->id)->update(['status' => 'failed', 'last_error' => 'SMS rate limit; retry scheduled.', 'next_attempt_at' => now()->addMinutes(5), 'updated_at' => now()]);

                return;
            }
            if (! $response->successful() || ! $reference) {
                $this->status($delivery->id, $response->serverError() ? 'unknown' : 'failed', 'SMS provider did not confirm acceptance. Review provider logs.');

                return;
            }
            DB::table('investor_deliveries')->where('id', $delivery->id)->update(['status' => 'submitted', 'provider_reference' => mb_substr($reference, 0, 255), 'submitted_at' => now(), 'last_error' => null, 'updated_at' => now()]);
        } catch (\Throwable $exception) {
            $this->status($delivery->id, 'unknown', 'SMS delivery is uncertain. Review the provider before retrying.');
        }
    }

    private function sendRoyceTalk(object $delivery, object $investor, string $message): void
    {
        $transport = app(RoyceTalkSms::class);
        if (! $transport->configured()) {
            $this->status($delivery->id, 'blocked', 'Configure the RoyceTalk API key and approved sender ID.');

            return;
        }
        if (! preg_match('/^\+[1-9][0-9]{7,14}$/', $investor->phone)) {
            $this->status($delivery->id, 'blocked', 'SMS phone number must use international format.');

            return;
        }
        DB::table('investor_deliveries')->where('id', $delivery->id)->update(['sms_provider' => 'roycetalk']);
        $response = $transport->send($investor->phone, $message, 'investor-'.$delivery->tenant_id.'-'.$delivery->id);
        if ($response->status() === 429) {
            DB::table('investor_deliveries')->where('id', $delivery->id)->update(['status' => 'failed', 'last_error' => 'RoyceTalk rate limit; retry scheduled.', 'next_attempt_at' => now()->addMinutes(5), 'updated_at' => now()]);

            return;
        }
        if (in_array($response->status(), [400, 401, 403, 404, 422], true)) {
            $this->status($delivery->id, 'rejected', 'RoyceTalk rejected the request (HTTP '.$response->status().'). Check credentials, sender ID, recipient and SMS balance before retrying.');

            return;
        }
        $reference = $response->json('data.message_id');
        if (! $response->successful() || $response->json('success') !== true || ! is_string($reference) || ! preg_match('/^[a-zA-Z0-9-]{1,100}$/', $reference)) {
            $this->status($delivery->id, 'unknown', 'RoyceTalk acceptance is uncertain. Check provider logs before retrying.');

            return;
        }
        DB::table('investor_deliveries')->where('id', $delivery->id)->update(['status' => 'submitted', 'provider_reference' => $reference, 'provider_status' => 'pending', 'submitted_at' => now(), 'status_checked_at' => null, 'last_error' => null, 'next_attempt_at' => null, 'updated_at' => now()]);
    }
}
