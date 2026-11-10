<?php

namespace App\Services;

use App\Models\Tenant;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Guest-facing enquiry references and private tracking links that show the enquiry's pipeline progress.
 */
class EnquiryTracking
{
    /** Letters and digits that cannot be confused with each other when read aloud or retyped. */
    private const REFERENCE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

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

    /** Generate a short reference such as ENQ-7K3P9Q that is unused within the company. */
    public function newReference(Tenant $tenant): string
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $reference = 'ENQ-';
            for ($index = 0; $index < 6; $index++) {
                $reference .= self::REFERENCE_ALPHABET[random_int(0, strlen(self::REFERENCE_ALPHABET) - 1)];
            }
            $taken = DB::table('enquiry_tracking_links')->where('tenant_id', $tenant->id)->where('reference', $reference)->exists()
                || DB::table('public_enquiry_receipts')->where('tenant_id', $tenant->id)->where('reference', $reference)->exists();
            if (! $taken) {
                return $reference;
            }
        }

        throw new \RuntimeException('Could not allocate an enquiry reference.');
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
