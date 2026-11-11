@extends('saas.layout')
@section('title','Message all agents')
@section('content')
@php($slug = app('currentTenant')->slug)
<p><a href="{{ route('sales.agents', $slug) }}">&larr; Referral agents</a></p>
<h1>Message all agents</h1>
<p class="muted">Tell your referral agents about available units or offers in one SMS. Each agent gets their own message, starting with their first name. WhatsApp will be added here once the WhatsApp Business connection is set up.</p>
@if(!$smsAvailable)<div class="card" role="status"><strong>SMS sending is not switched on yet.</strong> You can prepare a message, but sending needs RoyceTalk SMS to be enabled for this company.</div>@endif

<form method="post" action="{{ route('sales.broadcasts.store', $slug) }}" data-broadcast-form>
@csrf
<div class="grid broadcast-grid">
    <section class="card">
        <h2>1. Write the message</h2>
        <label for="kind">Type of message</label>
        <select id="kind" name="kind" data-broadcast-kind>@foreach(\App\Services\AgentBroadcasts::KINDS as $kindKey => $kindLabel)<option value="{{ $kindKey }}" @selected(old('kind', 'availability') === $kindKey)>{{ $kindLabel }}</option>@endforeach</select>
        <p><button type="button" data-broadcast-template>Start from the template</button></p>
        @if($listings->isNotEmpty())
            <fieldset class="broadcast-listings"><legend>Units to include <small class="muted">(up to 10, added where <code>{listings}</code> appears)</small></legend>
                <p class="muted broadcast-listing-help">Change a price to quote an offer in this message only. Leave it blank to leave the price out.</p>
                @foreach($listings as $listing)
                    @php($priceValue = old('listing_prices.'.$listing['id'], $listing['price'] !== null ? (string) round($listing['price']) : ''))
                    <div class="broadcast-listing" data-listing-row data-title="{{ $listing['title'] }}" data-summary="{{ $listing['summary'] }}" data-currency="{{ $listing['currency'] }}" data-url="{{ $listing['short_url'] }}">
                        <label><input style="width:auto" type="checkbox" name="listing_ids[]" value="{{ $listing['id'] }}" @checked(in_array($listing['id'], array_map('intval', old('listing_ids', [])), true))> {{ $listing['title'] }} <span class="muted">{{ $listing['summary'] }}</span></label>
                        <span class="broadcast-price"><label class="muted" for="price-{{ $listing['id'] }}">{{ $listing['currency'] }}</label><input id="price-{{ $listing['id'] }}" name="listing_prices[{{ $listing['id'] }}]" type="number" min="0" step="1" inputmode="numeric" value="{{ $priceValue }}" aria-label="Price per night for {{ $listing['title'] }}"><span class="muted">/night</span></span>
                    </div>
                @endforeach
            </fieldset>
        @else
            <p class="muted">Publish listings on your website to include them with their links.</p>
        @endif
        <label for="message">Message</label>
        <textarea id="message" name="message" rows="8" required maxlength="{{ \App\Services\AgentBroadcasts::MAX_LENGTH }}" data-broadcast-message>{{ old('message', $templates['availability']) }}</textarea>
        <small class="muted"><code>{name}</code> becomes each agent's first name. <code>{listings}</code> becomes the units you ticked.</small>
        <h3>Preview</h3>
        <pre class="broadcast-preview" data-broadcast-preview aria-live="polite"></pre>
        <p class="muted" data-broadcast-cost></p>
    </section>
    <section class="card">
        <h2>2. Choose agents</h2>
        @if($agents->isEmpty())
            <p>No active agents yet. <a href="{{ route('sales.agents', $slug) }}">Add agents first.</a></p>
        @else
            <p><button type="button" data-select-agents="all">Select all</button> <button type="button" data-select-agents="none">Clear</button></p>
            <div class="broadcast-agents">
            @foreach($agents as $agent)
                @php($canReceive = $agent->mobile && !$agent->broadcast_opt_out)
                <label class="{{ $canReceive ? '' : 'muted' }}"><input style="width:auto" type="checkbox" name="agent_ids[]" value="{{ $agent->id }}" data-agent-name="{{ $agent->name }}" @disabled(!$canReceive) @checked($canReceive && (old('agent_ids') === null || in_array($agent->id, array_map('intval', old('agent_ids', [])), true)))> {{ $agent->name }}
                    <span class="muted">{{ $agent->broadcast_opt_out ? 'Opted out of broadcasts' : ($agent->mobile ?: 'No mobile number') }}</span></label>
            @endforeach
            </div>
        @endif
    </section>
</div>
<section class="card">
    <h2>3. Send</h2>
    <label><input style="width:auto" type="checkbox" name="confirm" value="1" required> I have checked the message and want to send it by SMS to the selected agents</label>
    <div class="actions"><button class="button" @disabled(!$smsAvailable || $agents->isEmpty())>Send SMS</button><span class="muted" data-broadcast-summary></span></div>
</section>
</form>

