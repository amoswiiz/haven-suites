<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BookingCommunications
{
    public const STAGES = [
        'confirmation' => 'Booking confirmation',
        'upcoming' => 'Upcoming stay reminder',
        'check_in' => 'Check-in instructions',
        'checkout_reminder' => 'Checkout reminder',
        'review' => 'Thank-you & review request',
        'custom' => 'Custom message',
    ];

    public const STATUS_LABELS = [
        'processing' => 'Sending request', 'submitted' => 'Sent to provider', 'delivered' => 'Delivered',
        'rejected' => 'Rejected', 'unknown' => 'Delivery unknown', 'failed' => 'Failed',
        'undelivered' => 'Not delivered', 'queued' => 'Queued', 'pending' => 'Queued',
    ];

    public function booking(?int $id, ?string $stage): ?object
    {
        if (! $id) {
            if ($stage) {
                throw ValidationException::withMessages(['reservation_id' => 'Select a booking for this communication stage.']);
            }

            return null;
        }
        $booking = DB::connection('tenant')->table('reservations')->where('id', $id)->firstOrFail();
        if ($stage && $stage !== 'custom' && ! in_array($booking->status ?? '', ['confirmed', 'checked_in', 'checked_out'], true)) {
            throw ValidationException::withMessages(['communication_stage' => 'This booking is not confirmed. Use a custom message if you need to contact the guest.']);
        }

        return $booking;
    }

    public function overview(array $booking): array
    {
        abort_unless(auth()->user()?->can('messaging.view'), 403);
        $timezone = app('currentTenant')->timezone ?: config('app.timezone', 'UTC');
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $checkIn = CarbonImmutable::parse($booking['check_in'], $timezone)->startOfDay();
        $checkOut = CarbonImmutable::parse($booking['check_out'], $timezone)->startOfDay();
        $messages = DB::table('manual_sms_messages as m')->leftJoin('users as u', 'u.id', '=', 'm.sent_by')
            ->where('m.tenant_id', app('currentTenant')->id)->where('m.reservation_id', $booking['id'])
            ->select('m.*', 'u.name as staff_name')->latest('m.id')->get();
        foreach ($messages as $message) {
            $message->display_created_at = CarbonImmutable::parse($message->created_at, config('app.timezone', 'UTC'))
                ->setTimezone($timezone)->format('d M Y H:i');
        }
        $dates = ['confirmation' => null, 'upcoming' => $checkIn->subDay(), 'check_in' => $checkIn,
            'checkout_reminder' => $checkOut, 'review' => $checkOut->addDay()];
        $stages = [];
        foreach ($dates as $key => $date) {
            $latest = $messages->firstWhere('communication_stage', $key);
            $stages[] = [
                'key' => $key, 'label' => self::STAGES[$key], 'date' => $date?->toDateString(),
                'timing' => $date ? ($date->lt($today) ? 'Past suggested date' : ($date->isSameDay($today) ? 'Suggested today' : 'Upcoming')) : 'After booking confirmation',
                'status' => $latest ? (self::STATUS_LABELS[$latest->status] ?? ucfirst($latest->status)) : 'No message recorded',
                'uncertain' => $latest && in_array($latest->status, ['unknown', 'processing'], true),
            ];
        }

        return ['stages' => $stages, 'messages' => $messages, 'canPrepare' => in_array($booking['status'] ?? '', ['confirmed', 'checked_in', 'checked_out'], true)];
    }
}
