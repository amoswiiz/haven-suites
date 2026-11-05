@extends('saas.layout')
@section('title', 'Communications due today')
@section('content')
@include('communications.tabs')
<h1>Communications due today</h1>
<p>{{ $communicationQueue['dateLabel'] }} · Company time. Staff review and send each SMS manually.</p>
<div class="grid">
    <section class="card"><h2>Ready to send</h2><strong class="price">{{ $communicationQueue['readyCount'] }}</strong><p>Unsent messages and failed delivery attempts that can be reviewed and retried.</p></section>
    <section class="card"><h2>Delivery checks needed</h2><strong class="price">{{ $communicationQueue['reviewCount'] }}</strong><p>Sending or uncertain messages need a delivery check before another message is sent.</p></section>
</div>
<details class="card"><summary>Which messages appear here?</summary>
    <ul><li>Booking confirmations for confirmed bookings created today, where the stay has not ended.</li><li>Upcoming-stay reminders for guests arriving tomorrow.</li><li>Check-in instructions for confirmed guests arriving today.</li><li>Checkout reminders for guests due to leave today who have not yet been checked out.</li><li>Thank-you and review requests for bookings marked checked out with a checkout date of yesterday.</li></ul>
    <p>Messages already accepted by the provider, delivered or queued are excluded. Cancelled and pending bookings are excluded. Only messages explicitly linked to the booking and communication stage count toward its history.</p>
</details>
<form method="get" action="{{ route('communications.due', app('currentTenant')->slug) }}" class="card">
    <label for="due-state">Show</label><select id="due-state" name="state">
        @foreach(['all' => 'All communications needing attention', 'ready' => 'Ready to send', 'review' => 'Delivery checks needed'] as $value => $label)<option value="{{ $value }}" @selected($state === $value)>{{ $label }}</option>@endforeach
    </select><div class="actions"><button type="submit">Apply filter</button></div>
</form>
<section class="card"><div class="table-wrap"><table>
    <thead><tr><th>Message</th><th>Guest / apartment</th><th>Status</th><th>Next action</th></tr></thead>
    <tbody>@forelse($rows as $message)
        <tr><td><strong>{{ $message['label'] }}</strong><br><small>Due {{ $message['date'] }}</small></td>
            <td>{{ $message['guest_name'] }}<br>{{ $message['apartment'] }}<br>
                @if(auth()->user()->can('reservations.view'))<a href="{{ site_url('reservations/'.$message['booking_id']).'#booking-communications' }}">Booking #{{ $message['booking_id'] }}</a>@else<small>Booking #{{ $message['booking_id'] }}</small>@endif
            </td>
            <td><span class="pill">{{ $message['status'] }}</span>@if($message['state'] === 'review')<p>Check delivery before retrying.</p>@endif @if($message['provider_reference'])<small>Provider reference: {{ $message['provider_reference'] }}</small>@endif</td>
            <td>@if($message['state'] === 'ready' && auth()->user()->can('messaging.manage'))<a class="button" href="{{ route('communications.guests', ['tenant' => app('currentTenant')->slug, 'reservation_id' => $message['booking_id'], 'communication_stage' => $message['stage']]) }}">{{ $message['message_id'] ? 'Review and retry SMS' : 'Prepare SMS' }}</a>
                @elseif($message['state'] === 'review')<a href="{{ auth()->user()->can('reservations.view') ? site_url('reservations/'.$message['booking_id']).'#booking-communications' : route('communications.sms', app('currentTenant')->slug) }}">Review delivery history</a>
                @else<span class="muted">Sending requires communication management permission.</span>@endif
            </td></tr>
    @empty<tr><td colspan="4">No communications match this filter.</td></tr>@endforelse</tbody>
</table></div>{{ $rows->links() }}</section>
@endsection
