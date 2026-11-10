<?php

namespace App\Services;

use App\Models\Tenant;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Guest-facing enquiry references and private tracking links that show the enquiry's pipeline progress.
 */
class EnquiryTracking
{
    public const CODE_PATTERN = '[A-Za-z0-9_-]{12}';

    public const VALID_DAYS = 180;

    /**
     * Guest-facing status for each pipeline stage, in pipeline order.
     *
     * @var array<string, array{step: int, label: string, message: string}>
     */
    public const STATUSES = [
        'new' => ['step' => 1, 'label' => 'Enquiry received', 'message' => 'We have received your enquiry. Our team will review it and get back to you shortly.'],
        'qualifying' => ['step' => 2, 'label' => 'Reviewing your request', 'message' => 'Our team is checking availability and the details of your stay.'],
        'options_shared' => ['step' => 3, 'label' => 'Options shared', 'message' => 'We have shared stay options with you. Reply to let us know which one you prefer.'],
        'invoice_issued' => ['step' => 4, 'label' => 'Invoice sent', 'message' => 'We have sent your invoice. Your stay is secured once your deposit is received.'],
        'awaiting_deposit' => ['step' => 4, 'label' => 'Awaiting your deposit', 'message' => 'Your stay is secured once your deposit is received. Contact us if you need help with payment.'],
        'won' => ['step' => 5, 'label' => 'Booking confirmed', 'message' => 'Your stay is confirmed. We look forward to hosting you.'],
        'lost' => ['step' => 0, 'label' => 'Enquiry closed', 'message' => 'This enquiry is closed. Contact us any time if you would like to plan another stay.'],
    ];

    /** @var list<string> */
    public const STEPS = ['Received', 'Reviewing', 'Options', 'Payment', 'Confirmed'];

    /** Company prefixes are 2–5 capital letters, such as HS. */
    public const PREFIX_PATTERN = '[A-Z]{2,5}';

    /** Failed "Track my enquiry" attempts allowed per reference before it is locked for an hour. */
    public const LOOKUP_ATTEMPTS = 5;

