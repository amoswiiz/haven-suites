<?php

namespace App\Http\Controllers;

use App\Services\EnquiryAcknowledgements;
use App\Services\EnquiryTracking;
use App\Services\PublicListingCatalog;
use App\Services\PublicWebsiteLinks;
use App\Services\SalesPipelineSummary;
use App\Services\TaskAccess;
use App\Services\WhatsAppEnquiryReader;
use App\Support\CompanyModules;
use App\Support\PhoneNumber;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class SalesController extends Controller
{
    private function table(string $name): Builder
    {
        return DB::table($name)->where('tenant_id', app('currentTenant')->id);
    }

    private function lead(int $id): object
    {
        return $this->table('sales_leads')->where('id', $id)->firstOrFail();
    }

    private function activity(int $id, string $type, string $body): void
    {
        DB::table('sales_activities')->insert(['tenant_id' => app('currentTenant')->id, 'lead_id' => $id, 'actor_id' => auth()->id(), 'type' => $type, 'body' => $body, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function acknowledgeAfterCommit(int $id): void
    {
        $tenant = app('currentTenant');
        $actor = auth()->user();
        DB::afterCommit(function () use ($tenant, $id, $actor): void {
            try {
                $service = app(EnquiryAcknowledgements::class);
                if ($service->status($tenant->id, $id)?->status === 'pending') {
                    $service->send($tenant, $id, $actor);
                }
            } catch (\Throwable $exception) {
                Log::warning('Enquiry saved but acknowledgement submission could not be completed.', ['tenant_id' => $tenant->id, 'enquiry_id' => $id]);
            }
        });
    }

    private function members(): Collection
    {
        return app('currentTenant')->users()->where('active', true)->get()->filter(fn ($user) => (bool) array_intersect(json_decode($user->pivot->roles, true), ['superadmin', 'admin', 'manager', 'operations', 'sales']));
    }

    public function index(Request $request): View
    {
        $filters = $request->validate(['q' => 'nullable|string|max:100', 'stage' => ['nullable', Rule::in(array_keys(config('sales.stages')))], 'source' => ['nullable', Rule::in(array_keys(config('sales.sources')))], 'assigned_to' => 'nullable|integer', 'overdue' => 'nullable|boolean', 'view' => ['nullable', Rule::in(['board', 'list'])]]);
        $query = $this->table('sales_leads');
        foreach (['stage', 'source', 'assigned_to'] as $key) {
            if (! empty($filters[$key])) {
                $query->where($key, $filters[$key]);
            }
        }
        if (! empty($filters['q'])) {
            $q = $filters['q'];
            $query->where(function (Builder $query) use ($q): void {
                $query->where('name', 'like', '%'.$q.'%')->orWhere('phone', 'like', '%'.$q.'%')->orWhere('email', 'like', '%'.$q.'%');
                $reference = app(EnquiryTracking::class)->normalizeReference($q);
                if (preg_match('/^[A-Z]{2,5}-[A-Z0-9-]{3,}$/D', $reference)) {
                    $tenantId = app('currentTenant')->id;
                    $query->orWhereIn('id', DB::table('enquiry_tracking_links')->where('tenant_id', $tenantId)->where('reference', $reference)->select('lead_id'))
                        ->orWhereIn('id', DB::table('public_enquiry_receipts')->where('tenant_id', $tenantId)->where('reference', $reference)->select('lead_id'));
                }
            });
        }
        if (! empty($filters['overdue'])) {
            $query->whereNotIn('stage', ['won', 'lost'])->where('follow_up_at', '<', now());
        }
        $summary = $this->table('sales_leads')->selectRaw('source, COUNT(*) as total, SUM(CASE WHEN stage = ? THEN 1 ELSE 0 END) as won, SUM(CASE WHEN stage = ? THEN COALESCE(budget,0) ELSE 0 END) as estimated_won_value', ['won', 'won'])->groupBy('source')->get();
        $pipelineSummary = app(SalesPipelineSummary::class)->summary();

        return view('sales.index', ['leads' => $query->orderByRaw('CASE WHEN follow_up_at IS NULL THEN 1 ELSE 0 END')->orderBy('follow_up_at')->paginate(60)->withQueryString(), 'members' => $this->members(), 'summary' => $summary, 'filters' => $filters, 'pipelineSummary' => $pipelineSummary, 'overdue' => $pipelineSummary['overdueCount']]);
    }

    public function create(Request $request): View
    {
        $input = $request->validate(['listing' => 'nullable|integer|min:1', 'check_in' => 'nullable|date_format:Y-m-d', 'check_out' => 'nullable|date_format:Y-m-d',
            'guests' => 'nullable|integer|min:1|max:1000', 'message' => 'nullable|string|max:3000', 'phone' => 'nullable|string|max:50', 'name' => 'nullable|string|max:150']);
        $tenant = app('currentTenant');
        $published = $request->hasAny(['listing', 'message']) ? app(PublicListingCatalog::class)->published($tenant) : collect();
        $read = filled($input['message'] ?? null) ? app(WhatsAppEnquiryReader::class)->read($tenant, $input['message']) : [];
        $listing = $published->firstWhere('id', (int) ($input['listing'] ?? 0))
            ?? ($read['listing_slug'] ?? null ? $published->firstWhere('slug', $read['listing_slug']) : null)
            ?? ($read['listing_code'] ?? null ? $published->firstWhere('listing_code', $read['listing_code']) : null);
        $checkIn = $read['check_in'] ?? $input['check_in'] ?? null;
        $checkOut = $read['check_out'] ?? $input['check_out'] ?? null;
        if ($checkIn && $checkOut && $checkOut <= $checkIn) {
            $checkOut = null;
        }
        $fromWhatsApp = filled($input['message'] ?? null);
        $prefill = array_filter([
            'name' => $input['name'] ?? null, 'phone' => $input['phone'] ?? null,
            'unit_preference' => $listing ? $listing['title'].($listing['listing_code'] ? ' (Code '.$listing['listing_code'].')' : '') : null,
            'location' => $listing ? implode(', ', array_filter([$listing['neighbourhood'], $listing['city']])) : null,
            'check_in' => $checkIn, 'check_out' => $checkOut, 'guests' => $read['guests'] ?? $input['guests'] ?? null,
            'source' => $fromWhatsApp ? 'whatsapp' : ($listing ? 'phone' : null), 'channel' => $fromWhatsApp ? 'whatsapp' : ($listing ? 'phone' : null),
            'notes' => $fromWhatsApp ? "Guest's WhatsApp message:\n".trim($input['message']) : null,
            'public_listing_id' => $listing['id'] ?? null,
        ], fn ($value): bool => $value !== null && $value !== '');

        return view('sales.form', ['members' => $this->members(), 'agents' => CompanyModules::enabled('agents') ? $this->table('sales_agents')->where('active', true)->get() : collect(),
            'prefill' => $prefill, 'prefillListing' => $listing, 'pastedMessage' => $input['message'] ?? null,
            'messageUnmatched' => $fromWhatsApp && ! $listing]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => 'required|string|max:150', 'phone' => 'nullable|string|max:50', 'email' => 'nullable|email|max:255', 'source' => ['required', Rule::in(array_keys(config('sales.sources')))], 'channel' => ['required', Rule::in(array_keys(config('sales.channels')))], 'campaign' => 'nullable|string|max:255', 'assigned_to' => ['required', Rule::in($this->members()->pluck('id')->all())], 'agent_id' => 'nullable|integer', 'check_in' => 'nullable|date_format:Y-m-d', 'check_out' => 'nullable|required_with:check_in|date_format:Y-m-d|after:check_in', 'guests' => 'required|integer|min:1|max:1000', 'location' => 'nullable|string|max:255', 'unit_preference' => 'nullable|string|max:255', 'budget' => 'nullable|numeric|min:0|max:999999999.99', 'next_action' => 'required|string|max:255', 'follow_up_at' => 'required|date|after:now', 'notes' => 'nullable|string|max:5000', 'duplicate_acknowledged' => 'sometimes|accepted', 'capture_token' => 'nullable|uuid', 'public_listing_id' => 'nullable|integer|min:1']);
        $listingLink = [];
        if (! empty($data['public_listing_id'])) {
            $listing = DB::table('public_listings')->where('tenant_id', app('currentTenant')->id)->where('id', $data['public_listing_id'])->first();
            if (! $listing) {
                throw ValidationException::withMessages(['public_listing_id' => 'The selected apartment listing is no longer available. Remove it and choose the apartment again.']);
            }
            $listingLink = ['public_listing_id' => $listing->id, 'public_unit_id' => $listing->unit_id, 'public_partner_apartment_id' => $listing->partner_apartment_id ?? null, 'public_origin' => 'staff'];
        }
        unset($data['public_listing_id']);
        $data['capture_token'] = ! empty($data['capture_token']) ? strtolower($data['capture_token']) : null;
        if ($data['capture_token'] !== null && ($existing = $this->table('sales_leads')->where('capture_token', $data['capture_token'])->first())) {
            $this->acknowledgeAfterCommit((int) $existing->id);

            return redirect()->route('sales.show', [app('currentTenant')->slug, $existing->id])
                ->with('success', 'This enquiry was already saved. No duplicate enquiry or acknowledgement was created.');
        }
        if (PhoneNumber::isBlank($data['phone'] ?? null)) {
            $data['phone'] = null;
        } elseif ($international = PhoneNumber::normalize($data['phone'], mobileOnly: false)) {
            $data['phone'] = $international;
        }
        if (empty($data['phone']) && empty($data['email'])) {
            throw ValidationException::withMessages(['phone' => 'Enter a phone number or email address.']);
        }
        if (! empty($data['agent_id'])) {
            abort_unless(CompanyModules::enabled('agents'), 403);
            $this->table('sales_agents')->where('active', true)->where('id', $data['agent_id'])->firstOrFail();
        }
        $phone = ltrim(PhoneNumber::normalize($data['phone'] ?? null, mobileOnly: false) ?? '', '+');
        $data['email'] = empty($data['email']) ? null : strtolower($data['email']);
        $duplicates = $phone === '' && empty($data['email']) ? collect() : $this->table('sales_leads')->where(function (Builder $q) use ($phone, $data): void {
            if ($phone !== '') {
                $q->where('phone_normalized', $phone);
            }if (! empty($data['email'])) {
                $q->orWhere('email', $data['email']);
            }
        })->get();
        if ($duplicates->isNotEmpty() && ! $request->boolean('duplicate_acknowledged')) {
            return back()->withInput()->with('duplicates', $duplicates->map(fn ($lead) => ['id' => $lead->id, 'name' => $lead->name, 'check_in' => $lead->check_in])->all())->withErrors(['duplicate' => 'Possible duplicate guest found. Review the existing enquiries or confirm this is a separate enquiry.']);
        }
        unset($data['duplicate_acknowledged']);
        $tenant = app('currentTenant');
        $actor = auth()->user();
        try {
            $id = DB::transaction(function () use ($data, $phone, $tenant, $actor, $listingLink): int {
                $id = DB::table('sales_leads')->insertGetId([...$data, ...$listingLink, 'tenant_id' => $tenant->id, 'phone_normalized' => $phone ?: null, 'stage' => 'new', 'created_at' => now(), 'updated_at' => now()]);
                $this->activity($id, 'created', 'Enquiry captured from '.$data['source'].'.');
                app(EnquiryAcknowledgements::class)->prepare($tenant, $id, $actor);
                $this->acknowledgeAfterCommit($id);

                return $id;
            });
        } catch (UniqueConstraintViolationException $exception) {
            $existing = $data['capture_token'] !== null ? $this->table('sales_leads')->where('capture_token', $data['capture_token'])->first() : null;
            if (! $existing) {
                throw $exception;
            }
            $this->acknowledgeAfterCommit((int) $existing->id);

            return redirect()->route('sales.show', [$tenant->slug, $existing->id])
                ->with('success', 'This enquiry was already saved. No duplicate enquiry or acknowledgement was created.');
        }

        return redirect()->route('sales.show', [$tenant->slug, $id])->with('success', 'Enquiry added to the New enquiry pipeline stage. Check its acknowledgement SMS status below.');
    }

    public function show(string $tenant, int $id): View
    {
        $lead = $this->lead($id);
        $publicListing = null;
        $publicRequestedUnit = null;
        $publicRequestedPartner = null;
        $publicFollowupTask = null;
        $ownerContact = null;
        if (in_array($lead->public_origin ?? null, ['website', 'staff'], true)) {
            $publicListing = app(PublicListingCatalog::class)->published(app('currentTenant'))->firstWhere('id', (int) $lead->public_listing_id);
            if ($lead->public_unit_id && empty($lead->public_partner_apartment_id)) {
                $publicRequestedUnit = DB::connection('tenant')->table('units as u')->leftJoin('properties as p', 'p.id', '=', 'u.property_id')
                    ->where('u.id', $lead->public_unit_id)->first(['u.id', 'u.unit_number', 'u.status', 'p.name as property_name']);
            } elseif (! empty($lead->public_partner_apartment_id) && ! $lead->public_unit_id) {
                $publicRequestedPartner = DB::connection('tenant')->table('partner_apartments')
                    ->where('id', $lead->public_partner_apartment_id)
                    ->first(['id', 'apartment_name', 'building_name', 'unit_number', 'location', 'status']);
            }
            if (auth()->user()->can('properties.manage') || auth()->user()->can('reservations.manage')) {
                $ownerContact = app(PublicListingCatalog::class)->ownerContact(app('currentTenant'),
                    $lead->public_unit_id && empty($lead->public_partner_apartment_id) ? (int) $lead->public_unit_id : null,
                    ! empty($lead->public_partner_apartment_id) && ! $lead->public_unit_id ? (int) $lead->public_partner_apartment_id : null);
                if ($ownerContact) {
                    $ownerContact['whatsapp_url'] = app(PublicWebsiteLinks::class)->ownerWhatsappUrl($ownerContact, app('currentTenant'),
                        (string) $lead->unit_preference, array_filter(['check_in' => $lead->check_in, 'check_out' => $lead->check_out, 'guests' => $lead->guests]));
                }
            }
            $task = DB::table('task_items')->where('tenant_id', app('currentTenant')->id)->where('id', $lead->public_task_id)->first();
            if ($task && app(TaskAccess::class)->visible(auth()->user(), $task)) {
                $publicFollowupTask = $task;
            }
        }
        if (auth()->user()->can('messaging.view')) {
            $this->table('sales_messages')->where('lead_id', $id)->whereNull('read_at')->update(['read_at' => now()]);
        }

        $invoices = $lead->reservation_id && auth()->user()->can('finance.view')
            ? DB::connection('tenant')->table('invoices')->where('reservation_id', $lead->reservation_id)->where('status', '!=', 'void')->latest('id')->get() : collect();

        $eligibleInvoices = collect();
        $eligibleBookings = collect();
        if (auth()->user()->can('sales.manage') && auth()->user()->can('reservations.manage')) {
            $linkedElsewhere = $this->table('sales_leads')->whereNotNull('reservation_id')->where('id', '!=', $id)->pluck('reservation_id')->all();
            $bookingsQuery = DB::connection('tenant')->table('reservations as r')->leftJoin('units as u', 'u.id', '=', 'r.unit_id')->leftJoin('properties as p', 'p.id', '=', 'u.property_id')->where('r.status', '!=', 'cancelled')->whereNotIn('r.id', $linkedElsewhere)->whereRaw('LOWER(TRIM(r.guest_name)) = ?', [mb_strtolower(trim($lead->name))]);
            if ($lead->check_in) {
                $bookingsQuery->where('r.check_in', $lead->check_in);
            }
            if ($lead->check_out) {
                $bookingsQuery->where('r.check_out', $lead->check_out);
            }
            $eligibleBookings = $bookingsQuery->select('r.id', 'r.guest_name', 'r.check_in', 'r.check_out', 'u.unit_number', 'p.name as property_name')->latest('r.id')->get();
        }
        $searchData = request()->validate(['invoice_search' => 'nullable|string|max:150']);
        $invoiceSearch = trim($searchData['invoice_search'] ?? '');
        if (! $lead->reservation_id && auth()->user()->can('finance.view') && auth()->user()->can('sales.manage') && auth()->user()->can('reservations.manage')) {
            $linkedBookings = $this->table('sales_leads')->whereNotNull('reservation_id')->where('id', '!=', $id)->pluck('reservation_id')->all();
            $query = DB::connection('tenant')->table('invoices as i')
                ->join('reservations as r', 'r.id', '=', 'i.reservation_id')
                ->leftJoin('units as u', 'u.id', '=', 'r.unit_id')
                ->leftJoin('properties as p', 'p.id', '=', 'u.property_id')
                ->where('i.status', '!=', 'void')->where('r.status', '!=', 'cancelled')
                ->whereRaw('LOWER(TRIM(r.guest_name)) = ?', [mb_strtolower(trim($lead->name))])
                ->whereNotIn('r.id', $linkedBookings);
            if ($lead->check_in) {
                $query->where('r.check_in', $lead->check_in);
            }
            if ($lead->check_out) {
                $query->where('r.check_out', $lead->check_out);
            }
            if ($invoiceSearch !== '') {
                $query->where(function ($query) use ($invoiceSearch): void {
                    $query->where('i.invoice_number', 'like', '%'.$invoiceSearch.'%')
                        ->orWhere('i.customer_name', 'like', '%'.$invoiceSearch.'%')
                        ->orWhere('r.guest_phone', 'like', '%'.$invoiceSearch.'%')
                        ->orWhere('u.unit_number', 'like', '%'.$invoiceSearch.'%');
                });
            }
            $eligibleInvoices = $query->select('i.id', 'i.invoice_number', 'i.customer_name', 'i.issue_date', 'i.total_amount', 'i.currency', 'i.status', 'r.id as reservation_id', 'r.check_in', 'r.check_out', 'u.unit_number', 'p.name as property_name')->latest('i.id')->limit(50)->get();
        }

        return view('sales.show', ['publicListing' => $publicListing, 'publicRequestedUnit' => $publicRequestedUnit, 'publicRequestedPartner' => $publicRequestedPartner, 'publicFollowupTask' => $publicFollowupTask, 'ownerContact' => $ownerContact, 'acknowledgement' => app(EnquiryAcknowledgements::class)->status(app('currentTenant')->id, $id), 'tracking' => app(EnquiryTracking::class)->forLead(app('currentTenant'), $id),
            'resendBlockedReason' => auth()->user()->can('sales.manage') ? app(EnquiryAcknowledgements::class)->resendBlockedReason(app('currentTenant'), $lead) : 'Only sales managers can resend the acknowledgement.', 'eligibleBookings' => $eligibleBookings, 'eligibleInvoices' => $eligibleInvoices, 'invoiceSearch' => $invoiceSearch, 'invoices' => $invoices, 'lead' => $lead, 'members' => $this->members(), 'activities' => $this->table('sales_activities')->where('lead_id', $id)->latest('id')->limit(100)->get(), 'quotes' => $this->table('sales_quotes')->where('lead_id', $id)->latest('id')->get(), 'messages' => auth()->user()->can('messaging.view') ? $this->table('sales_messages')->where('lead_id', $id)->latest('id')->limit(100)->get() : collect(), 'identities' => auth()->user()->can('messaging.view') ? $this->table('messaging_identities')->where('lead_id', $id)->get() : collect()]);
    }

    public function update(string $tenant, int $id, Request $request): RedirectResponse
    {
        $lead = $this->lead($id);
        $data = $request->validate(['stage' => ['required', Rule::in(array_keys(config('sales.stages')))], 'assigned_to' => ['required', Rule::in($this->members()->pluck('id')->all())], 'next_action' => 'required|string|max:255', 'follow_up_at' => 'nullable|required_unless:stage,won,lost|date', 'lost_reason' => 'nullable|required_if:stage,lost|string|max:255', 'version' => 'required|integer']);
        if ($data['stage'] === 'won') {
            throw ValidationException::withMessages(['stage' => 'Use Confirm booking below to verify payment and link a reservation.']);
        }
        if (in_array($data['stage'], ['invoice_issued', 'awaiting_deposit'], true)) {
            $booking = $lead->reservation_id && CompanyModules::enabled('finance') ? DB::connection('tenant')->table('reservations')->where('id', $lead->reservation_id)->where('status', '!=', 'cancelled')->first() : null;
            if (! $booking || ! DB::connection('tenant')->table('invoices')->where('reservation_id', $booking->id)->whereIn('status', ['issued', 'partially_paid', 'paid', 'overdue'])->whereNotNull('invoice_number')->exists()) {
                throw ValidationException::withMessages(['stage' => 'Link the booking and issue its invoice before moving to this stage. No quotation is required.']);
            }
        }
        $version = $data['version'];
        unset($data['version']);
        $data['closed_at'] = $data['stage'] === 'lost' ? now() : null;
        DB::transaction(function () use ($id, $version, $data, $lead): void {
            $changed = $this->table('sales_leads')->where('id', $id)->where('version', $version)->update([...$data, 'version' => $version + 1, 'updated_at' => now()]);
            if (! $changed) {
                throw ValidationException::withMessages(['version' => 'This enquiry changed. Refresh before saving.']);
            }$this->activity($id, 'updated', config('sales.stages.'.$lead->stage).' ? '.config('sales.stages.'.$data['stage']).'; next action: '.$data['next_action']);
        });

        return back()->with('success', 'Pipeline and follow-up updated.');
    }

    public function note(string $tenant, int $id, Request $request): RedirectResponse
    {
        $this->lead($id);
        $data = $request->validate(['body' => 'required|string|max:5000']);
        $this->activity($id, 'note', $data['body']);

        return back()->with('success', 'Note saved.');
    }

    public function details(string $tenant, int $id, Request $request): RedirectResponse
    {
        $lead = $this->lead($id);
        $data = $request->validate(['name' => 'required|string|max:150', 'phone' => 'nullable|string|max:50', 'email' => 'nullable|email|max:255', 'check_in' => 'nullable|required_with:check_out|date_format:Y-m-d', 'check_out' => 'nullable|required_with:check_in|date_format:Y-m-d|after:check_in', 'guests' => 'required|integer|min:1|max:1000', 'location' => 'nullable|string|max:255', 'unit_preference' => 'nullable|string|max:255', 'budget' => 'nullable|numeric|min:0|max:999999999.99', 'version' => 'required|integer']);
        if (PhoneNumber::isBlank($data['phone'] ?? null) && empty($data['email'])) {
            throw ValidationException::withMessages(['phone' => 'Enter a phone number or email address.']);
        }
        $international = PhoneNumber::normalize($data['phone'] ?? null, mobileOnly: false);
        if (! PhoneNumber::isBlank($data['phone'] ?? null) && $international === null) {
            throw ValidationException::withMessages(['phone' => 'Enter a valid phone number, such as 0712 345 678 or +254 712 345 678. Kenyan numbers need 9 digits after +254.']);
        }
        $version = $data['version'];
        unset($data['version']);
        $data['phone'] = $international;
        $data['phone_normalized'] = $international ? ltrim($international, '+') : null;
        $data['email'] = empty($data['email']) ? null : strtolower($data['email']);
        $corrections = collect(['name' => 'name', 'phone' => 'phone', 'email' => 'email'])
            ->filter(fn (string $label, string $field): bool => (string) ($lead->$field ?? '') !== (string) ($data[$field] ?? ''))
            ->map(fn (string $label, string $field): string => $label.' changed from "'.($lead->$field ?: 'blank').'" to "'.($data[$field] ?: 'blank').'"')->values();
        DB::transaction(function () use ($id, $version, $data, $lead, $corrections): void {
            if (! $this->table('sales_leads')->where('id', $id)->where('version', $version)->update([...$data, 'version' => $version + 1, 'updated_at' => now()])) {
                throw ValidationException::withMessages(['version' => 'This enquiry changed. Refresh before saving.']);
            }
            $this->activity($id, 'requirements', 'Guest and stay requirements updated. Original source remains '.$lead->source.'.'
                .($corrections->isNotEmpty() ? ' Contact details corrected: '.$corrections->implode('; ').'.' : ''));
        });

        return back()->with('success', 'Guest and stay requirements updated.');
    }

    public function resendAcknowledgement(string $tenant, int $id): RedirectResponse
    {
        $this->lead($id);
        app(EnquiryAcknowledgements::class)->resend(app('currentTenant'), $id, auth()->user());

        return back()->with('success', 'Acknowledgement SMS sent to the corrected number. Check the SMS status below.');
    }

    public function message(string $tenant, int $id, Request $request): RedirectResponse
    {
        $this->lead($id);
        $data = $request->validate(['channel' => ['required', Rule::in(array_keys(config('sales.channels')))], 'direction' => ['required', Rule::in(['inbound', 'outbound'])], 'body' => 'required|string|max:5000', 'external_id' => 'nullable|string|max:255', 'consent' => 'nullable|boolean']);
        DB::transaction(function () use ($id, $data, $request): void {
            if (! empty($data['external_id'])) {
                $identity = $this->table('messaging_identities')->where('channel', $data['channel'])->where('external_id', $data['external_id'])->first();
                if ($identity && (int) $identity->lead_id !== $id) {
                    throw ValidationException::withMessages(['external_id' => 'This identity is already linked to another enquiry.']);
                }if (! $identity) {
                    DB::table('messaging_identities')->insert(['tenant_id' => app('currentTenant')->id, 'lead_id' => $id, 'channel' => $data['channel'], 'external_id' => $data['external_id'], 'consented_at' => $request->boolean('consent') ? now() : null, 'created_at' => now(), 'updated_at' => now()]);
                }
            }
            DB::table('sales_messages')->insert(['tenant_id' => app('currentTenant')->id, 'lead_id' => $id, 'channel' => $data['channel'], 'direction' => $data['direction'], 'body' => $data['body'], 'delivery_status' => 'manual', 'received_at' => now(), 'read_at' => now(), 'recorded_by' => auth()->id(), 'created_at' => now(), 'updated_at' => now()]);
            $this->table('sales_leads')->where('id', $id)->update(['channel' => $data['channel'], 'updated_at' => now()]);
        });

        return back()->with('success', 'Conversation recorded. No message was sent.');
    }

    public function quote(string $tenant, int $id, Request $request): RedirectResponse
    {
        $this->lead($id);

        return back()->withErrors(['quotation' => 'Booking quotations are retired. Use the booking invoice instead.']);
    }

    public function linkBooking(string $tenant, int $id, Request $request): RedirectResponse
    {
        $lead = $this->lead($id);
        $data = $request->validate(['reservation_id' => 'required_without:invoice_id|nullable|integer|min:1', 'invoice_id' => 'nullable|integer|min:1', 'version' => 'required|integer']);
        if (! empty($data['invoice_id'])) {
            abort_unless(auth()->user()->can('finance.view'), 403);
            $invoice = DB::connection('tenant')->table('invoices')->where('id', $data['invoice_id'])->where('status', '!=', 'void')->whereNotNull('reservation_id')->first();
            if (! $invoice || (! empty($data['reservation_id']) && (int) $data['reservation_id'] !== (int) $invoice->reservation_id)) {
                throw ValidationException::withMessages(['invoice_id' => 'Choose an available booking invoice in this company.']);
            }
            $data['reservation_id'] = (int) $invoice->reservation_id;
        }
        $booking = DB::connection('tenant')->table('reservations')->where('id', $data['reservation_id'])->where('status', '!=', 'cancelled')->first();
        if (! $booking || mb_strtolower(trim($booking->guest_name)) !== mb_strtolower(trim($lead->name)) || ($lead->check_in && $lead->check_in !== $booking->check_in) || ($lead->check_out && $lead->check_out !== $booking->check_out)) {
            throw ValidationException::withMessages(['reservation_id' => 'Choose a booking in this company with the same guest and stay dates.']);
        }
        if ($lead->stage === 'won' || ($lead->reservation_id && (int) $lead->reservation_id !== (int) $booking->id)) {
            throw ValidationException::withMessages(['reservation_id' => 'This enquiry already has a booking link.']);
        }
        try {
            DB::transaction(function () use ($id, $data): void {
                if (! $this->table('sales_leads')->where('id', $id)->where('version', $data['version'])->update(['reservation_id' => $data['reservation_id'], 'version' => $data['version'] + 1, 'updated_at' => now()])) {
                    throw ValidationException::withMessages(['version' => 'This enquiry changed. Refresh before linking the booking.']);
                }
                $this->activity($id, 'booking_linked', 'Linked booking #'.$data['reservation_id'].' for invoicing. No payment or confirmation was recorded.');
            });
        } catch (UniqueConstraintViolationException $exception) {
            throw ValidationException::withMessages(['reservation_id' => 'This booking is already linked to another enquiry.']);
        }

        return back()->with('success', 'Booking linked. Create or open its invoice below.');
    }

    public function quotation(string $tenant, int $id, int $quote): View
    {
        $lead = $this->lead($id);
        $quotation = $this->table('sales_quotes')->where('lead_id', $id)->where('id', $quote)->firstOrFail();

        return view('sales.quote', ['lead' => $lead, 'quote' => $quotation, 'snapshot' => json_decode($quotation->snapshot, true)]);
    }

    public function win(string $tenant, int $id, Request $request): RedirectResponse
    {
        abort_unless(auth()->user()->can('reservations.manage') && CompanyModules::enabled('finance'), 403);
        $lead = $this->lead($id);
        $data = $request->validate(['reservation_id' => 'required|integer|min:1', 'required_deposit' => 'required|numeric|min:0.01|max:999999999.99', 'payment_arrangement' => 'nullable|string|max:2000']);
        $reservation = DB::connection('tenant')->table('reservations')->where('id', $data['reservation_id'])->whereIn('status', ['confirmed', 'checked_in', 'checked_out'])->first();
        if (! $reservation) {
            throw ValidationException::withMessages(['reservation_id' => 'Choose a confirmed reservation belonging to this company.']);
        }
        if (mb_strtolower(trim($reservation->guest_name)) !== mb_strtolower(trim($lead->name))
            || ($lead->check_in && $lead->check_in !== $reservation->check_in)
            || ($lead->check_out && $lead->check_out !== $reservation->check_out)) {
            throw ValidationException::withMessages(['reservation_id' => 'The booking guest and stay dates must match this enquiry.']);
        }
        if (! empty($data['payment_arrangement']) && ! auth()->user()->can('finance.manage')) {
            abort(403, 'Only finance managers may approve payment arrangements.');
        }
        $paid = (float) DB::connection('tenant')->table('guest_payments')->where('reservation_id', $reservation->id)->sum('amount');
        if ($paid < (float) $data['required_deposit'] && (empty($data['payment_arrangement']) || ! auth()->user()->can('finance.manage'))) {
            throw ValidationException::withMessages(['required_deposit' => 'Verified guest payments must meet the required deposit. Only finance managers may approve a documented payment arrangement.']);
        }
        if ($this->table('sales_leads')->where('reservation_id', $reservation->id)->where('id', '!=', $id)->exists()) {
            throw ValidationException::withMessages(['reservation_id' => 'This booking is already linked to another enquiry.']);
        }
        DB::transaction(function () use ($id, $data, $paid): void {
            $this->table('sales_leads')->where('id', $id)->update([...$data, 'stage' => 'won', 'closed_at' => now(), 'follow_up_at' => null, 'next_action' => 'Hand over to operations', 'version' => DB::raw('version + 1'), 'updated_at' => now()]);
            $this->activity($id, 'won', 'Linked reservation #'.$data['reservation_id'].'. Verified payments: '.$paid.'.'.(! empty($data['payment_arrangement']) ? ' Approved arrangement: '.$data['payment_arrangement'] : ''));
        });

        return back()->with('success', 'Booking confirmed and linked.');
    }

    public function agents(): View
    {
        return view('sales.agents', ['agents' => $this->table('sales_agents')->orderBy('name')->paginate(30)]);
    }

    public function agent(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => 'required|string|max:150', 'phone' => 'nullable|string|max:50', 'email' => 'nullable|email|max:255', 'commission_terms' => 'nullable|string|max:2000']);
        DB::table('sales_agents')->insert([...$data, 'tenant_id' => app('currentTenant')->id, 'created_at' => now(), 'updated_at' => now()]);

        return back()->with('success', 'Referral agent saved.');
    }

    public function connections(): View
    {
        return view('sales.connections', ['connections' => $this->table('messaging_connections')->get()]);
    }
}
