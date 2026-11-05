@extends('saas.layout')
@section('title','Message templates')
@section('content')
@include('communications.tabs')
<h1>Message templates</h1>
<p>Create SMS messages for all apartments or add apartment-specific instructions and conditions. Templates are sent only when a staff member previews and sends a message.</p>
@if(auth()->user()->can('messaging.manage'))
<section class="card"><h2>Start a template</h2><div class="actions">
@foreach($purposes as $key=>$label)
    <a href="{{ route('communications.templates.create',['tenant'=>app('currentTenant')->slug,'purpose'=>$key]) }}">{{ $label }}</a>
@endforeach
</div><p>For review requests, add your actual review link. For check-in messages, add the correct arrival instructions and apartment conditions before saving.</p></section>
@endif
<section class="card"><div class="table-wrap"><table>
<thead><tr><th>Name</th><th>Purpose</th><th>Apartment</th><th>Status</th><th></th></tr></thead><tbody>
@forelse($templates as $template)
<tr><td>{{ $template->name }}</td><td>{{ $purposes[$template->purpose] ?? $template->purpose }}</td><td>{{ $template->unit_id ? ($units->get($template->unit_id)?->unit_number ?? 'Apartment unavailable') : 'All apartments / company' }}</td><td>{{ $template->active ? 'Active' : 'Archived' }}</td><td>
@if(auth()->user()->can('messaging.manage'))<a href="{{ route('communications.templates.edit',[app('currentTenant')->slug,$template->id]) }}">Edit</a>@endif
</td></tr>
@empty<tr><td colspan="5">No templates yet. Choose a starter above.</td></tr>@endforelse
</tbody></table></div></section>
@endsection
