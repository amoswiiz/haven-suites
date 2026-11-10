<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PublicEnquiries
{
    public function formContext(Tenant $tenant, array $listing, ?Request $request = null): array
    {
        $request ??= request();
        $context = ['capture_token' => (string) Str::uuid(), 'form_issued_at' => (string) now()->timestamp];
        $context['form_signature'] = $this->signature($tenant, (int) $listing['id'], $context, $request);

        return $context;
    }

    public function create(Tenant $tenant, array $listing, Request $request): RedirectResponse
    {
        $listing = app(PublicListingCatalog::class)->findPublished($tenant, $listing['slug']);
        $data = $request->validate([
            'full_name' => 'required|string|min:2|max:150', 'phone' => 'required|string|max:50',
            'email' => 'nullable|email|max:180', 'check_in' => 'required|date_format:Y-m-d',
            'check_out' => 'required|date_format:Y-m-d|after:check_in', 'guests' => 'required|integer|min:1|max:1000',
            'message' => 'nullable|string|max:3000', 'flexible_dates' => 'sometimes|boolean',
            'consent' => 'required|accepted', 'website' => 'nullable|string|max:0',
            'capture_token' => 'required|uuid', 'form_issued_at' => 'required|integer', 'form_signature' => 'required|string|size:64',
            'utm_source' => 'nullable|string|max:100', 'utm_medium' => 'nullable|string|max:100', 'utm_campaign' => 'nullable|string|max:150',
        ]);
        $data['capture_token'] = strtolower($data['capture_token']);
        $age = now()->timestamp - (int) $data['form_issued_at'];
        if ($age < 2 || $age > 3600 || ! hash_equals($this->signature($tenant, (int) $listing['id'], $data, $request), $data['form_signature'])) {
            throw ValidationException::withMessages(['enquiry' => 'Please refresh this listing and submit the enquiry again.']);
        }
        $phone = app(EnquiryAcknowledgements::class)->phone($data['phone']);
        if ($phone === null) {
            throw ValidationException::withMessages(['phone' => 'Enter a valid phone number, such as 0712 345 678 or +254 712 345 678. Kenyan numbers need 9 digits after +254.']);
        }
        $token = $this->receiptToken($tenant, (int) $listing['id'], $data['capture_token']);
        $existing = DB::table('sales_leads')->where('tenant_id', $tenant->id)->where('capture_token', $data['capture_token'])->first();
        if ($existing) {
            $this->existingReceipt($tenant, $existing, $token);
            $this->submitAfterCommit($tenant, (int) $existing->id);

            return $this->redirect($tenant, $token);
        }
        $today = CarbonImmutable::now($tenant->timezone ?: config('app.timezone'))->startOfDay();
        $start = CarbonImmutable::createFromFormat('!Y-m-d', $data['check_in'], $today->timezone);
        $end = CarbonImmutable::createFromFormat('!Y-m-d', $data['check_out'], $today->timezone);
        $nights = (int) $start->diffInDays($end);
        if ($data['check_in'] < $today->toDateString() || $end > $today->addYears(2) || $nights > 366 || $nights < (int) $listing['min_nights']) {
            throw ValidationException::withMessages(['check_out' => 'Choose future stay dates within two years and meet this apartment’s minimum stay.']);
        }
        if ((int) $data['guests'] > (int) $listing['max_guests']) {
            throw ValidationException::withMessages(['guests' => 'The guest count exceeds this apartment’s capacity.']);
        }
        $availability = app(ListingAvailability::class)->calendar($tenant, $listing, $data['check_in'], $data['check_out']);
        $blocked = $availability['blocked'] !== [];
        if ($blocked && ! $request->boolean('flexible_dates')) {
            throw ValidationException::withMessages(['check_in' => 'These dates are unavailable. Choose different dates or select flexible dates so our team can suggest alternatives.']);
        }
        $rateKey = 'public-enquiry-phone:'.hash('sha256', $tenant->id.':'.$phone);
        $ipKey = 'public-enquiry-ip:'.hash('sha256', $tenant->id.':'.$request->ip());
        if (RateLimiter::tooManyAttempts($rateKey, 5) || RateLimiter::tooManyAttempts($ipKey, 20)) {
            throw ValidationException::withMessages(['enquiry' => 'You have sent several enquiries recently. Please contact our team directly or try again later.']);
        }
        RateLimiter::hit($rateKey, 3600);
        RateLimiter::hit($ipKey, 3600);
        try {
            DB::transaction(function () use ($tenant, $listing, $data, $phone, $token, $availability, $blocked, $request): void {
                $site = DB::table('public_sites')->where('tenant_id', $tenant->id)->lockForUpdate()->first();
                $current = DB::table('public_listings')->where('tenant_id', $tenant->id)->where('id', $listing['id'])->lockForUpdate()->first();
                abort_unless($tenant->active && $site?->enabled && $current?->status === 'published'
                    && ($tenant->modules['properties'] ?? true) && ($tenant->modules['sales'] ?? true), 404);
                if ((int) $current->unit_id !== (int) ($listing['unit_id'] ?? null)
                    || (int) ($current->partner_apartment_id ?? null) !== (int) ($listing['partner_apartment_id'] ?? null)
                    || (int) $current->max_guests !== (int) $listing['max_guests']
                    || (int) $current->min_nights !== (int) $listing['min_nights'] || $current->slug !== $listing['slug']
                    || $current->title !== $listing['title'] || $current->city !== $listing['city']
                    || (string) $current->updated_at !== (string) $listing['updated_at']) {
                    throw ValidationException::withMessages(['enquiry' => 'This apartment listing changed. Refresh the listing and review your stay details before submitting again.']);
                }
                $availability = app(ListingAvailability::class)->calendar($tenant, (array) $current, $data['check_in'], $data['check_out']);
                $blocked = $availability['blocked'] !== [];
                if ($blocked && ! $request->boolean('flexible_dates')) {
                    throw ValidationException::withMessages(['check_in' => 'These dates became unavailable. Choose different dates or select flexible dates for alternatives.']);
                }
                $assignee = $this->assignee($tenant, (int) ($site->enquiry_assignee_id ?? 0));
                if (! $assignee) {
                    throw ValidationException::withMessages(['enquiry' => 'Online enquiries are temporarily unavailable. Please contact our team directly.']);
                }
                $deadline = now()->addHours(max(1, min(168, (int) ($site->response_hours ?? 24))));
                $notes = 'Website enquiry for '.$listing['title'].'. Availability must be confirmed by staff.';
                $notes .= $blocked ? "\nSelected dates currently unavailable; guest accepts alternative dates." : '';
                $notes .= ! $availability['fresh'] ? (empty($listing['partner_apartment_id'])
                    ? "\nExternal calendar availability needs a fresh check."
                    : "\nPartner availability must be confirmed with the host; an owner calendar is not connected.") : '';
                $notes .= $request->boolean('flexible_dates') ? "\nGuest is flexible on dates." : '';
                $notes .= ! empty($data['message']) ? "\nGuest message: ".trim($data['message']) : '';
                $id = DB::table('sales_leads')->insertGetId([
                    'tenant_id' => $tenant->id, 'name' => trim($data['full_name']), 'phone' => $phone,
                    'phone_normalized' => ltrim($phone, '+'), 'email' => empty($data['email']) ? null : strtolower($data['email']),
                    'source' => 'website', 'channel' => 'phone', 'campaign' => $data['utm_campaign'] ?? null,
                    'assigned_to' => $assignee->id, 'check_in' => $data['check_in'], 'check_out' => $data['check_out'],
                    'guests' => (int) $data['guests'], 'location' => $listing['city'], 'unit_preference' => $listing['title'],
                    'stage' => 'new', 'next_action' => 'Review website enquiry and confirm availability', 'follow_up_at' => $deadline,
                    'notes' => $notes, 'capture_token' => $data['capture_token'], 'public_listing_id' => $listing['id'],
                    'public_unit_id' => $listing['unit_id'] ?? null,
                    'public_partner_apartment_id' => $listing['partner_apartment_id'] ?? null,
                    'public_origin' => 'website', 'public_consented_at' => now(),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                DB::table('sales_activities')->insert([
                    'tenant_id' => $tenant->id, 'lead_id' => $id, 'actor_id' => null, 'type' => 'website_enquiry',
                    'body' => 'Guest submitted a website enquiry; automatically assigned to '.$assignee->name.'. No booking or payment was created.',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                DB::table('public_enquiry_receipts')->insert([
                    'tenant_id' => $tenant->id, 'lead_id' => $id, 'token_hash' => hash('sha256', $token),
                    'reference' => app(EnquiryTracking::class)->newReference($tenant), 'created_at' => now(), 'updated_at' => now(),
                ]);
                if ($tenant->modules['tasks'] ?? true) {
                    $taskId = DB::table('task_items')->insertGetId([
                        'tenant_id' => $tenant->id, 'title' => 'Follow up website enquiry: '.mb_substr(trim($data['full_name']), 0, 105),
                        'description' => 'Review the enquiry for '.$listing['title'].' and confirm availability and pricing. '.
                            rtrim(config('app.url'), '/').route('sales.show', [$tenant->slug, $id], false),
                        'unit_id' => $listing['unit_id'] ?? null, 'created_by' => null, 'assigned_to' => $assignee->id,
                        'due_date' => $deadline->copy()->timezone($tenant->timezone ?: config('app.timezone'))->toDateString(),
                        'priority' => 'normal', 'status' => 'todo', 'owner_visible' => false, 'share_cost' => false,
                        'kind' => 'routine', 'cost_status' => 'none', 'created_at' => now(), 'updated_at' => now(),
                    ]);
                    DB::table('sales_leads')->where('id', $id)->update(['public_task_id' => $taskId]);
                    DB::table('task_events')->insert(['task_id' => $taskId, 'actor_id' => null,
                        'message' => 'Automatically created from a website enquiry.', 'created_at' => now(), 'updated_at' => now()]);
                    app(TaskNotifications::class)->record(DB::table('task_items')->find($taskId), 'website-enquiry', 'A website enquiry needs your follow-up.');
                }
                app(EnquiryAcknowledgements::class)->preparePublic($tenant, $id);
                $this->submitAfterCommit($tenant, $id);
            });
        } catch (UniqueConstraintViolationException $exception) {
            $existing = DB::table('sales_leads')->where('tenant_id', $tenant->id)->where('capture_token', $data['capture_token'])->first();
            if (! $existing) {
                throw $exception;
            }
            $this->existingReceipt($tenant, $existing, $token);
            $this->submitAfterCommit($tenant, (int) $existing->id);
        }

        return $this->redirect($tenant, $token);
    }

    private function assignee(Tenant $tenant, int $id): ?User
    {
        return app(PublicListingCatalog::class)->assignees($tenant)->firstWhere('id', $id);
    }

    private function signature(Tenant $tenant, int $listingId, array $data, Request $request): string
    {
        return hash_hmac('sha256', implode(':', [$tenant->id, $listingId, $data['capture_token'], $data['form_issued_at'], $request->session()->getId()]), config('app.key'));
    }

    private function receiptToken(Tenant $tenant, int $listingId, string $captureToken): string
    {
        return hash_hmac('sha256', 'public-enquiry:'.$tenant->id.':'.$listingId.':'.$captureToken, config('app.key'));
    }

    private function existingReceipt(Tenant $tenant, object $lead, string $token): void
    {
        abort_unless($lead->public_origin === 'website' && DB::table('public_enquiry_receipts')
            ->where('tenant_id', $tenant->id)->where('lead_id', $lead->id)->where('token_hash', hash('sha256', $token))->exists(), 404);
    }

    private function submitAfterCommit(Tenant $tenant, int $id): void
    {
        DB::afterCommit(function () use ($tenant, $id): void {
            try {
                app(EnquiryAcknowledgements::class)->sendPublic($tenant, $id);
            } catch (\Throwable $exception) {
                Log::warning('Website enquiry saved; acknowledgement needs review.', ['tenant_id' => $tenant->id, 'enquiry_id' => $id]);
            }
        });
    }

    private function redirect(Tenant $tenant, string $token): RedirectResponse
    {
        return redirect()->route('public-stays.received', [$tenant->slug, $token])->header('Cache-Control', 'private, no-store');
    }
}
