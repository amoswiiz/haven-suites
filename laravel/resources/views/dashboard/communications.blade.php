<section aria-labelledby="communications-today-heading" class="mb-4">
    <div class="hs-section-head">
        <div><h2 id="communications-today-heading">Communications due today</h2><p class="mb-0 mt-1">{{ $communicationQueue['dateLabel'] }} · Company time · Staff send these messages manually.</p></div>
        <a href="{{ route('communications.due', app('currentTenant')->slug) }}">View all communications due today &rarr;</a>
    </div>
    <div class="hs-work-panel">
        <p><strong>{{ $communicationQueue['readyCount'] }} ready to send</strong>
            @if($communicationQueue['reviewCount']) · <a href="{{ route('communications.due', ['tenant' => app('currentTenant')->slug, 'state' => 'review']) }}">{{ $communicationQueue['reviewCount'] }} delivery {{ $communicationQueue['reviewCount'] === 1 ? 'check' : 'checks' }} needed</a>@endif
        </p>
        @forelse($communicationQueue['rows'] as $message)
            <div class="hs-task d-flex justify-content-between gap-3 flex-wrap">
                <div><strong>{{ $message['label'] }} — {{ $message['guest_name'] }}</strong><p>Booking #{{ $message['booking_id'] }} · {{ $message['apartment'] }}</p><span class="hs-status {{ $message['state'] === 'review' ? 'attention' : '' }}">{{ $message['state'] === 'review' ? 'Check delivery before retrying' : $message['status'] }}</span></div>
                <div>
                    @if($message['state'] === 'ready' && auth()->user()->can('messaging.manage'))
                        <a href="{{ route('communications.guests', ['tenant' => app('currentTenant')->slug, 'reservation_id' => $message['booking_id'], 'communication_stage' => $message['stage']]) }}">{{ $message['message_id'] ? 'Review and retry SMS' : 'Prepare SMS' }} &rarr;</a>
                    @elseif($message['state'] === 'review')
                        <a href="{{ auth()->user()->can('reservations.view') ? site_url('reservations/'.$message['booking_id']).'#booking-communications' : route('communications.sms', app('currentTenant')->slug) }}">Review delivery history &rarr;</a>
                    @else<span class="small text-muted">Sending requires communication management permission.</span>@endif
                </div>
            </div>
        @empty<p class="hs-empty"><span class="hs-status clear">All clear</span><br>No communications need sending or delivery review today.</p>@endforelse
        @if($communicationQueue['totalCount'] > $communicationQueue['rows']->count())<p class="small text-muted mt-3 mb-0">Showing {{ $communicationQueue['rows']->count() }} of {{ $communicationQueue['totalCount'] }} communications. Open the full list to review the rest.</p>@endif
    </div>
</section>
