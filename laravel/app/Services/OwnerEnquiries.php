<?php

namespace App\Services;

use App\Models\Tenant;
use App\Support\PhoneNumber;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * "List your property with us" enquiries from property owners. Each one becomes a lead in the sales pipeline
 * (origin "owner_website") assigned to the website's enquiry contact, with a follow-up task. No booking is created.
 */
class OwnerEnquiries
{
    public const PROPERTY_TYPES = ['apartment' => 'Apartment', 'house' => 'House or villa', 'studio' => 'Studio', 'other' => 'Other'];

    public const CURRENT_USE = [
        'vacant' => 'Vacant or newly bought', 'long_term' => 'Let to a long-term tenant', 'self_hosted' => 'I host it myself on Airbnb or Booking.com',
        'other_manager' => 'Managed by another company', 'building' => 'Still being built', 'other' => 'Other',
    ];

    /** Signed value for the form so automated submissions are refused. */
    public function formToken(Tenant $tenant, int $issuedAt): string
    {
        return hash_hmac('sha256', 'owner-enquiry:'.$tenant->id.':'.$issuedAt, (string) config('app.key'));
    }

    /** The short form beside an owner guide leaves out bedrooms and current use, and passes the guide's title. */
    public function create(Tenant $tenant, Request $request, ?string $fromArticle = null): int
    {
        $data = $request->validate([
            'full_name' => 'required|string|min:2|max:150', 'phone' => 'required|string|max:50', 'email' => 'nullable|email|max:180',
            'location' => 'required|string|min:2|max:150', 'property_type' => ['required', Rule::in(array_keys(self::PROPERTY_TYPES))],
            'bedrooms' => 'nullable|integer|min:0|max:20', 'current_use' => ['nullable', Rule::in(array_keys(self::CURRENT_USE))],
            'message' => 'nullable|string|max:2000', 'consent' => 'accepted', 'website' => 'nullable|string|max:0',
            'form_issued_at' => 'required|integer', 'form_token' => 'required|string|size:64',
        ], ['consent.accepted' => 'Tick the box so we can contact you about your property.', 'website.max' => 'Please submit the form again.']);
        $age = now()->timestamp - (int) $data['form_issued_at'];
        if ($age < 3 || $age > 7200 || ! hash_equals($this->formToken($tenant, (int) $data['form_issued_at']), $data['form_token'])) {
            throw ValidationException::withMessages(['owner_enquiry' => 'Please refresh the page and send the form again.']);
        }
        $phone = PhoneNumber::normalize($data['phone']);
        if ($phone === null) {
            throw ValidationException::withMessages(['phone' => 'Enter a valid phone number, such as 0712 345 678 or +254 712 345 678.']);
        }
        $phoneKey = 'owner-enquiry-phone:'.hash('sha256', $tenant->id.':'.$phone);
        $ipKey = 'owner-enquiry-ip:'.hash('sha256', $tenant->id.':'.$request->ip());
        if (RateLimiter::tooManyAttempts($phoneKey, 3) || RateLimiter::tooManyAttempts($ipKey, 10)) {
            throw ValidationException::withMessages(['owner_enquiry' => 'We already have your details. Our team will call you; you can also reach us on WhatsApp.']);
        }
        RateLimiter::hit($phoneKey, 86400);
        RateLimiter::hit($ipKey, 3600);

        return DB::transaction(function () use ($tenant, $data, $phone, $fromArticle): int {
            $site = DB::table('public_sites')->where('tenant_id', $tenant->id)->lockForUpdate()->first();
            abort_unless($tenant->active && $site?->enabled && ($tenant->modules['sales'] ?? true), 404);
            $assignee = app(PublicListingCatalog::class)->assignees($tenant)->firstWhere('id', (int) ($site->enquiry_assignee_id ?? 0));
            if (! $assignee) {
                throw ValidationException::withMessages(['owner_enquiry' => 'Online enquiries are temporarily unavailable. Please contact us on WhatsApp or by phone.']);
            }
            $deadline = now()->addHours(max(1, min(168, (int) ($site->response_hours ?? 24))));
            $bedrooms = match (true) {
                ! isset($data['bedrooms']) => null,
                (int) $data['bedrooms'] === 0 => 'studio',
                default => $data['bedrooms'].' bedroom'.((int) $data['bedrooms'] === 1 ? '' : 's'),
            };
            $property = self::PROPERTY_TYPES[$data['property_type']].($bedrooms ? ', '.$bedrooms : '').', in '.trim($data['location']);
            $notes = 'Property owner enquiry from the website: wants '.$tenant->name.' to manage their property.'
                ."\nProperty: ".$property.'.'
                ."\nCurrently: ".(isset($data['current_use']) ? self::CURRENT_USE[$data['current_use']] : 'not given').'.'
                .($fromArticle ? "\nSent from the guide: ".$fromArticle : '')
                .(! empty($data['message']) ? "\nOwner's message: ".trim($data['message']) : '');
            $id = DB::table('sales_leads')->insertGetId([
                'tenant_id' => $tenant->id, 'name' => trim($data['full_name']), 'phone' => $phone, 'phone_normalized' => ltrim($phone, '+'),
                'email' => empty($data['email']) ? null : strtolower($data['email']), 'source' => 'website', 'channel' => 'phone',
                'assigned_to' => $assignee->id, 'guests' => 1, 'location' => trim($data['location']), 'unit_preference' => mb_substr('Owner: '.$property, 0, 255),
                'stage' => 'new', 'next_action' => 'Call the owner about managing their property', 'follow_up_at' => $deadline, 'notes' => $notes,
                'public_origin' => 'owner_website', 'public_consented_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('sales_activities')->insert([
                'tenant_id' => $tenant->id, 'lead_id' => $id, 'actor_id' => null, 'type' => 'website_enquiry',
                'body' => 'Property owner sent the "List your property" form; automatically assigned to '.$assignee->name.'.',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            if ($tenant->modules['tasks'] ?? true) {
                $taskId = DB::table('task_items')->insertGetId([
                    'tenant_id' => $tenant->id, 'title' => 'Call property owner: '.mb_substr(trim($data['full_name']), 0, 100),
                    'description' => 'Owner wants their property managed ('.$property.'). '.rtrim(config('app.url'), '/').route('sales.show', [$tenant->slug, $id], false),
                    'created_by' => null, 'assigned_to' => $assignee->id,
                    'due_date' => $deadline->copy()->timezone($tenant->timezone ?: config('app.timezone'))->toDateString(),
                    'priority' => 'normal', 'status' => 'todo', 'owner_visible' => false, 'share_cost' => false,
                    'kind' => 'routine', 'cost_status' => 'none', 'created_at' => now(), 'updated_at' => now(),
                ]);
                DB::table('sales_leads')->where('id', $id)->update(['public_task_id' => $taskId]);
                DB::table('task_events')->insert(['task_id' => $taskId, 'actor_id' => null,
                    'message' => 'Automatically created from a property owner enquiry.', 'created_at' => now(), 'updated_at' => now()]);
                app(TaskNotifications::class)->record(DB::table('task_items')->find($taskId), 'website-enquiry', 'A property owner wants to talk about management.');
            }

            return $id;
        });
    }
}
