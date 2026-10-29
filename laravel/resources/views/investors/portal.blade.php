@extends('saas.layout')
@section('title', $section==='money' ? 'Statements & Payments' : 'Investor portal')
@section('content')
@include('investors.overview')
@if($section==='bookings')
@include('investors.calendar')
@endif
@if(in_array($section,['dashboard','settings']))
@include('investors.support')
@endif
@if($section==='dashboard')
<section class="card"><h2>Monthly report summary</h2>

<p class="muted">{{ date('F Y',strtotime($reportMonth.'-01')) }} / {{ $selectedUnitId ? 'Selected unit' : 'All your units' }}. Booking tabs below show all dates.</p>
@if($bookingLists)
<div class="grid"><div><h3>Bookings in month</h3><strong>{{ $monthly['bookings'] }}</strong></div><div><h3>Booked nights</h3><strong>{{ $monthly['nights'] }}</strong></div><div><h3>Gross booking value</h3><strong>{{ app('currentTenant')->currency }} {{ number_format($monthly['value_minor']/100,2) }}</strong></div></div>
<p class="muted">Confirmed, checked-in and checked-out stays only. Prices are allocated to nights within the month; check-out day is excluded. These are full guest booking values, including future nights, not cash collected or your investor payout. Pending and cancelled bookings are excluded.</p>
@endif
<details><summary>View financial calculation</summary><h3>Published investor financials</h3><p class="muted">Net entitlement = owner guest collections + fixed landlord rent âˆ’ management fees âˆ’ owner-deductible expenses. Approved historical terms are retained.</p>
@if($monthly['published_count'])
<p class="muted">{{ $monthly['published_count'] }} published unit statement(s). Amounts below already reflect your ownership share. Unpublished units are excluded.</p><div class="table-wrap"><table><tbody>
@foreach(['collected'=>'Owner guest collections','rental_income'=>'Fixed rent owed to landlord','management_fee'=>'Management fees','expenses'=>'Owner-deductible expenses','entitlement'=>'Net entitlement for month','closing_balance'=>'Closing balance including carry-forward'] as $key=>$label)<tr><th>{{ $label }}</th><td>{{ app('currentTenant')->currency }} {{ number_format($monthly['published_totals'][$key]/100,2) }}</td></tr>@endforeach
</tbody></table></div>
@else
<p class="muted">No investor statements published for this month and unit selection. Financial totals will appear after management publishes the statements.</p>
@endif
<p><strong>Payouts published with payment dates in this month:</strong> {{ app('currentTenant')->currency }} {{ number_format($monthly['payout_minor']/100,2) }}</p><p class="muted">Payouts follow payment dates and may relate to earlier accounting months.</p></details>
</section>

@endif

