@extends('saas.layout')
@section('title','New enquiry')
@section('content')
<h1>New enquiry</h1><p class="muted">Record where the guest first found you separately from the channel you are using now.</p>
@if(session('duplicates'))<section class="card"><h2>Possible existing enquiries</h2>@foreach(session('duplicates') as $duplicate)<p><a href="{{ route('sales.show',[app('currentTenant')->slug,$duplicate['id']]) }}">{{ $duplicate['name'] }} / {{ $duplicate['check_in'] ?: 'Dates to confirm' }}</a></p>@endforeach</section>@endif
<form method="post" action="{{ route('sales.store',app('currentTenant')->slug) }}" class="card">@csrf<div class="grid">
@foreach(['name'=>'Guest name','phone'=>'Phone number','email'=>'Email address','campaign'=>'Campaign / Advert','location'=>'Preferred location','unit_preference'=>'Preferred apartment'] as $key=>$label)
<div><label for="{{ $key }}">{{ $label }}</label><input id="{{ $key }}" name="{{ $key }}" type="{{ $key==='email'?'email':'text' }}" value="{{ old($key) }}" maxlength="{{ $key==='phone'?50:150 }}" @required($key==='name')></div>
@endforeach
<div><label for="source">Original source</label><select id="source" name="source">@foreach(config('sales.sources') as $key=>$label)<option value="{{ $key }}" @selected(old('source','direct')===$key)>{{ $label }}</option>@endforeach</select></div>
<div><label for="channel">Current channel</label><select id="channel" name="channel">@foreach(config('sales.channels') as $key=>$label)<option value="{{ $key }}" @selected(old('channel','whatsapp')===$key)>{{ $label }}</option>@endforeach</select></div>
<div><label for="assigned_to">Assigned salesperson</label><select id="assigned_to" name="assigned_to" required>@foreach($members as $member)<option value="{{ $member->id }}" @selected(old('assigned_to',auth()->id())==$member->id)>{{ $member->name }}</option>@endforeach</select></div>
@if(\App\Support\CompanyModules::enabled('agents'))<div><label for="agent_id">Referring agent</label><select id="agent_id" name="agent_id"><option value="">No agent</option>@foreach($agents as $agent)<option value="{{ $agent->id }}" @selected(old('agent_id')==$agent->id)>{{ $agent->name }}</option>@endforeach</select></div>@endif
<div><label for="check_in">Check-in</label><input id="check_in" name="check_in" type="date" value="{{ old('check_in') }}"></div><div><label for="check_out">Check-out</label><input id="check_out" name="check_out" type="date" value="{{ old('check_out') }}"></div>
<div><label for="guests">Guests</label><input id="guests" name="guests" type="number" min="1" max="1000" value="{{ old('guests',1) }}" required></div><div><label for="budget">Stay budget ({{ app('currentTenant')->currency }})</label><input id="budget" name="budget" type="number" min="0" step="0.01" value="{{ old('budget') }}"></div>
</div><label for="next_action">Next action</label><input id="next_action" name="next_action" value="{{ old('next_action','Confirm stay requirements') }}" required maxlength="255"><label for="follow_up_at">Follow-up date and time ({{ config('app.timezone') }})</label><input id="follow_up_at" name="follow_up_at" type="datetime-local" value="{{ old('follow_up_at',now()->addHour()->format('Y-m-d\TH:i')) }}" required>
<label for="notes">Notes</label><textarea id="notes" name="notes" rows="4" maxlength="5000">{{ old('notes') }}</textarea>
@if(session('duplicates'))<label><input style="width:auto" type="checkbox" name="duplicate_acknowledged" value="1"> I reviewed the matching guests; this is a separate enquiry.</label>@endif
<div class="actions"><button>Create enquiry</button><a href="{{ route('sales.index',app('currentTenant')->slug) }}">Cancel</a></div></form>
@endsection
