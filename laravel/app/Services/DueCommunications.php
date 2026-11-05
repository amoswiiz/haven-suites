<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DueCommunications
{
    private const PRIORITY = ['checkout_reminder' => 0, 'check_in' => 1, 'upcoming' => 2, 'confirmation' => 3, 'review' => 4];

    public function summary(int $limit = 5): array
    {
        $items = $this->items();
        $today = CarbonImmutable::now(app('currentTenant')->timezone ?: config('app.timezone', 'UTC'));

        return [
            'date' => $today->toDateString(),
            'dateLabel' => $today->format('l, j F Y'),
            'readyCount' => $items->where('state', 'ready')->count(),
            'reviewCount' => $items->where('state', 'review')->count(),
            'totalCount' => $items->count(),
            'rows' => $items->take(max(0, $limit))->values(),
        ];
    }

    public function items(): Collection
    {
        abort_unless(auth()->user()?->active && auth()->user()->can('messaging.view'), 403);
        $timezone = app('currentTenant')->timezone ?: config('app.timezone', 'UTC');
        $storageTimezone = config('app.timezone', 'UTC');
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $date = $today->toDateString();
        $tomorrow = $today->addDay()->toDateString();
        $yesterday = $today->subDay()->toDateString();
        $start = $today->setTimezone($storageTimezone)->format('Y-m-d H:i:s');
        $end = $today->addDay()->setTimezone($storageTimezone)->format('Y-m-d H:i:s');

        $bookings = DB::connection('tenant')->table('reservations as r')
            ->leftJoin('units as u', 'u.id', '=', 'r.unit_id')
            ->leftJoin('properties as p', 'p.id', '=', 'u.property_id')
            ->leftJoin('partner_apartments as pa', 'pa.id', '=', 'r.partner_apartment_id')
            ->select('r.id', 'r.unit_id', 'r.guest_name', 'r.status', 'r.check_in', 'r.check_out', 'r.created_at',
                'u.unit_number', 'p.name as property_name', 'pa.apartment_name as partner_name', 'pa.unit_number as partner_unit')
            ->where(function (Builder $query) use ($date, $tomorrow, $yesterday, $start, $end): void {
                $query->where(function (Builder $confirmed) use ($date, $tomorrow, $start, $end): void {
                    $confirmed->where('r.status', 'confirmed')->where(function (Builder $due) use ($date, $tomorrow, $start, $end): void {
                        $due->whereIn('r.check_in', [$date, $tomorrow])
                            ->orWhere(fn (Builder $new) => $new->where('r.created_at', '>=', $start)->where('r.created_at', '<', $end)->where('r.check_out', '>=', $date));
                    });
                })->orWhere(function (Builder $departures) use ($date): void {
                    $departures->where('r.check_out', $date)->whereIn('r.status', ['confirmed', 'checked_in']);
                })->orWhere(function (Builder $reviews) use ($yesterday): void {
                    $reviews->where('r.check_out', $yesterday)->where('r.status', 'checked_out');
                });
            })->orderBy('r.id')->get();

        if ($bookings->isEmpty()) {
            return collect();
        }
        $latest = [];
        $messages = DB::table('manual_sms_messages')->where('tenant_id', app('currentTenant')->id)
            ->whereIn('reservation_id', $bookings->pluck('id'))->whereIn('communication_stage', array_keys(self::PRIORITY))
            ->select('id', 'reservation_id', 'communication_stage', 'status', 'provider_reference', 'provider_status')
            ->orderByDesc('id')->get();
        foreach ($messages as $message) {
            $key = $message->reservation_id.'|'.$message->communication_stage;
            $latest[$key] ??= $message;
        }

        $items = collect();
        foreach ($bookings as $booking) {
            $stages = [];
            if ($booking->status === 'confirmed') {
                if ($booking->check_out >= $date && $booking->created_at && CarbonImmutable::parse($booking->created_at, $storageTimezone)->setTimezone($timezone)->toDateString() === $date) {
                    $stages[] = 'confirmation';
                }
                if ($booking->check_in === $tomorrow) {
                    $stages[] = 'upcoming';
                }
                if ($booking->check_in === $date) {
                    $stages[] = 'check_in';
                }
            }
            if (in_array($booking->status, ['confirmed', 'checked_in'], true) && $booking->check_out === $date) {
                $stages[] = 'checkout_reminder';
            }
            if ($booking->status === 'checked_out' && $booking->check_out === $yesterday) {
                $stages[] = 'review';
            }

            foreach ($stages as $stage) {
                $message = $latest[$booking->id.'|'.$stage] ?? null;
                $status = $message ? strtolower($message->status) : null;
                if (in_array($status, ['submitted', 'delivered', 'queued', 'pending'], true)) {
                    continue;
                }
                $state = $message && ! in_array($status, ['rejected', 'failed', 'undelivered'], true) ? 'review' : 'ready';
                $items->push([
                    'booking_id' => (int) $booking->id,
                    'guest_name' => $booking->guest_name,
                    'apartment' => $booking->unit_number
                        ? ($booking->property_name ?: 'Apartment').' / '.$booking->unit_number
                        : trim(($booking->partner_name ?: 'Partner apartment').($booking->partner_unit ? ' / '.$booking->partner_unit : '')),
                    'stage' => $stage,
                    'label' => BookingCommunications::STAGES[$stage],
                    'date' => $date,
                    'state' => $state,
                    'status' => $message ? (BookingCommunications::STATUS_LABELS[$status] ?? 'Delivery status needs review') : 'Not sent yet',
                    'message_status' => $status,
                    'message_id' => $message?->id,
                    'provider_reference' => $message?->provider_reference,
                    'priority' => self::PRIORITY[$stage],
                ]);
            }
        }

        return $items->sortBy([['priority', 'asc'], ['booking_id', 'asc']])->values();
    }
}