@if(in_array($section,['dashboard','properties','agreements']))
<section class="card" id="properties">@if($section==='agreements')<p>Ownership dates and references are shown below. Contact management for the signed agreement document.</p>@endif<h2>{{ $section==='agreements' ? 'Agreement details' : 'Your properties' }}</h2><div class="grid">@forelse($assignments as $assignment)@php($unit=$units->firstWhere('id',$assignment->unit_id))<article class="investor-property"><p><strong>{{ $unit?->property_name }} / {{ $unit?->unit_number }}</strong><br>{{ $assignment->share_basis_points/100 }}% share &middot; {{ $assignment->starts_on }} to {{ $assignment->ends_on ?: 'Ongoing' }}<br><span class="pill">{{ app(\App\Services\UnitAccounting::class)->mode($assignment->unit_id,$reportMonth.'-01') === 'leased' ? 'Fixed landlord rent' : 'Managed unit' }}</span><br>Agreement: {{ $assignment->agreement_reference }}<br><a href="{{ route('investor.section',[app('currentTenant')->slug,'agreements','unit_id'=>$assignment->unit_id]) }}">View agreement details</a></p></article>@empty<p>No properties assigned yet. Contact management.</p>@endforelse</div></section>
@endif
@if($bookingLists && $section==='dashboard')
<section class="card"><h2 id="investor-bookings-heading">Your unit bookings</h2><p><a class="button secondary" href="{{ route('investor.section',[app('currentTenant')->slug,'bookings','month'=>$reportMonth,'unit_id'=>$selectedUnitId]) }}">Open booking calendar</a></p><p class="muted">Booking tabs show all dates for the selected unit. Financial cards use the selected month.</p><div class="actions" aria-label="Booking views">@foreach(['current'=>'Current stays','upcoming'=>'Upcoming','completed'=>'Completed'] as $key=>$label)<a class="button {{ request('tab','current')===$key ? '' : 'secondary' }}" href="{{ route('investor.portal',[app('currentTenant')->slug,'month'=>$reportMonth,'unit_id'=>$selectedUnitId,'tab'=>$key]) }}#bookings" @if(request('tab','current')===$key) aria-current="true" @endif>{{ $label }}</a>@endforeach</div></section>
@foreach($bookingLists as $heading=>$bookings)
@if($heading===['current'=>'Currently checked in','upcoming'=>'Upcoming bookings','completed'=>'Checked-out bookings'][request('tab','current')])
<section class="card" id="bookings"><h2>{{ $heading }} <span class="pill">{{ $bookings->total() }}</span></h2><div class="table-wrap"><table><thead><tr><th>Booking</th><th>Unit</th><th>Check-in</th><th>Check-out</th><th>Nights</th><th>Nightly rate</th><th>Total stay price</th><th>Status</th></tr></thead><tbody>
@forelse($bookings as $booking)
@php($unit=$units->firstWhere('id',$booking->unit_id))
<tr><td data-label="Booking">#{{ $booking->id }}</td><td data-label="Unit">{{ $unit?->property_name }} / {{ $unit?->unit_number }}</td><td data-label="Check-in">{{ date('d M Y',strtotime($booking->check_in)) }}</td><td data-label="Check-out">{{ date('d M Y',strtotime($booking->check_out)) }}</td><td data-label="Nights">{{ (new \DateTimeImmutable($booking->check_in))->diff(new \DateTimeImmutable($booking->check_out))->days }}</td><td data-label="Nightly rate">{{ app('currentTenant')->currency }} {{ number_format($booking->nightly_rate,2) }}</td><td data-label="Total stay price">{{ app('currentTenant')->currency }} {{ number_format($booking->total_amount,2) }}</td><td data-label="Status"><span class="pill">{{ ucwords(str_replace('_',' ',$booking->status)) }}</span></td></tr>
@empty
<tr><td colspan="8">No {{ strtolower($heading) }} for the selected units.</td></tr>
@endforelse
</tbody></table></div>{{ $bookings->links() }}</section>
@endif
@endforeach
@endif
@if($section==='money')
<p class="muted">See how your money was calculated and the payments management has recorded.</p>
<div class="investor-metrics" style="grid-template-columns:repeat(auto-fit,minmax(220px,1fr))">
@foreach(['entitlement'=>'Your earnings for the month','payments'=>'Payments received during the month','closing_balance'=>'Published closing balance'] as $key=>$label)
<article class="investor-metric"><span>{{ $label }}</span><strong>@if($key!=='payments' && !$monthly['published_count'])Awaiting statement @else{{ app('currentTenant')->currency }} {{ number_format(($key==='payments' ? $monthly['payout_minor'] : $monthly['published_totals'][$key])/100,2) }}@endif</strong><small>{{ $key==='payments' ? 'Published payment records, by payment date' : ($key==='closing_balance' ? 'Includes earlier balances and payments allocated in the published statement' : 'Your published entitlement after applicable fees and deductions') }}</small></article>
@endforeach
</div>
<p class="investor-note">{{ date('F Y',strtotime($reportMonth.'-01')) }}: {{ $monthly['published_count'] }} statement(s) published for {{ $monthly['expected_units'] }} eligible unit(s). Missing statements are excluded. Payments this month may settle earlier months, so earnings minus payments may not equal the closing balance. Later payments appear when the statement is updated. A negative balance carries forward and does not automatically mean you owe a payment.</p>
<nav class="actions" aria-label="Financial history period">
@foreach(['month'=>'Selected month','all'=>'All history'] as $value=>$label)<a class="button {{ request('history','month')===$value ? '' : 'secondary' }}" href="{{ route('investor.section',[app('currentTenant')->slug,'money','month'=>$reportMonth,'unit_id'=>$selectedUnitId,'history'=>$value]) }}" @if(request('history','month')===$value) aria-current="true" @endif>{{ $label }}</a>@endforeach
<a href="#monthly-statements">Monthly statements</a><a href="#payment-history">Payment history</a>
</nav><p class="muted">{{ $filterMoneyMonth ? 'Both lists show the selected month and property.' : 'Both lists show all dates for the selected property. Summary cards still use the selected month.' }}</p>
@endif
@if(in_array($section,['dashboard','money']))
<section class="card" id="monthly-statements"><p class="muted">{{ $section==='money' && $filterMoneyMonth ? 'Published statements for the selected month.' : 'Statement history for your selected properties.' }} Open a statement to see its calculation or download the PDF.</p><h2>Monthly statements</h2><div class="table-wrap"><table><thead><tr><th>Month</th><th>Property</th><th>Closing balance</th><th>Statement</th></tr></thead><tbody>@forelse($statements as $statement)@php($snapshot=json_decode($statement->snapshot,true))<tr><td>{{ substr($statement->period_month,0,7) }}</td><td>{{ $snapshot['property'] }}</td><td>{{ $snapshot['currency'] }} {{ number_format((float)$snapshot['totals']['closing_balance'],2) }}</td><td><a href="{{ route('investor.statement',[app('currentTenant')->slug,$statement->id]) }}">View / Download &middot; Revision {{ $statement->revision }}</a></td></tr>@empty<tr><td colspan="4">No statements published yet.</td></tr>@endforelse</tbody></table></div></section>
{{ $statements->links() }}@if($section==='dashboard')<a href="{{ route('investor.section',[app('currentTenant')->slug,'money','month'=>$reportMonth,'unit_id'=>$selectedUnitId]) }}">Statements &amp; Payments</a>@endif
@endif
@if(in_array($section,['dashboard','money']))
<section class="card" id="payment-history"><p class="muted">Payments recorded and published by management. Recording a payment here does not transfer money.</p><h2>Payment history</h2><div class="table-wrap"><table><thead><tr><th>Date</th><th>Property</th><th>Amount</th><th>Reference</th></tr></thead><tbody>@forelse($payouts as $payout)<tr><td>{{ date('d M Y',strtotime($payout->payment_date)) }}</td><td>{{ $payout->property_label }}</td><td>{{ $payout->currency }} {{ number_format($payout->amount_minor/100,2) }}</td><td>{{ $payout->reference ?: 'Not supplied' }}</td></tr>@empty<tr><td colspan="4">No payments published for this selection.</td></tr>@endforelse</tbody></table></div></section>
{{ $payouts->links() }}@if($section==='dashboard')<a href="{{ route('investor.section',[app('currentTenant')->slug,'money','month'=>$reportMonth,'unit_id'=>$selectedUnitId]) }}">View payment history</a>@endif
@endif
@if((app('currentTenant')->modules['notifications']??true) && in_array($section,['dashboard','settings']))
<section class="card"><h2>Notifications <span class="pill">{{ $unreadCount }} unread</span></h2>@forelse($section==='dashboard' ? $events->take(3) : $events as $event)<article style="border-top:1px solid #dfe5ef;padding:16px 0"><strong>{{ $event->title }}</strong><small> &middot; {{ date('d M Y H:i',strtotime($event->created_at)) }}</small><p style="white-space:pre-wrap">{{ $event->body }}</p>@if(!$event->read_at)<form method="post" action="{{ route('investor.read',[app('currentTenant')->slug,$event->id]) }}">@csrf<button class="secondary">Mark read</button></form>@endif</article>@empty<p>No notifications yet.</p>@endforelse</section>
@endif
@if((app('currentTenant')->modules['notifications']??true) && $section==='settings')
<section class="card"><h2>Email and SMS preferences</h2><p class="muted">Choose where you receive investor updates. Your sign-in email stays unchanged. New phone numbers need management confirmation before SMS can be enabled.</p><form method="post" action="{{ route('investor.preferences',app('currentTenant')->slug) }}">@csrf
<label for="communication-email">Communication email</label><input id="communication-email" type="email" name="communication_email" maxlength="255" value="{{ old('communication_email', $investor->communication_email ?: $investor->email) }}" autocomplete="email"><p class="muted">{{ $investor->communication_email && !$investor->communication_email_verified_at ? 'Awaiting email confirmation. Email updates are paused until confirmed.' : 'Email confirmed. Leave blank to use your sign-in email.' }}</p>
<label for="communication-phone">Mobile number</label><input id="communication-phone" type="tel" name="phone" value="{{ old('phone', $investor->phone) }}" placeholder="0712 345 678 or +254712345678" autocomplete="tel"><p class="muted">{{ $investor->phone_verified_at ? 'Phone confirmed.' : 'Phone awaiting management confirmation.' }}</p>
<input type="hidden" name="sms_consent" value="0"><label><input type="checkbox" name="sms_consent" value="1" @checked(old('sms_consent', (bool) $investor->sms_consented_at))> I agree to receive investor updates by SMS at this number.</label>
<label for="email-notifications">Email updates</label><select id="email-notifications" name="email_notifications"><option value="1" @selected($investor->email_notifications)>Enabled</option><option value="0" @selected(!$investor->email_notifications)>Disabled</option></select><label for="sms-notifications">SMS updates</label><select id="sms-notifications" name="sms_notifications"><option value="0" @selected(!$investor->sms_notifications)>Disabled</option><option value="1" @selected($investor->sms_notifications)>Enabled</option></select><div class="actions"><button>Save contact details</button></div></form>@if($investor->communication_email && !$investor->communication_email_verified_at)<form method="post" action="{{ route('investor.contact.send',app('currentTenant')->slug) }}">@csrf<button>Send email confirmation</button></form>@endif</section>
@endif
@endsection
