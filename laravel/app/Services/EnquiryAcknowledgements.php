<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\User;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class EnquiryAcknowledgements
{
    public const STAGE = 'enquiry_acknowledgement';

    public function phone(?string $value): ?string
    {
        return PhoneNumber::normalize($value);
    }

    /** Most acknowledgement SMS (the original plus resends) per enquiry. */
    public const MAX_SENDS = 3;

    /** The latest acknowledgement SMS for the enquiry, including any resend to a corrected number. */
    public function status(int $tenantId, int $enquiryId): ?object
    {
        return DB::table('manual_sms_messages')->where('tenant_id', $tenantId)->where('enquiry_id', $enquiryId)
            ->where(fn ($query) => $query->where('communication_stage', self::STAGE)->orWhere('communication_stage', 'like', self::STAGE.'_resend_%'))
            ->orderByDesc('id')->first();
    }

    /**
     * Why the acknowledgement cannot be resent now, or null when staff may resend it to the enquiry's current number.
     *
     * Resending is for a corrected number or a failed send; it never repeats a message to the same number.
     */
    public function resendBlockedReason(Tenant $tenant, object $lead): ?string
    {
        $phone = $this->phone($lead->phone);
        $latest = $this->status($tenant->id, (int) $lead->id);
        $sends = DB::table('manual_sms_messages')->where('tenant_id', $tenant->id)->where('enquiry_id', $lead->id)
            ->where(fn ($query) => $query->where('communication_stage', self::STAGE)->orWhere('communication_stage', 'like', self::STAGE.'_resend_%'))->count();

        return match (true) {
            $phone === null => 'Add a valid mobile number to send the acknowledgement by SMS.',
            $sends >= self::MAX_SENDS => 'The acknowledgement has already been sent '.self::MAX_SENDS.' times. Contact the guest directly.',
            ($tenant->modules['messaging'] ?? true) === false => 'Messaging is disabled for this company.',
            ! config('services.roycetalk.manual_live') || ! app(RoyceTalkSms::class)->configured() => 'SMS delivery is disabled or the provider is not configured.',
            $latest !== null && $latest->phone === $phone && ! in_array($latest->status, ['skipped', 'rejected', 'undelivered'], true) => 'The acknowledgement was already sent to this number. Correct the number first if it was wrong.',
            default => null,
        };
    }

    /** Send the acknowledgement again (same reference and tracking link) to the enquiry's corrected mobile number. */
    public function resend(Tenant $tenant, int $enquiryId, User $actor): void
    {
        abort_unless($tenant->active && $actor->active && app('currentTenant')->id === $tenant->id && $actor->can('sales.manage'), 403);
        $record = DB::transaction(function () use ($tenant, $enquiryId, $actor): object {
            $lead = DB::table('sales_leads')->where('tenant_id', $tenant->id)->where('id', $enquiryId)->lockForUpdate()->firstOrFail();
            if ($reason = $this->resendBlockedReason($tenant, $lead)) {
                throw ValidationException::withMessages(['acknowledgement' => $reason]);
            }
            $attempt = DB::table('manual_sms_messages')->where('tenant_id', $tenant->id)->where('enquiry_id', $enquiryId)
                ->where(fn ($query) => $query->where('communication_stage', self::STAGE)->orWhere('communication_stage', 'like', self::STAGE.'_resend_%'))->count() + 1;
            $id = DB::table('manual_sms_messages')->insertGetId([
                'tenant_id' => $tenant->id, 'sent_by' => $actor->id, 'enquiry_id' => $enquiryId,
                'send_token' => (string) Str::uuid(), 'communication_stage' => self::STAGE.'_resend_'.$attempt,
                'phone' => $this->phone($lead->phone), 'body' => $this->body($tenant, $lead), 'sender_id' => config('services.roycetalk.sender_id') ?: '',
                'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->activity($tenant->id, $enquiryId, (int) $actor->id, 'Acknowledgement SMS resent to the corrected number '.$this->phone($lead->phone).'.');

            return DB::table('manual_sms_messages')->find($id);
        });

        $this->dispatch($tenant, $record, (int) $actor->id);
    }

    /** The acknowledgement text: greeting, enquiry reference and private tracking link. */
    private function body(Tenant $tenant, object $lead): string
    {
        $tracking = app(EnquiryTracking::class)->issue($tenant, $lead);
        $firstName = mb_substr((string) preg_replace('/[^\p{L}\'-]/u', '', explode(' ', trim((string) $lead->name))[0] ?? ''), 0, 20);

        return ($firstName !== '' ? 'Hi '.$firstName.', thanks' : 'Thank you').' for contacting '.$tenant->name.'! Your enquiry ref is '
            .$tracking['reference'].'. Track its progress here: '.$tracking['url'];
    }

    public function prepare(Tenant $tenant, int $enquiryId, User $actor): object
    {
        abort_unless($tenant->active && $actor->active && app('currentTenant')->id === $tenant->id && $actor->can('sales.manage'), 403);
        $lead = DB::table('sales_leads')->where('tenant_id', $tenant->id)->where('id', $enquiryId)->firstOrFail();

        return $this->prepareIntent($tenant, $lead, (int) $actor->id);
    }

    public function preparePublic(Tenant $tenant, int $enquiryId): object
    {
        abort_unless($tenant->active && ($tenant->modules['sales'] ?? true) && ($tenant->modules['properties'] ?? true)
            && DB::table('public_sites')->where('tenant_id', $tenant->id)->where('enabled', true)->exists(), 404);
        $lead = DB::table('sales_leads')->where('tenant_id', $tenant->id)->where('id', $enquiryId)
            ->where('public_origin', 'website')->whereNotNull('public_listing_id')->firstOrFail();
        abort_unless(DB::table('public_enquiry_receipts')->where('tenant_id', $tenant->id)->where('lead_id', $enquiryId)->exists(), 404);
        if ($record = $this->status($tenant->id, $enquiryId)) {
            return $record;
        }
        $phone = $this->phone($lead->phone);
        $recent = $phone !== null && DB::table('manual_sms_messages')->where('tenant_id', $tenant->id)
            ->where('phone', $phone)->where('communication_stage', self::STAGE)->where('enquiry_id', '!=', $enquiryId)
            ->where('created_at', '>=', now()->subMinutes(10))->whereIn('status', ['pending', 'processing', 'submitted', 'delivered', 'unknown', 'rejected', 'undelivered'])->exists();

        return $this->prepareIntent($tenant, $lead, null, $recent
            ? 'An acknowledgement was already recorded for this number recently. The team will follow up with the new enquiry.' : null);
    }

    private function prepareIntent(Tenant $tenant, object $lead, ?int $actorId, ?string $blockedReason = null): object
    {
        $enquiryId = (int) $lead->id;
        if ($existing = $this->status($tenant->id, $enquiryId)) {
            return $existing;
        }
        $phone = $this->phone($lead->phone);
        $transport = app(RoyceTalkSms::class);
        $skipped = match (true) {
            $blockedReason !== null => $blockedReason,
            ! filled($lead->phone) => 'No phone number was supplied. Follow up using the recorded email address.',
            $phone === null => 'The phone number could not be used for SMS. Add a valid mobile number and contact the guest separately.',
            ($tenant->modules['messaging'] ?? true) === false => 'SMS was not sent because messaging is disabled for this company.',
            ! config('services.roycetalk.manual_live') || ! $transport->configured() => 'SMS delivery is disabled or the provider is not configured. Follow up with the guest separately.',
            default => null,
        };
        $body = $this->body($tenant, $lead);
        $inserted = DB::table('manual_sms_messages')->insertOrIgnore([
            'tenant_id' => $tenant->id, 'sent_by' => $actorId, 'enquiry_id' => $enquiryId,
            'send_token' => (string) Str::uuid(), 'communication_stage' => self::STAGE,
            'phone' => $phone ?? '', 'body' => $body, 'sender_id' => config('services.roycetalk.sender_id') ?: '',
            'status' => $skipped === null ? 'pending' : 'skipped', 'last_error' => $skipped,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $record = $this->status($tenant->id, $enquiryId);
        if ($inserted && $skipped !== null) {
            $this->activity($tenant->id, $enquiryId, $actorId, 'Acknowledgement SMS not sent. '.$skipped);
        }

        return $record ?? throw new \RuntimeException('The acknowledgement SMS intent could not be saved.');
    }

    public function send(Tenant $tenant, int $enquiryId, User $actor): void
    {
        $record = $this->prepare($tenant, $enquiryId, $actor);

        $this->dispatch($tenant, $record, (int) $actor->id);
    }

    public function sendPublic(Tenant $tenant, int $enquiryId): void
    {
        $this->dispatch($tenant, $this->preparePublic($tenant, $enquiryId), null);
    }

    private function dispatch(Tenant $tenant, object $record, ?int $actorId): void
    {
        $enquiryId = (int) $record->enquiry_id;
        if ($record->status !== 'pending') {
            return;
        }
        $transport = app(RoyceTalkSms::class);
        $disabled = ($tenant->modules['messaging'] ?? true) === false || ! config('services.roycetalk.manual_live') || ! $transport->configured();
        if ($disabled) {
            $reason = 'SMS delivery became unavailable before submission. Follow up with the guest separately.';
            $skipped = DB::table('manual_sms_messages')->where('tenant_id', $tenant->id)->where('id', $record->id)->where('status', 'pending')
                ->update(['status' => 'skipped', 'last_error' => $reason, 'updated_at' => now()]);
            if ($skipped) {
                $this->activity($tenant->id, $enquiryId, $actorId, 'Acknowledgement SMS not sent. '.$reason);
            }

            return;
        }
        $claimed = DB::table('manual_sms_messages')->where('tenant_id', $tenant->id)->where('id', $record->id)->where('status', 'pending')
            ->update(['status' => 'processing', 'sender_id' => config('services.roycetalk.sender_id'), 'last_error' => null, 'updated_at' => now()]);
        if (! $claimed) {
            return;
        }
        $update = ['updated_at' => now()];
        try {
            $response = $transport->send($record->phone, $record->body, 'enquiry-'.$tenant->id.'-'.$record->id);
            $reference = $response->json('data.message_id');
            if ($response->successful() && $response->json('success') === true && is_string($reference) && preg_match('/^[a-zA-Z0-9-]{1,100}$/D', $reference)) {
                $update += ['status' => 'submitted', 'provider_reference' => $reference, 'provider_status' => 'pending', 'submitted_at' => now(), 'last_error' => null];
            } elseif (in_array($response->status(), [400, 401, 403, 404, 422, 429], true)) {
                $update += ['status' => 'rejected', 'last_error' => 'The SMS provider rejected the request. Check the sender, number, balance or rate limit before contacting the guest separately.'];
            } else {
                $update += ['status' => 'unknown', 'last_error' => 'SMS acceptance is uncertain. Check RoyceTalk before sending another acknowledgement.'];
            }
        } catch (\Throwable $exception) {
            $update += ['status' => 'unknown', 'last_error' => 'The connection was interrupted. Check RoyceTalk before sending another acknowledgement.'];
        }
        DB::table('manual_sms_messages')->where('tenant_id', $tenant->id)->where('id', $record->id)->update($update);
        $this->activity($tenant->id, $enquiryId, $actorId, $update['status'] === 'submitted'
            ? 'Acknowledgement SMS submitted to RoyceTalk. Delivery confirmation will appear in the SMS status.'
            : 'Acknowledgement SMS '.$update['status'].'. '.$update['last_error']);
    }

    private function activity(int $tenantId, int $enquiryId, ?int $actorId, string $body): void
    {
        DB::table('sales_activities')->insert([
            'tenant_id' => $tenantId, 'lead_id' => $enquiryId, 'actor_id' => $actorId,
            'type' => 'acknowledgement_sms', 'body' => $body, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
