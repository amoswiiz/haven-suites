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
<section class="card" id="booking-invoice"><h2>Booking invoice</h2><p class="muted">Create the booking, link its number here, then create and issue its invoice. Booking enquiries do not require quotations.</p>
@if(!$lead->reservation_id && auth()->user()->can('sales.manage') && auth()->user()->can('reservations.manage'))
@if(auth()->user()->can('finance.view'))
<h3>Choose an existing invoice</h3>
<p class="muted">Select one invoice below to link its booking to this enquiry. Only invoices for the same guest and stay dates are shown. Bookings already linked to another enquiry are excluded.</p>
<form method="get" action="{{ route('sales.show',[app('currentTenant')->slug,$lead->id]) }}#booking-invoice"><label for="invoice-search">Find an invoice</label><input id="invoice-search" name="invoice_search" value="{{ $invoiceSearch }}" maxlength="150" placeholder="Customer, invoice number, phone or apartment"><div class="actions"><button>Search invoices</button>@if($invoiceSearch)<a href="{{ route('sales.show',[app('currentTenant')->slug,$lead->id]) }}#booking-invoice">Clear search</a>@endif</div></form>
@if($eligibleInvoices->isNotEmpty())
<form method="post" action="{{ route('sales.booking',[app('currentTenant')->slug,$lead->id]) }}">@csrf<input type="hidden" name="version" value="{{ $lead->version }}">
<fieldset style="border:0;padding:0;margin-top:20px"><legend>Available invoices</legend>
<div class="table-wrap"><table><thead><tr><th>Select</th><th>Invoice / customer</th><th>Booking / stay</th><th>Date / status</th><th>Amount</th></tr></thead><tbody>
@foreach($eligibleInvoices as $candidate)
<tr><td><input style="width:auto" type="radio" name="invoice_id" id="candidate-invoice-{{ $candidate->id }}" value="{{ $candidate->id }}" required @checked((string) old('invoice_id') === (string) $candidate->id)></td><td><label for="candidate-invoice-{{ $candidate->id }}">{{ $candidate->invoice_number ?: 'Draft #'.$candidate->id }} — {{ $candidate->customer_name }}</label></td><td>Booking #{{ $candidate->reservation_id }}<br>{{ trim(($candidate->property_name ?? '').' '.($candidate->unit_number ?? '')) ?: 'Partner apartment' }}<br>{{ $candidate->check_in }} to {{ $candidate->check_out }}</td><td>{{ $candidate->issue_date }}<br>{{ ucfirst($candidate->status) }}</td><td>{{ $candidate->currency }} {{ number_format($candidate->total_amount, 2) }}</td></tr>
@endforeach
</tbody></table></div></fieldset><p class="muted">Showing up to 50 matching invoices. Linking does not issue an invoice, record payment or confirm the booking.</p><div class="actions"><button>Link selected invoice &amp; booking</button></div></form>
@else<p>No eligible invoices found. Create a booking and invoice, or check the guest name and stay dates above.</p>@endif
@endif
<details style="margin-top:20px"><summary>Link a booking that has no invoice yet</summary><form method="post" action="{{ route('sales.booking',[app('currentTenant')->slug,$lead->id]) }}">@csrf<input type="hidden" name="version" value="{{ $lead->version }}"><label for="invoice-booking">Booking number</label><input id="invoice-booking" type="number" name="reservation_id" min="1" required><div class="actions"><button>Link booking for invoicing</button></div></form></details>
@elseif($lead->reservation_id)
<p>Linked booking #{{ $lead->reservation_id }}</p>
@if(auth()->user()->can('finance.view'))
@forelse($invoices as $invoice)<p><a class="button secondary" href="{{ site_url('invoices/'.$invoice->id) }}">{{ $invoice->invoice_number ?: 'Draft #'.$invoice->id }}</a> <span class="pill">{{ ucfirst($invoice->status) }}</span></p>@empty
@if(haven_can_access(site_url('invoices/create'),'GET'))<a class="button" href="{{ site_url('invoices/create?reservation_id='.$lead->reservation_id) }}">Create booking invoice</a>@else<p>Ask finance staff to prepare the booking invoice.</p>@endif
@endforelse
@else<p>Finance staff manage the linked booking invoice.</p>@endif
@else<p>Ask reservations staff to create and link the booking.</p>@endif
</section>
@if($quotes->isNotEmpty())<details class="card"><summary>Historical quotations (read-only)</summary><p>Previous documents are retained for reference. They are not required for booking progression.</p>@foreach($quotes as $quote)<p><a href="{{ route('sales.quotation',[app('currentTenant')->slug,$lead->id,$quote->id]) }}">SQ-{{ $quote->id }}</a></p>@endforeach</details>@endif
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
