@extends('layouts.main')
@section('content')
<h1 class="h3">Today's work</h1><p class="text-muted">{{ now()->format('d M Y') }} - booking availability, cleaning and maintenance are shown separately. Ready for review is not a check-in approval.</p>
<div class="row g-3">@foreach($apartments as $apartment)<div class="col-lg-6"><section class="card p-3 h-100"><h2 class="h5">{{ $apartment->property_name }} / {{ $apartment->unit_number }}</h2><p><strong>{{ $apartment->readiness }}</strong></p><dl class="row"><dt class="col-4">Availability</dt><dd class="col-8">{{ $apartment->availability }}</dd><dt class="col-4">Cleaning</dt><dd class="col-8">{{ $apartment->cleaning }}</dd><dt class="col-4">Maintenance</dt><dd class="col-8">{{ $apartment->maintenance }}</dd></dl>
@foreach(['arrivals'=>'Arriving today','departures'=>'Departing today'] as $key=>$label)@foreach($apartment->$key as $stay)<p>{{ $label }}: <a href="{{ site_url('reservations/'.$stay->id) }}">{{ $stay->guest_name }}</a></p>@endforeach @endforeach
@foreach($apartment->issues as $issue)<p>{{ ucfirst($issue->priority) }}: {{ $issue->title }}</p>@endforeach
<div class="d-flex gap-3 flex-wrap">@foreach(['housekeeping'=>'Cleaning tasks','maintenance'=>'Maintenance'] as $path=>$label)@if(haven_can_access(site_url($path),'GET'))<a href="{{ site_url($path.'?unit_id='.$apartment->id) }}">{{ $label }}</a>@endif @endforeach</div></section></div>@endforeach</div>
@endsection
