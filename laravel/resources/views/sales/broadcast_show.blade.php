@extends('saas.layout')
@section('title','Agent message')
@section('content')
@php
    $slug = app('currentTenant')->slug;
    $labels = ['pending' => 'Waiting to send', 'processing' => 'Sending', 'submitted' => 'Sent', 'delivered' => 'Delivered', 'undelivered' => 'Not delivered',
        'failed' => 'Retrying', 'rejected' => 'Rejected', 'unknown' => 'Check with provider', 'blocked' => 'Not sent', 'skipped' => 'Skipped'];
    $totals = $recipients->countBy('status');
@endphp
<p><a href="{{ route('sales.broadcasts', $slug) }}">&larr; Message all agents</a></p>
<h1>{{ \App\Services\AgentBroadcasts::KINDS[$broadcast->kind] ?? 'Agent message' }}</h1>
<p class="muted">Sent {{ \Carbon\Carbon::parse($broadcast->created_at)->format('j M Y, H:i') }} by {{ $broadcast->sender_name ?? 'staff' }} &middot; {{ \App\Services\AgentBroadcasts::CHANNELS[$broadcast->channel] ?? strtoupper($broadcast->channel) }} &middot; {{ $broadcast->segments }} SMS per agent</p>
<section class="card">
    <h2>Message</h2>
    <pre style="white-space:pre-wrap;word-break:break-word;font:inherit;margin:0" id="broadcast-message">{{ $broadcast->message }}</pre>
    <div class="actions"><button type="button" data-copy-message>Copy message</button><span class="muted" data-copy-status aria-live="polite"></span></div>
</section>
<section class="card">
    <h2>Agents</h2>
    <p>@foreach($totals as $statusKey => $total)<span class="pill">{{ $total }} {{ strtolower($labels[$statusKey] ?? $statusKey) }}</span> @endforeach</p>
    @if(($totals['pending'] ?? 0) + ($totals['processing'] ?? 0) > 0)<p class="muted">Messages go out within a minute or two. Refresh this page to see progress.</p>@endif
    <div class="table-wrap"><table><thead><tr><th>Agent</th><th>Number</th><th>Status</th><th>Details</th></tr></thead><tbody>
    @foreach($recipients as $recipient)
        <tr><td>{{ $recipient->agent_name }}</td><td>{{ $recipient->phone ?: '—' }}</td><td>{{ $labels[$recipient->status] ?? ucfirst($recipient->status) }}</td>
            <td class="muted">{{ $recipient->last_error ?: ($recipient->delivered_at ? 'Delivered '.\Carbon\Carbon::parse($recipient->delivered_at)->format('j M, H:i') : ($recipient->sent_at ? 'Sent '.\Carbon\Carbon::parse($recipient->sent_at)->format('j M, H:i') : '')) }}</td></tr>
    @endforeach
    </tbody></table></div>
</section>
@endsection

@push('scripts')
<script>
document.querySelector('[data-copy-message]')?.addEventListener('click', async () => {
    const status = document.querySelector('[data-copy-status]');
    try {
        await navigator.clipboard.writeText(document.getElementById('broadcast-message').textContent.replaceAll('{name}', 'there'));
        status.textContent = 'Copied. Paste it into a WhatsApp group or broadcast list.';
    } catch {
        status.textContent = 'Select the message text and copy it.';
    }
});
</script>
@endpush
