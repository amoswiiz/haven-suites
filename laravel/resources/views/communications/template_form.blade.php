@extends('saas.layout')
@section('title','Edit SMS template')
@section('content')
@include('communications.tabs')
<h1>{{ $template ? 'Edit SMS template' : 'New SMS template' }}</h1>
<form class="card" method="post" action="{{ $template ? route('communications.templates.update',[app('currentTenant')->slug,$template->id]) : route('communications.templates.store',app('currentTenant')->slug) }}">
@csrf
@if($template)<input type="hidden" name="version" value="{{ $template->version }}">@endif
<label for="name">Template name</label><input id="name" name="name" maxlength="120" value="{{ old('name',$template?->name ?? $purposes[$purpose]) }}" required>
<label for="purpose">Message type</label><select id="purpose" name="purpose">@foreach($purposes as $key=>$label)<option value="{{ $key }}" @selected(old('purpose',$purpose)===$key)>{{ $label }}</option>@endforeach</select>
<label for="unit_id">Apartment</label><select id="unit_id" name="unit_id"><option value="">All apartments / company-wide</option>@foreach($units as $unit)<option value="{{ $unit->id }}" @selected(old('unit_id',$template?->unit_id)==$unit->id)>{{ $unit->property_name }} — {{ $unit->unit_number }}</option>@endforeach</select>
<label for="body">SMS template</label><textarea id="body" name="body" rows="9" maxlength="1600" required>{{ old('body',$starter) }}</textarea>
<p>Add apartment conditions directly here, such as check-in time, key collection, parking, quiet hours and checkout instructions. Only include details appropriate to share by SMS.</p>
<p>Available booking placeholders:
@foreach($fields as $field)<code>{{ chr(123).chr(123).$field.chr(125).chr(125) }}</code> @endforeach
</p><p>The final message is checked and previewed before sending. Long messages may use multiple SMS credits.</p>
<label for="active">Status</label><select id="active" name="active"><option value="1" @selected(old('active',$template?->active ?? 1)==1)>Active</option><option value="0" @selected(old('active',$template?->active ?? 1)==0)>Archived</option></select>
<button>Save template</button>
</form>
@endsection
