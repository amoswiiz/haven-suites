@extends('saas.layout')
@section('title','Enquiry details')
@section('content')
<p><a href="{{ route('sales.index',app('currentTenant')->slug) }}">&larr; Sales pipeline</a></p>
<div class="split"><div><h1>{{ $lead->name }}</h1><p>{{ $lead->phone }} {{ $lead->email }}</p></div><span class="pill">{{ config('sales.stages.'.$lead->stage) }}</span></div>
<section class="card"><div class="grid"><div><h2>Stay requirements</h2><p>{{ $lead->check_in ?: 'Check-in to confirm' }} &rarr; {{ $lead->check_out ?: 'Check-out to confirm' }}<br>{{ $lead->guests }} guests &middot; {{ $lead->location ?: 'Location to confirm' }}<br>{{ $lead->unit_preference }}</p><p>Budget: {{ $lead->budget===null?'To confirm':app('currentTenant')->currency.' '.number_format($lead->budget,2) }}</p></div><div><h2>Attribution</h2><p>Original source: {{ config('sales.sources.'.$lead->source) }}<br>Current channel: {{ config('sales.channels.'.$lead->channel) }}<br>Campaign: {{ $lead->campaign ?: 'Not recorded' }}</p><p>{{ $lead->notes }}</p></div></div>
<div class="actions">
@if(haven_can_access(site_url('availability'),'GET'))<a class="button secondary" href="{{ site_url('availability') }}">Check availability</a>@endif
@if(haven_can_access(site_url('reservations/create'),'GET'))<a class="button" href="{{ site_url('reservations/create?lead_id='.$lead->id) }}">Create booking from enquiry</a>@endif
@if($lead->reservation_id && haven_can_access(site_url('reservations/'.$lead->reservation_id),'GET'))<a class="button secondary" href="{{ site_url('reservations/'.$lead->reservation_id) }}">Open linked booking #{{ $lead->reservation_id }}</a>@endif
</div></section>
@if(auth()->user()->can('sales.manage'))
<details class="card"><summary>Edit guest and stay requirements</summary>
<form method="post" action="{{ route('sales.details',[app('currentTenant')->slug,$lead->id]) }}">
@csrf
<input type="hidden" name="version" value="{{ $lead->version }}">
<div class="grid">
@foreach(['name'=>'Guest name','phone'=>'Phone','email'=>'Email','location'=>'Preferred location','unit_preference'=>'Preferred apartment'] as $key=>$label)
<div><label for="detail-{{ $key }}">{{ $label }}</label><input id="detail-{{ $key }}" name="{{ $key }}" value="{{ old($key,$lead->$key) }}" @required($key==='name')></div>
@endforeach
<div><label for="detail-check-in">Check-in</label><input id="detail-check-in" name="check_in" type="date" value="{{ old('check_in',$lead->check_in) }}"></div>
<div><label for="detail-check-out">Check-out</label><input id="detail-check-out" name="check_out" type="date" value="{{ old('check_out',$lead->check_out) }}"></div>
<div><label for="detail-guests">Guests</label><input id="detail-guests" name="guests" type="number" min="1" max="1000" value="{{ old('guests',$lead->guests) }}" required></div>
<div><label for="detail-budget">Stay budget</label><input id="detail-budget" name="budget" type="number" min="0" step="0.01" value="{{ old('budget',$lead->budget) }}"></div>
</div><div class="actions"><button>Save guest requirements</button></div></form></details>
<form method="post" action="{{ route('sales.update',[app('currentTenant')->slug,$lead->id]) }}" class="card">@csrf<input type="hidden" name="version" value="{{ $lead->version }}"><h2>Stage and next action</h2><div class="grid"><div><label for="stage">Stage</label><select id="stage" name="stage">@if($lead->stage==='won')<option value="won" selected disabled>Won / Booking confirmed</option>@endif
@foreach(config('sales.stages') as $key=>$label)@if($key!=='won')<option value="{{ $key }}" @selected(old('stage',$lead->stage)===$key)>{{ $label }}</option>@endif
@endforeach</select><p class="muted">Use Confirm booking to move to Won. Reopening a won enquiry preserves its booking link.</p></div><div><label for="assigned_to">Assigned salesperson</label><select id="assigned_to" name="assigned_to">@foreach($members as $member)<option value="{{ $member->id }}" @selected(old('assigned_to',$lead->assigned_to)==$member->id)>{{ $member->name }}</option>@endforeach</select></div></div>
<label for="next_action">Next action</label><input id="next_action" name="next_action" maxlength="255" value="{{ old('next_action',$lead->next_action) }}" required><label for="follow_up_at">Follow up ({{ config('app.timezone') }})</label><input id="follow_up_at" type="datetime-local" name="follow_up_at" value="{{ old('follow_up_at',$lead->follow_up_at?date('Y-m-d\TH:i',strtotime($lead->follow_up_at)):'') }}"><label for="lost_reason">Lost / postponed reason (required when closing as lost)</label><input id="lost_reason" name="lost_reason" value="{{ old('lost_reason',$lead->lost_reason) }}" maxlength="255"><div class="actions"><button>Save stage and follow-up</button></div></form>
@endif
<section class="card"><h2>Quotations</h2><p class="muted">Preparing or sharing a quotation does not reserve an apartment. Availability must be checked before booking.</p>
@foreach($quotes as $quote)<p><a href="{{ route('sales.quotation',[app('currentTenant')->slug,$lead->id,$quote->id]) }}">SQ-{{ $quote->id }} &middot; {{ app('currentTenant')->currency }} {{ number_format($quote->amount,2) }} &middot; Valid until {{ $quote->valid_until }}</a></p>@endforeach
@if(auth()->user()->can('sales.manage'))<details><summary>Prepare quotation</summary><form method="post" action="{{ route('sales.quote',[app('currentTenant')->slug,$lead->id]) }}">@csrf<div class="grid"><div><label for="amount">Total stay price ({{ app('currentTenant')->currency }})</label><input type="number" id="amount" name="amount" min="0.01" step="0.01" required></div><div><label for="valid_until">Valid until</label><input type="date" id="valid_until" name="valid_until" min="{{ date('Y-m-d') }}" value="{{ date('Y-m-d',strtotime('+3 days')) }}" required></div></div><label for="description">Apartment options, inclusions and terms</label><textarea id="description" name="description" rows="4" maxlength="5000" required></textarea><div class="actions"><button>Prepare printable quotation</button></div></form></details>@endif
</section>
@if(auth()->user()->can('sales.manage') && auth()->user()->can('reservations.manage') && \App\Support\CompanyModules::enabled('finance'))
<details class="card"><summary>Confirm booking / Mark won</summary><p class="muted">Link a confirmed booking after checking the guest and dates. Only recorded guest payments count as verified payment; message screenshots do not.</p><form method="post" action="{{ route('sales.win',[app('currentTenant')->slug,$lead->id]) }}">@csrf<label for="reservation_id">Reservation number</label><input id="reservation_id" type="number" name="reservation_id" value="{{ $lead->reservation_id }}" min="1" required><label for="required_deposit">Required deposit ({{ app('currentTenant')->currency }})</label><input id="required_deposit" name="required_deposit" type="number" min="0.01" step="0.01" value="{{ $lead->required_deposit }}" required>
@if(auth()->user()->can('finance.manage'))<label for="payment_arrangement">Approved payment arrangement (if deposit is not met)</label><textarea id="payment_arrangement" name="payment_arrangement" maxlength="2000">{{ $lead->payment_arrangement }}</textarea>@endif
<div class="actions"><button>Verify payment and confirm booking</button></div></form></details>
@endif
<div class="grid">@if(auth()->user()->can('messaging.view'))<section class="card"><h2>Conversation history</h2><p class="muted">Manual conversation records. WhatsApp and Instagram sending and automatic capture will be connected next.</p>
@foreach($identities as $identity)<p class="pill">{{ $identity->channel }}: {{ $identity->external_id }}</p>@endforeach
@forelse($messages as $message)<article style="border-top:1px solid #dfe5ef;padding:12px 0"><strong>{{ ucfirst($message->direction) }} / {{ config('sales.channels.'.$message->channel) }}</strong><p style="white-space:pre-wrap">{{ $message->body }}</p><small>{{ $message->received_at }} &middot; Manually recorded</small></article>@empty<p>No conversations recorded.</p>@endforelse
@if(auth()->user()->can('sales.manage') && auth()->user()->can('messaging.manage'))<details><summary>Record a conversation</summary><form method="post" action="{{ route('sales.message',[app('currentTenant')->slug,$lead->id]) }}">@csrf<label for="channel">Channel</label><select id="channel" name="channel">@foreach(config('sales.channels') as $key=>$label)<option value="{{ $key }}" @selected($key===$lead->channel)>{{ $label }}</option>@endforeach</select><label for="direction">Direction</label><select id="direction" name="direction"><option value="inbound">Guest contacted us</option><option value="outbound">We contacted guest</option></select><label for="external_id">Channel identity (optional phone or account ID)</label><input id="external_id" name="external_id" maxlength="255"><label><input style="width:auto" type="checkbox" name="consent" value="1"> Guest consent to follow-up recorded</label><label for="message-body">Conversation</label><textarea id="message-body" name="body" rows="4" maxlength="5000" required></textarea><div class="actions"><button>Save conversation record</button></div></form></details>@endif
</section>
@endif
<section class="card"><h2>Activity and notes</h2>
@if(auth()->user()->can('sales.manage'))<form method="post" action="{{ route('sales.note',[app('currentTenant')->slug,$lead->id]) }}">@csrf<label for="note-body">Add a note</label><textarea id="note-body" name="body" maxlength="5000" rows="3" required></textarea><div class="actions"><button>Save note</button></div></form>@endif
@foreach($activities as $activity)<article style="border-top:1px solid #dfe5ef;margin-top:12px;padding-top:12px"><small>{{ $activity->created_at }} &middot; {{ ucfirst($activity->type) }}</small><p style="white-space:pre-wrap">{{ $activity->body }}</p></article>@endforeach
</section></div>
@endsection