    /**
     * Allocate the company's next reference for this month, such as HS-2611-042 (prefix, year and month, number).
     *
     * Numbers come from a locked per-company monthly counter, so concurrent enquiries never share a reference.
     */
    public function newReference(Tenant $tenant): string
    {
        $period = now($tenant->timezone ?: config('app.timezone'))->format('ym');

        return DB::transaction(function () use ($tenant, $period): string {
            DB::table('enquiry_reference_counters')->insertOrIgnore([
                'tenant_id' => $tenant->id, 'period' => $period, 'last_number' => 0, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $counter = DB::table('enquiry_reference_counters')->where('tenant_id', $tenant->id)->where('period', $period)->lockForUpdate()->first();
            $number = (int) $counter->last_number + 1;
            DB::table('enquiry_reference_counters')->where('id', $counter->id)->update(['last_number' => $number, 'updated_at' => now()]);

            return sprintf('%s-%s-%03d', $this->prefix($tenant), $period, $number);
        });
    }

    /** The company's reference prefix, defaulting to the initials of its name (Haven Suites becomes HS). */
    public function prefix(Tenant $tenant): string
    {
        $configured = strtoupper(trim((string) $tenant->reference_prefix));
        if (preg_match('/^'.self::PREFIX_PATTERN.'$/D', $configured)) {
            return $configured;
        }
        $words = preg_split('/[^A-Za-z]+/', (string) $tenant->name, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $initials = strtoupper(implode('', array_map(fn (string $word): string => $word[0], $words)));
        if (strlen($initials) === 1) {
            $initials = strtoupper(substr($words[0], 0, 2));
        }

        return preg_match('/^'.self::PREFIX_PATTERN.'$/D', substr($initials, 0, 5)) ? substr($initials, 0, 5) : 'ENQ';
    }

    /** Normalise a typed reference: trim, upper-case and turn spaces into dashes ("hs 2611 042" becomes HS-2611-042). */
    public function normalizeReference(string $reference): string
    {
        return (string) preg_replace('/[\s-]+/', '-', strtoupper(trim($reference)));
    }

    /**
     * Return the tracking URL for a reference when the phone number on the enquiry ends with these four digits.
     *
     * Returns null for an unknown reference, wrong digits or a reference locked after repeated failures, without
     * saying which, so the form cannot be used to discover references.
     */
    public function lookup(Tenant $tenant, string $reference, string $lastDigits): ?string
    {
        $reference = $this->normalizeReference($reference);
        $lockKey = 'enquiry-lookup:'.$tenant->id.':'.hash('sha256', $reference);
        if (RateLimiter::tooManyAttempts($lockKey, self::LOOKUP_ATTEMPTS)) {
            return null;
        }
        $leadId = DB::table('enquiry_tracking_links')->where('tenant_id', $tenant->id)->where('reference', $reference)->value('lead_id')
            ?? DB::table('public_enquiry_receipts')->where('tenant_id', $tenant->id)->where('reference', $reference)->value('lead_id');
        $lead = $leadId ? DB::table('sales_leads')->where('tenant_id', $tenant->id)->where('id', $leadId)->first() : null;
        $phoneDigits = preg_replace('/\D+/', '', (string) ($lead->phone ?? ''));
        if (! $lead || strlen($phoneDigits) < 4 || ! preg_match('/^[0-9]{4}$/D', $lastDigits) || ! hash_equals(substr($phoneDigits, -4), $lastDigits)) {
            RateLimiter::hit($lockKey, 3600);

            return null;
        }
        RateLimiter::clear($lockKey);

        return $this->issue($tenant, $lead)['url'];
    }

    /**
     * Return the enquiry's reference and tracking URL, creating them on first use.
     *
     * @return array{reference: string, url: string}
     */
    public function issue(Tenant $tenant, object $lead): array
    {
        if ($existing = $this->forLead($tenant, (int) $lead->id)) {
            return $existing;
        }
        $reference = DB::table('public_enquiry_receipts')->where('tenant_id', $tenant->id)->where('lead_id', $lead->id)->value('reference')
            ?: $this->newReference($tenant);
        // 9 random bytes encode to exactly 12 URL-safe characters (72 bits).
        $code = strtr(base64_encode(random_bytes(9)), '+/', '-_');
        $expiresAt = now()->addDays(self::VALID_DAYS);
        if (! empty($lead->check_out) && Carbon::parse($lead->check_out)->addDays(30)->greaterThan($expiresAt)) {
            $expiresAt = Carbon::parse($lead->check_out)->addDays(30);
        }
        DB::table('enquiry_tracking_links')->insertOrIgnore([
            'tenant_id' => $tenant->id, 'lead_id' => $lead->id, 'reference' => $reference,
            'code_hash' => hash('sha256', $code), 'code_encrypted' => Crypt::encryptString($code),
            'expires_at' => $expiresAt, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $this->forLead($tenant, (int) $lead->id) ?? throw new \RuntimeException('The enquiry tracking link could not be saved.');
    }

    /** @return array{reference: string, url: string}|null */
    public function forLead(Tenant $tenant, int $leadId): ?array
    {
        $link = DB::table('enquiry_tracking_links')->where('tenant_id', $tenant->id)->where('lead_id', $leadId)->first();
        if (! $link) {
            return null;
        }
        try {
            $code = Crypt::decryptString($link->code_encrypted);
        } catch (DecryptException) {
            return null;
        }

        return ['reference' => $link->reference, 'url' => $this->url($code)];
    }

    /**
     * Find the active company and enquiry for a tracking code, or null when the link is unknown or expired.
     *
     * @return array{tenant: Tenant, lead: object, reference: string}|null
     */
    public function find(string $code): ?array
    {
        if (! preg_match('/^'.self::CODE_PATTERN.'$/D', $code)) {
            return null;
        }
        $link = DB::table('enquiry_tracking_links')->where('code_hash', hash('sha256', $code))->where('expires_at', '>', now())->first();
        $tenant = $link ? Tenant::whereKey($link->tenant_id)->where('active', true)->first() : null;
        $lead = $tenant ? DB::table('sales_leads')->where('tenant_id', $tenant->id)->where('id', $link->lead_id)->first() : null;

        return $lead ? ['tenant' => $tenant, 'lead' => $lead, 'reference' => $link->reference] : null;
    }

    /** @return array{step: int, label: string, message: string} */
    public function status(string $stage): array
    {
        return self::STATUSES[$stage] ?? self::STATUSES['new'];
    }

    public function url(string $code): string
    {
        return rtrim(config('app.url'), '/').route('enquiry.track', ['code' => $code], false);
    }
}
