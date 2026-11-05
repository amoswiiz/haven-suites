<?php

namespace App\Http\Controllers;

use App\Services\BookingCommunications;
use App\Services\GuestMessageTemplates;
use App\Services\RoyceTalkSms;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class GuestCommunicationController extends Controller
{
    private function templates(): Builder
    {
        return DB::table('guest_message_templates')->where('tenant_id', app('currentTenant')->id)->where('channel', 'sms');
    }

    private function units(): Collection
    {
        return DB::connection('tenant')->table('units as u')->leftJoin('properties as p', 'p.id', '=', 'u.property_id')
            ->select('u.id', 'u.unit_number', 'p.name as property_name')->orderBy('u.unit_number')->get();
    }

    public function index(): View
    {
        return view('communications.templates', ['templates' => $this->templates()->orderBy('name')->get(), 'units' => $this->units()->keyBy('id'), 'purposes' => GuestMessageTemplates::PURPOSES]);
    }

    public function create(Request $request): View
    {
        $purpose = $request->query('purpose', 'check_in');
        if (! is_string($purpose) || ! isset(GuestMessageTemplates::PURPOSES[$purpose])) {
            $purpose = 'custom';
        }

        return view('communications.template_form', ['template' => null, 'units' => $this->units(), 'purpose' => $purpose, 'starter' => GuestMessageTemplates::STARTERS[$purpose], 'purposes' => GuestMessageTemplates::PURPOSES, 'fields' => GuestMessageTemplates::FIELDS]);
    }

    public function edit(string $tenant, int $id): View
    {
        $template = $this->templates()->where('id', $id)->firstOrFail();

        return view('communications.template_form', ['template' => $template, 'units' => $this->units(), 'purpose' => $template->purpose, 'starter' => $template->body, 'purposes' => GuestMessageTemplates::PURPOSES, 'fields' => GuestMessageTemplates::FIELDS]);
    }

    public function store(Request $request): RedirectResponse
    {
        return $this->save($request, null);
    }

    public function update(string $tenant, int $id, Request $request): RedirectResponse
    {
        return $this->save($request, $id);
    }

    private function save(Request $request, ?int $id): RedirectResponse
    {
        if ($id) {
            $this->templates()->where('id', $id)->firstOrFail();
        }
        $data = $request->validate([
            'name' => 'required|string|max:120', 'purpose' => ['required', Rule::in(array_keys(GuestMessageTemplates::PURPOSES))],
            'unit_id' => 'nullable|integer|min:1', 'body' => 'required|string|max:1600', 'active' => 'required|boolean',
            'version' => $id ? 'required|integer|min:1' : 'nullable',
        ]);
        app(GuestMessageTemplates::class)->validateBody($data['body']);
        $data['unit_id'] = $data['unit_id'] ?? null;
        if ($data['unit_id'] && ! $this->units()->contains('id', (int) $data['unit_id'])) {
            throw ValidationException::withMessages(['unit_id' => 'Choose an apartment belonging to this company.']);
        }
        $version = $data['version'] ?? 1;
        unset($data['version']);
        $data += ['updated_by' => auth()->id(), 'updated_at' => now()];
        if ($id) {
            $changed = $this->templates()->where('id', $id)->where('version', $version)->update($data + ['version' => $version + 1]);
            if (! $changed) {
                throw ValidationException::withMessages(['template' => 'This template was changed by someone else. Reload before saving.']);
            }
        } else {
            DB::table('guest_message_templates')->insert($data + ['tenant_id' => app('currentTenant')->id, 'channel' => 'sms', 'created_by' => auth()->id(), 'created_at' => now()]);
        }

        return redirect()->route('communications.templates', app('currentTenant')->slug)->with('success', 'SMS template saved.');
    }

    public function compose(Request $request): View
    {
        $data = $request->validate(['reservation_id' => 'nullable|integer|min:1', 'communication_stage' => ['nullable', 'string', Rule::in(array_keys(BookingCommunications::STAGES))]]);
        $bookings = DB::connection('tenant')->table('reservations')->select('id', 'unit_id', 'guest_name', 'check_in', 'check_out')->orderByDesc('id')->limit(200)->get();
        $chosen = null;
        if (! empty($data['reservation_id'])) {
            $chosen = app(BookingCommunications::class)->booking((int) $data['reservation_id'], $data['communication_stage'] ?? null);
            if (! $bookings->contains('id', $chosen->id)) {
                $bookings->prepend($chosen);
            }
        }

        $stage = $data['communication_stage'] ?? null;
        if ($stage && ! $chosen) {
            throw ValidationException::withMessages(['reservation_id' => 'Select a booking for this communication stage.']);
        }
        $templates = $this->templates()->where('active', true)
            ->when($chosen, fn ($query) => $query->where(fn ($query) => $query->whereNull('unit_id')->orWhere('unit_id', $chosen->unit_id)))
            ->orderBy('name')->get();
        $matching = $stage ? $templates->where('purpose', $stage) : collect();
        $selectedTemplate = $matching->firstWhere('unit_id', $chosen?->unit_id) ?? $matching->firstWhere('unit_id', null);

        return view('communications.guests', ['bookings' => $bookings, 'units' => $this->units()->keyBy('id'), 'templates' => $templates,
            'stages' => BookingCommunications::STAGES, 'stage' => $stage, 'selectedTemplate' => $selectedTemplate?->id,
            'starter' => $stage ? (GuestMessageTemplates::STARTERS[$stage] ?? '') : '']);
    }

    public function preview(Request $request, GuestMessageTemplates $renderer, RoyceTalkSms $transport): View
    {
        $data = $request->validate(['reservation_id' => 'nullable|integer|min:1', 'unit_id' => 'nullable|integer|min:1', 'template_id' => 'nullable|integer|min:1', 'body' => 'nullable|required_without:template_id|string|max:1600', 'phone' => 'nullable|string|max:40',
            'communication_stage' => ['nullable', 'string', Rule::in(array_keys(BookingCommunications::STAGES))]]);
        $booking = app(BookingCommunications::class)->booking(! empty($data['reservation_id']) ? (int) $data['reservation_id'] : null, $data['communication_stage'] ?? null);
        $template = ! empty($data['template_id']) ? $this->templates()->where('id', $data['template_id'])->where('active', true)->firstOrFail() : null;
        $unitId = $booking ? $booking->unit_id : ($data['unit_id'] ?? $template?->unit_id);
        if ($booking && ! empty($data['unit_id']) && (int) $data['unit_id'] !== (int) $booking->unit_id) {
            throw ValidationException::withMessages(['unit_id' => 'The selected apartment does not match this booking.']);
        }
        if ($template?->unit_id && (int) $template->unit_id !== (int) $unitId) {
            throw ValidationException::withMessages(['template_id' => 'This template belongs to a different apartment. Select the matching template or a company-wide template.']);
        }
        $unit = $unitId ? $this->units()->firstWhere('id', (int) $unitId) : null;
        abort_if($unitId && ! $unit, 404);
        $partner = $booking && ! $unitId && ! empty($booking->partner_apartment_id)
            ? DB::connection('tenant')->table('partner_apartments')->where('id', $booking->partner_apartment_id)->first()
            : null;
        $enteredPhone = trim((string) ($data['phone'] ?? ''));
        $phone = $renderer->phone($enteredPhone !== '' ? $enteredPhone : $booking?->guest_phone);
        if (! preg_match('/^\+[1-9][0-9]{7,14}$/D', $phone)) {
            throw ValidationException::withMessages(['phone' => 'Enter a valid recipient number with country code, e.g. +254720602805.']);
        }
        $body = $renderer->render($template?->body ?? $data['body'], [
            'company_name' => app('currentTenant')->name, 'guest_name' => $booking?->guest_name, 'reservation_id' => $booking?->id,
            'apartment' => $unit?->unit_number ?? ($partner?->unit_number ?: $partner?->apartment_name),
            'property_name' => $unit?->property_name ?? ($partner?->building_name ?: $partner?->apartment_name),
            'check_in' => $booking?->check_in, 'check_out' => $booking?->check_out,
        ]);

        return view('communications.preview', ['body' => $body, 'phone' => $phone, 'booking' => $booking, 'template' => $template,
            'communicationStage' => $booking ? ($data['communication_stage'] ?? 'custom') : null,
            'sendToken' => (string) Str::uuid(), 'ready' => config('services.roycetalk.manual_live') && $transport->configured()]);
    }
}
