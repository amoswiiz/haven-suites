<section id="booking-communications" class="hs-card mb-4" aria-labelledby="booking-communications-heading">
    <div class="d-flex align-items-center justify-content-between gap-3 flex-wrap">
        <h5 id="booking-communications-heading">Booking communications</h5>
        @if(auth()->user()->can('messaging.manage'))<a class="btn btn-outline-primary" href="{{ route('communications.guests', ['tenant'=>app('currentTenant')->slug, 'reservation_id'=>$id, 'communication_stage'=>'custom']) }}">Custom message</a>@endif
    </div>
    <p>Suggested communication dates for this booking. Staff prepare and send each SMS; automatic scheduling is not active. Email and WhatsApp will be connected later.</p>
    @if(!$workflow['canPrepare'])<p class="alert alert-info">This booking is pending or cancelled. Contact the guest using a custom message when needed.</p>@endif
    <div class="table-responsive"><table class="table align-middle">
        <thead><tr><th>Stage</th><th>Suggested date</th><th>Latest message</th><th>Action</th></tr></thead>
        <tbody>@foreach($workflow['stages'] as $stage)
            <tr><td>{{ $stage['label'] }}</td><td>{{ $stage['date'] ? date('d M Y',strtotime($stage['date'])) : 'After confirmation' }}<div class="small text-muted">{{ $stage['timing'] }}</div></td><td>{{ $stage['status'] }}</td><td>
                @if($stage['uncertain'])<span class="small">Check delivery before retrying</span>
                @elseif($workflow['canPrepare'] && auth()->user()->can('messaging.manage'))<a class="btn btn-sm btn-outline-primary" href="{{ route('communications.guests', ['tenant'=>app('currentTenant')->slug, 'reservation_id'=>$id, 'communication_stage'=>$stage['key']]) }}">Prepare SMS</a>@endif
            </td></tr>
        @endforeach</tbody>
    </table></div>
    <details class="mt-3"><summary class="fw-bold">Communication history ({{ $workflow['messages']->count() }})</summary>
    <p class="small text-muted">Sent to provider means accepted for delivery. Delivered means the provider confirmed receipt. Only messages explicitly linked to this booking appear here.</p>
    @forelse($workflow['messages'] as $message)
        <article class="border rounded p-3 mb-3">
            <div class="d-flex justify-content-between gap-2 flex-wrap"><strong>{{ \App\Services\BookingCommunications::STAGES[$message->communication_stage] ?? 'Custom message' }}</strong><span>{{ \App\Services\BookingCommunications::STATUS_LABELS[$message->status] ?? ucfirst($message->status) }}</span></div>
            <p class="small text-muted mb-2">{{ strtoupper($message->channel) }} · {{ $message->phone }} · {{ $message->display_created_at }} · {{ $message->staff_name }}</p>
            <p class="mb-1" style="white-space:pre-wrap;overflow-wrap:anywhere">{{ $message->body }}</p>
            @if($message->provider_status)<div class="small text-muted">Provider status: {{ $message->provider_status }}</div>@endif
            @if($message->last_error)<div class="small text-danger">{{ $message->last_error }}</div>@endif
        </article>
    @empty<p class="text-muted">No communications recorded for this booking yet.</p>@endforelse
    </details>
</section>