<section class="card">
    <h2>Sent messages</h2>
    <div class="table-wrap"><table><thead><tr><th>Sent</th><th>Type</th><th>Message</th><th>Agents</th><th>Status</th></tr></thead><tbody>
    @forelse($history as $broadcast)
        @php($status = $counts[$broadcast->id] ?? [])
        <tr>
            <td><a href="{{ route('sales.broadcasts.show', [$slug, $broadcast->id]) }}">{{ \Carbon\Carbon::parse($broadcast->created_at)->format('j M Y, H:i') }}</a><br><span class="muted">{{ $broadcast->sender_name ?? 'Staff' }}</span></td>
            <td>{{ \App\Services\AgentBroadcasts::KINDS[$broadcast->kind] ?? ucfirst($broadcast->kind) }}</td>
            <td>{{ \Illuminate\Support\Str::limit($broadcast->message, 90) }}</td>
            <td>{{ $broadcast->recipient_count }}</td>
            <td>@foreach(['delivered' => 'delivered', 'submitted' => 'sent', 'pending' => 'waiting', 'skipped' => 'skipped'] as $statusKey => $statusLabel)@if(!empty($status[$statusKey]))<span class="pill">{{ $status[$statusKey] }} {{ $statusLabel }}</span> @endif @endforeach
                @php($problems = array_sum(array_intersect_key($status, array_flip(['failed', 'rejected', 'undelivered', 'unknown', 'blocked']))))@if($problems)<span class="pill">{{ $problems }} not sent</span>@endif</td>
        </tr>
    @empty
        <tr><td colspan="5">No messages sent to agents yet.</td></tr>
    @endforelse
    </tbody></table></div>{{ $history->links() }}
</section>
@endsection

@push('styles')
<style>
.broadcast-grid{grid-template-columns:repeat(auto-fit,minmax(min(320px,100%),1fr));align-items:start}
.broadcast-listings{border:1px solid #dfe5ef;border-radius:10px;padding:10px 14px;margin:12px 0}
.broadcast-listings label,.broadcast-agents label{display:block;margin:6px 0;font-weight:normal}
.broadcast-listing-help{margin:0 0 6px;font-size:13px}
.broadcast-listing{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:6px 14px;padding:6px 0;border-top:1px solid #eef2f7}
.broadcast-listing:first-of-type{border-top:0}
.broadcast-listing>label{flex:1 1 220px;margin:0}
.broadcast-price{display:inline-flex;align-items:center;gap:6px}
.broadcast-price label{display:inline;margin:0}
.broadcast-price input{width:110px;margin:0}
.broadcast-agents{max-height:420px;overflow:auto}
.broadcast-preview{white-space:pre-wrap;word-break:break-word;background:#f4f6fa;border:1px solid #dfe5ef;border-radius:10px;padding:12px;font:inherit;margin:0}
</style>
@endpush

@push('scripts')
<script>
(() => {
    const form = document.querySelector('[data-broadcast-form]');
    if (!form) return;
    const templates = @json($templates);
    const message = form.querySelector('[data-broadcast-message]');
    const kind = form.querySelector('[data-broadcast-kind]');
    const preview = form.querySelector('[data-broadcast-preview]');
    const cost = form.querySelector('[data-broadcast-cost]');
    const summary = form.querySelector('[data-broadcast-summary]');
    const gsm = "@£$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";
    const extended = "^{}\\[~]|€";
    const segments = (text) => {
        let length = 0;
        for (const character of text) {
            if (extended.includes(character)) { length += 2; continue; }
            if (!gsm.includes(character)) { const units = text.length; return units <= 70 ? 1 : Math.ceil(units / 67); }
            length++;
        }
        return length <= 160 ? 1 : Math.ceil(length / 153);
    };
    const render = () => {
        const lines = [...form.querySelectorAll('[data-listing-row]')].filter((row) => row.querySelector('input[name="listing_ids[]"]').checked).map((row) => {
            const price = parseFloat(row.querySelector('input[type="number"]').value);
            return '- ' + row.dataset.title + (row.dataset.summary ? ` (${row.dataset.summary})` : '')
                + (price > 0 ? ` ${row.dataset.currency} ${Math.round(price).toLocaleString('en-US')}/night` : '') + ' ' + row.dataset.url;
        }).join('\n');
        const agents = [...form.querySelectorAll('input[name="agent_ids[]"]:checked')];
        const firstName = (agents[0]?.dataset.agentName || 'Agent').trim().split(/\s+/)[0];
        let text = message.value.replace(/\r\n/g, '\n').replace(/^[ \t]*\{listings\}[ \t]*\n?/m, lines ? lines + '\n' : '').replace('{listings}', lines).trim();
        const final = text.replaceAll('{name}', firstName);
        preview.textContent = final || 'Your message will appear here.';
        const parts = segments(final);
        cost.textContent = `${[...final].length} characters · ${parts} SMS per agent` + (parts > 1 ? ' (long messages are sent in parts and cost more)' : '') + '.';
        summary.textContent = `${agents.length} agent${agents.length === 1 ? '' : 's'} selected · about ${parts * agents.length} SMS in total.`;
    };
    form.querySelector('[data-broadcast-template]').addEventListener('click', () => {
        if (message.value.trim() !== '' && !Object.values(templates).includes(message.value) && !confirm('Replace your message with the template?')) return;
        message.value = templates[kind.value] || '';
        render();
        message.focus();
    });
    kind.addEventListener('change', () => { if (Object.values(templates).includes(message.value) || message.value.trim() === '') { message.value = templates[kind.value] || ''; render(); } });
    form.querySelectorAll('[data-select-agents]').forEach((button) => button.addEventListener('click', () => {
        form.querySelectorAll('input[name="agent_ids[]"]:not(:disabled)').forEach((box) => { box.checked = button.dataset.selectAgents === 'all'; });
        render();
    }));
    form.addEventListener('input', render);
    form.addEventListener('change', render);
    render();
})();
</script>
@endpush
