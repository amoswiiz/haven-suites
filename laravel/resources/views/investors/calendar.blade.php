@php
    $calendarStart = \Carbon\CarbonImmutable::parse($reportMonth.'-01');
    $calendarStatuses = ['pending'=>'Pending', 'confirmed'=>'Confirmed', 'checked_in'=>'Checked in', 'checked_out'=>'Completed'];
@endphp
<style>
.owner-calendar{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:8px}.owner-day{border:1px solid #dfe5ef;border-radius:8px;padding:10px;min-width:0;min-height:110px}.owner-day time{font-weight:700}.owner-stay{margin-top:8px;padding:8px;border-left:4px solid #2563eb;background:#eff6ff;border-radius:4px;font-size:13px;overflow-wrap:anywhere}.owner-stay.checked_in{border-color:#15803d;background:#f0fdf4}.owner-stay.checked_out{border-color:#64748b;background:#f1f5f9}.owner-stay.pending{border-color:#b45309;background:#fffbeb}.owner-day-today{outline:2px solid #294780}.owner-weekday{font-weight:600;text-align:center}@media(max-width:850px){.owner-calendar{grid-template-columns:repeat(2,minmax(0,1fr))}.owner-weekday,.owner-calendar-spacer{display:none}}@media(max-width:480px){.owner-calendar{grid-template-columns:1fr}.owner-day{min-height:0}}
</style>
<section class="card" aria-labelledby="owner-calendar-heading">
<h2 id="owner-calendar-heading">Booking calendar &middot; {{ $calendarStart->format('F Y') }}</h2>
@if(!(app('currentTenant')->modules['reservations'] ?? true))
<p>Booking visibility is currently disabled for your company.</p>
@else
<p class="muted">Stays for your assigned properties and ownership dates. Each entry represents an overnight stay; checkout day is excluded. Days with no stays do not guarantee availability. Cancelled bookings are excluded.</p>
<div class="actions">
<a class="button secondary" href="{{ route('investor.section',[app('currentTenant')->slug,'bookings','month'=>$calendarStart->subMonth()->format('Y-m'),'unit_id'=>$selectedUnitId]) }}">Previous month</a>
<a class="button secondary" href="{{ route('investor.section',[app('currentTenant')->slug,'bookings','month'=>now(app('currentTenant')->timezone)->format('Y-m'),'unit_id'=>$selectedUnitId]) }}">This month</a>
<a class="button secondary" href="{{ route('investor.section',[app('currentTenant')->slug,'bookings','month'=>$calendarStart->addMonth()->format('Y-m'),'unit_id'=>$selectedUnitId]) }}">Next month</a>
</div>
<p>Statuses: Pending &middot; Confirmed &middot; Checked in &middot; Completed</p>
@if($calendarBookings->isEmpty())<p>No visible bookings for this month and property selection.</p>@endif
<div class="owner-calendar">
@foreach(['Mon','Tue','Wed','Thu','Fri','Sat','Sun'] as $weekday)<div class="owner-weekday" aria-hidden="true">{{ $weekday }}</div>@endforeach
@for($blank=1;$blank<$calendarStart->isoWeekday();$blank++)<div class="owner-calendar-spacer" aria-hidden="true"></div>@endfor
@for($day=1;$day<=$calendarStart->daysInMonth;$day++)
@php
    $date = $calendarStart->day($day);
    $stays = $calendarBookings->filter(fn($stay) => $stay->check_in <= $date->toDateString() && $stay->check_out > $date->toDateString());
@endphp
<article class="owner-day {{ $date->toDateString()===now(app('currentTenant')->timezone)->toDateString() ? 'owner-day-today' : '' }}">
<time datetime="{{ $date->toDateString() }}">{{ $date->format('D j M') }}</time>
@forelse($stays as $stay)
@php($unit=$units->firstWhere('id',$stay->unit_id))
<div class="owner-stay {{ $stay->status }}"><strong>{{ $unit?->property_name }} / {{ $unit?->unit_number }}</strong><br>{{ $calendarStatuses[$stay->status] }}<br>Booking #{{ $stay->id }}<br>{{ date('d M',strtotime($stay->check_in)) }} to {{ date('d M',strtotime($stay->check_out)) }}<br>{{ app('currentTenant')->currency }} {{ number_format($stay->nightly_rate,2) }} / night</div>
@empty<p class="muted">No visible stays</p>@endforelse
</article>
@endfor
</div>
@endif
</section>
