@extends('saas.layout')
@section('title','Sales pipeline')
@section('content')
<div class="split"><div><h1>Sales pipeline</h1><p class="muted">Enquiries from every channel, one next action for every guest.</p></div><div class="actions" style="margin-top:0">@if(auth()->user()->can('sales.manage'))<a class="button" href="{{ route('sales.create',app('currentTenant')->slug) }}">New enquiry</a>@endif<a href="{{ route('sales.index',[app('currentTenant')->slug,'overdue'=>1]) }}">{{ $overdue }} overdue follow-ups</a></div></div>
<form method="get" class="card"><div class="grid">
<div><label for="q">Search guest, phone or email</label><input id="q" name="q" value="{{ $filters['q']??'' }}"></div>
<div><label for="stage">Stage</label><select id="stage" name="stage"><option value="">All stages</option>@foreach(config('sales.stages') as $key=>$label)<option value="{{ $key }}" @selected(($filters['stage']??'')===$key)>{{ $label }}</option>@endforeach</select></div>
<div><label for="source">Original source</label><select id="source" name="source"><option value="">All sources</option>@foreach(config('sales.sources') as $key=>$label)<option value="{{ $key }}" @selected(($filters['source']??'')===$key)>{{ $label }}</option>@endforeach</select></div>
<div><label for="assigned">Salesperson</label><select id="assigned" name="assigned_to"><option value="">Everyone</option>@foreach($members as $member)<option value="{{ $member->id }}" @selected(($filters['assigned_to']??'')==$member->id)>{{ $member->name }}</option>@endforeach</select></div>
<div><label for="view">Display</label><select id="view" name="view"><option value="board">Board</option><option value="list" @selected(($filters['view']??'')==='list')>List</option></select></div></div>
<label><input style="width:auto" type="checkbox" name="overdue" value="1" @checked(!empty($filters['overdue']))> Overdue follow-ups only</label><div class="actions"><button>Apply filters</button><a href="{{ route('sales.index',app('currentTenant')->slug) }}">Reset</a></div></form>
@if(($filters['view']??'board')==='list')
<section class="card"><div class="table-wrap"><table><caption>Enquiries matching your filters</caption><thead><tr><th>Guest</th><th>Stage</th><th>Source / Channel</th><th>Next action</th><th>Assigned to</th></tr></thead><tbody>
@forelse($leads as $lead)
<tr><td><a href="{{ route('sales.show',[app('currentTenant')->slug,$lead->id]) }}">{{ $lead->name }}</a><br>{{ $lead->check_in ?: 'Dates to confirm' }}</td><td>{{ config('sales.stages.'.$lead->stage) }}</td><td>{{ config('sales.sources.'.$lead->source) }} / {{ config('sales.channels.'.$lead->channel) }}</td><td>{{ $lead->next_action }}<br>{{ $lead->follow_up_at ?: 'Closed' }}</td><td>{{ $members->firstWhere('id',$lead->assigned_to)?->name ?? 'Former member' }}</td></tr>
@empty<tr><td colspan="5">No enquiries match these filters.</td></tr>@endforelse
</tbody></table></div></section>
@else
<p class="muted">Drag an enquiry into a stage, or use its Move button. Review the next action before saving. Showing up to 60 enquiries per page.</p>
<div id="sales-board" style="display:flex;gap:14px;overflow-x:auto;padding-bottom:16px" tabindex="0" aria-label="Sales pipeline board, scroll horizontally for more stages">
@foreach(config('sales.stages') as $stage=>$label)
<section data-stage="{{ $stage }}" style="flex:0 0 260px;background:#e8edf5;padding:14px;border-radius:12px;min-height:240px"><h2>{{ $label }} <span class="pill">{{ $leads->where('stage',$stage)->count() }} on this page</span></h2>
@forelse($leads->where('stage',$stage) as $lead)
<article class="card sales-enquiry" style="padding:16px;margin:12px 0" @if(auth()->user()->can('sales.manage')) draggable="true" data-movable data-stage="{{ $stage }}" data-name="{{ $lead->name }}" data-update-url="{{ route('sales.update',[app('currentTenant')->slug,$lead->id]) }}" data-detail-url="{{ route('sales.show',[app('currentTenant')->slug,$lead->id]) }}" data-version="{{ $lead->version }}" data-assigned="{{ $lead->assigned_to }}" data-next-action="{{ $lead->next_action }}" data-follow-up="{{ $lead->follow_up_at ? date('Y-m-d\TH:i',strtotime($lead->follow_up_at)) : '' }}" data-lost-reason="{{ $lead->lost_reason }}" @endif><strong><a href="{{ route('sales.show',[app('currentTenant')->slug,$lead->id]) }}">{{ $lead->name }}</a></strong><p class="muted">{{ config('sales.sources.'.$lead->source) }} &middot; {{ $lead->check_in ?: 'Dates to confirm' }}</p><p>{{ $lead->next_action }}</p><small>{{ $members->firstWhere('id',$lead->assigned_to)?->name ?? 'Former member' }}</small><br>
@if($lead->follow_up_at)<span class="pill">{{ \Carbon\Carbon::parse($lead->follow_up_at)->isPast() && !in_array($stage,['won','lost']) ? 'Overdue: ' : 'Follow up: ' }}{{ $lead->follow_up_at }}</span>@endif
@if(auth()->user()->can('sales.manage'))
<button type="button" class="secondary sales-move" aria-label="Move enquiry for {{ $lead->name }}" style="margin-top:12px;touch-action:none">Move enquiry</button>
@endif
</article>
@empty<p class="muted">No enquiries on this page.</p>@endforelse
</section>
@endforeach
</div>
@if(auth()->user()->can('sales.manage'))
<p id="sales-move-status" role="status" aria-live="polite" class="muted"></p>
<dialog id="sales-move-dialog" aria-labelledby="sales-move-title">
<form method="post" id="sales-move-form">
@csrf
<h2 id="sales-move-title">Move enquiry</h2><p id="sales-move-guest"></p>
<input type="hidden" name="version"><input type="hidden" name="assigned_to">
<label for="move-stage">Move to stage</label><select id="move-stage" name="stage">@foreach(config('sales.stages') as $key=>$label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select>
<div id="sales-move-fields">
<label for="move-next-action">Next action</label><input id="move-next-action" name="next_action" maxlength="255" required>
<label for="move-follow-up">Follow-up date and time ({{ config('app.timezone') }})</label><input id="move-follow-up" type="datetime-local" name="follow_up_at">
<div id="sales-lost-fields"><label for="move-lost-reason">Lost / postponed reason</label><input id="move-lost-reason" name="lost_reason" maxlength="255"></div>
<p class="muted">Invoice issued and Awaiting deposit require a linked booking with an issued invoice.</p>
</div>
<p id="sales-won-message" hidden>To mark this enquiry as Won, open its details to link the reservation and verify the deposit.</p>
<div class="actions"><button type="submit" id="sales-save-move">Save move</button><a class="button" id="sales-confirm-booking" hidden>Open booking confirmation</a><button type="button" class="secondary" id="sales-cancel-move">Cancel</button></div>
</form></dialog>
<style>
#sales-move-dialog{width:min(540px,calc(100vw - 32px));max-height:90dvh;overflow:auto;border:1px solid #ccd6e5;border-radius:14px;padding:24px;color:#203456}#sales-move-dialog::backdrop{background:#172b4999}
#sales-board [data-stage].sales-drop-target{outline:3px solid #294780;outline-offset:-3px;background:#d7e4f7!important}
.sales-enquiry[draggable=true]{cursor:grab}.sales-enquiry.sales-dragging{opacity:.55}.sales-move:focus-visible{outline:3px solid #294780;outline-offset:3px}
#sales-move-dialog [hidden]{display:none!important}
</style>
<script src="{{ asset('assets/js/sales-pipeline.js') }}" defer></script>
@endif
@endif
{{ $leads->links() }}
<section class="card" style="margin-top:24px"><h2>Performance by original source</h2><p class="muted">All enquiries, independent of filters. Budget is an estimate, not revenue or cash collected.</p><div class="table-wrap"><table><thead><tr><th>Source</th><th>Enquiries</th><th>Won</th><th>Conversion</th><th>Won enquiry budgets</th></tr></thead><tbody>
@forelse($summary as $row)<tr><td>{{ config('sales.sources.'.$row->source) }}</td><td>{{ $row->total }}</td><td>{{ $row->won }}</td><td>{{ number_format($row->won/$row->total*100,1) }}%</td><td>{{ app('currentTenant')->currency }} {{ number_format($row->estimated_won_value,2) }}</td></tr>@empty<tr><td colspan="5">Capture an enquiry to start tracking sales.</td></tr>@endforelse
</tbody></table></div></section>
@endsection
