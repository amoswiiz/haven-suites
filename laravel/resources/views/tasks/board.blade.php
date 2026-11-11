@extends('saas.layout')
@section('title', $owner ? 'Property tasks' : 'Task board')
@push('styles')<link rel="stylesheet" href="{{ \App\Support\AssetVersion::url('assets/css/task-board.css') }}">@endpush
@push('scripts')<script src="{{ \App\Support\AssetVersion::url('assets/js/task-board.js') }}" defer></script>@endpush
@section('content')
@php
    $slug = app('currentTenant')->slug;
    $boardUrl = request()->fullUrl();
    $listUrl = request()->fullUrlWithQuery(['view' => 'list', 'group' => null, 'cancelled' => null]);
    $quick = fn (array $changes): string => request()->fullUrlWithQuery($changes);
    $initials = fn (?string $name): string => collect(preg_split('/\s+/', trim((string) $name)) ?: [])->filter()->take(2)->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))->implode('') ?: '?';
    $priorityLabels = ['urgent' => 'Urgent', 'high' => 'High', 'normal' => 'Normal', 'low' => 'Low'];
@endphp
<div class="split"><div><h1>{{ $owner ? 'Your property tasks' : ($team ? 'Team tasks' : 'My tasks') }}</h1><p class="muted">Drag a card to move it to the next stage, or use its Move menu.</p></div>
@if($canManage)<a class="button" href="{{ route('tasks.create', $slug) }}">+ New task</a>@endif</div>
<nav class="actions" aria-label="Task navigation">@if(!$owner)<a href="{{ route('tasks.index', [$slug, 'view' => 'board']) }}">My tasks</a>@if($canManage)<a href="{{ route('tasks.index', [$slug, 'scope' => 'team', 'view' => 'board']) }}">Team tasks</a>@endif<a href="{{ route('tasks.preferences', $slug) }}">Notification settings</a>@endif<a href="{{ route('tasks.notifications', $slug) }}">Notifications <span class="pill">{{ $unread }} unread</span></a><a href="{{ $listUrl }}">List view</a></nav>

<form method="get" class="card task-filters" aria-label="Filter tasks">
    <input type="hidden" name="view" value="board"><input type="hidden" name="scope" value="{{ $team ? 'team' : 'mine' }}">
    <div><label for="task-search">Search</label><input type="search" id="task-search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Repair, inspection… (press /)"></div>
    @if(!$owner)<div><label for="task-unit">Apartment</label><select id="task-unit" name="unit"><option value="">All apartments</option>@foreach($units as $unit)<option value="{{ $unit->id }}" @selected((int) ($filters['unit'] ?? 0) === (int) $unit->id)>{{ $unit->property_name }} / {{ $unit->unit_number }}</option>@endforeach</select></div>@endif
    @if($team)<div><label for="task-assignee">Assignee</label><select id="task-assignee" name="assignee"><option value="">Everyone</option>@foreach($members as $member)<option value="{{ $member->id }}" @selected((int) ($filters['assignee'] ?? 0) === (int) $member->id)>{{ $member->name }}</option>@endforeach</select></div>@endif
    @if(!$owner)<div><label for="task-group">Group by</label><select id="task-group" name="group">@foreach(['status' => 'Stage', 'unit' => 'Apartment', 'assignee' => 'Person'] as $key => $label)<option value="{{ $key }}" @selected($group === $key)>{{ $label }}</option>@endforeach</select></div>@endif
    <div class="task-filter-actions"><button type="submit">Apply</button><a href="{{ route('tasks.index', [$slug, 'view' => 'board', 'scope' => $team ? 'team' : 'mine']) }}">Clear</a></div>
    <div class="task-chips" aria-label="Quick filters">
        @foreach(['overdue' => 'Overdue', 'today' => 'Due today', 'week' => 'This week'] as $key => $label)
            @php($active = ($filters['due'] ?? null) === $key)
            <a class="task-chip{{ $active ? ' is-active' : '' }}" href="{{ $quick(['due' => $active ? null : $key]) }}" @if($active) aria-current="true" @endif>{{ $label }}</a>
        @endforeach
        @foreach(['high' => 'High priority', 'cost' => 'Cost tasks'] as $key => $label)
            @php($active = ($filters['quick'] ?? null) === $key)
            <a class="task-chip{{ $active ? ' is-active' : '' }}" href="{{ $quick(['quick' => $active ? null : $key]) }}" @if($active) aria-current="true" @endif>{{ $label }}</a>
        @endforeach
        @if(!$owner && $group === 'status')<a class="task-chip{{ $showCancelled ? ' is-active' : '' }}" href="{{ $quick(['cancelled' => $showCancelled ? null : 1]) }}">{{ $showCancelled ? 'Hide cancelled' : 'Show cancelled' }}</a>@endif
    </div>
</form>

<nav class="task-tabs" aria-label="Board columns">@foreach($columns as $index => $column)<a href="#task-column-{{ $index }}">{{ $column['label'] }} <span>{{ count($column['tasks']) }}</span></a>@endforeach</nav>

<div class="task-board" data-task-board data-group="{{ $group }}">
@foreach($columns as $index => $column)
    <section class="task-column" id="task-column-{{ $index }}" aria-labelledby="task-column-title-{{ $index }}" @if($group === 'status') data-drop-status="{{ $column['key'] }}" @endif>
        <div class="task-column-head"><h2 id="task-column-title-{{ $index }}">{{ $column['label'] }}</h2><span class="task-count">{{ count($column['tasks']) }}</span></div>
        @if($group === 'status' && $column['key'] === 'done')<p class="task-column-note">Finished in the last 14 days</p>@endif
        <div class="task-cards">
        @forelse($column['tasks'] as $task)
            @php($unit = $units->firstWhere('id', $task->unit_id))
            @php($taskMoves = $moves[$task->id] ?? [])
            @php($overdue = $task->due_date < $today && !in_array($task->status, ['done', 'cancelled'], true))
            <article class="task-card priority-{{ $task->priority }}" data-task-id="{{ $task->id }}" @if($taskMoves) draggable="true" data-moves='@json($taskMoves)' @endif aria-labelledby="task-title-{{ $task->id }}">
                <div class="task-card-top">
                    @if(in_array($task->priority, ['high', 'urgent'], true))<span class="task-priority">{{ $priorityLabels[$task->priority] }}</span>@endif
                    @if(isset($unreadTasks[$task->id]))<span class="task-unread" title="Unread update"><span class="visually-hidden">Unread update</span></span>@endif
                    @if($group !== 'status')<span class="task-stage">{{ $statuses[$task->status] }}</span>@endif
                </div>
                <h3 id="task-title-{{ $task->id }}"><a href="{{ route('tasks.show', [$slug, $task->id]) }}">{{ $task->title }}</a></h3>
                <p class="task-meta">{{ $unit ? $unit->property_name.' / '.$unit->unit_number : 'No apartment' }}</p>
                <div class="task-card-foot">
                    <span class="task-due{{ $overdue ? ' is-overdue' : ($task->due_date === $today ? ' is-today' : '') }}">{{ $overdue ? 'Overdue · ' : ($task->due_date === $today ? 'Today · ' : '') }}{{ date('j M', strtotime($task->due_date)) }}</span>
                    @if($task->kind === 'cost')<span class="task-cost cost-{{ $task->cost_status }}">Cost {{ ['requested' => 'requested', 'approved' => 'approved', 'rejected' => 'declined'][$task->cost_status] ?? $task->cost_status }}</span>@endif
                    @if(($comments[$task->id] ?? 0) > 0)<span class="task-comments" title="Updates">&#128172; {{ $comments[$task->id] }}</span>@endif
                    @if(!$owner)<span class="task-avatar" title="{{ $members->firstWhere('id', $task->assigned_to)?->name ?? 'Former team member' }}">{{ $initials($members->firstWhere('id', $task->assigned_to)?->name) }}</span>@endif
                </div>
                @if($taskMoves)
                    <details class="task-move"><summary>Move</summary>
                        <form method="post" action="{{ route('tasks.action', [$slug, $task->id]) }}" data-move-form>
                            @csrf<input type="hidden" name="version" value="{{ $task->version }}"><input type="hidden" name="return_to" value="{{ $boardUrl }}">
                            <label for="move-{{ $task->id }}">Move to</label>
                            <select id="move-{{ $task->id }}" name="action">@foreach($taskMoves as $target => $move)<option value="{{ $move['action'] }}" data-target="{{ $target }}" data-note="{{ $move['note'] ? '1' : '0' }}">{{ $move['label'] }} &rarr; {{ $statuses[$target] }}</option>@endforeach</select>
                            <label for="move-note-{{ $task->id }}">Note <span class="muted">(needed to complete or return)</span></label>
                            <textarea id="move-note-{{ $task->id }}" name="note" rows="2" maxlength="3000"></textarea>
                            <button type="submit">Move</button>
                        </form>
                    </details>
                @elseif(!$owner && $task->kind === 'cost' && $task->cost_status !== 'approved' && !in_array($task->status, ['done', 'cancelled'], true))
                    <p class="task-blocked">Waiting for cost approval</p>
                @endif
            </article>
        @empty
            <p class="task-empty">No tasks here.</p>
        @endforelse
        </div>
        @if($canManage && $group === 'status' && $column['key'] === 'todo')
            <details class="task-quick-add" @if($errors->has('title')) open @endif><summary>+ Add task</summary>
                <form method="post" action="{{ route('tasks.store', $slug) }}">
                    @csrf<input type="hidden" name="return_to" value="{{ $boardUrl }}">
                    <input type="hidden" name="priority" value="normal"><input type="hidden" name="kind" value="routine"><input type="hidden" name="owner_visible" value="0"><input type="hidden" name="share_cost" value="0">
                    <label for="quick-title">Task</label><input id="quick-title" name="title" required maxlength="150" value="{{ old('title') }}" placeholder="What needs doing? (press N)" data-quick-title>
                    <label for="quick-due">Due</label><input id="quick-due" name="due_date" type="date" required value="{{ old('due_date', $today) }}">
                    <label for="quick-assignee">Assign to</label><select id="quick-assignee" name="assigned_to">@foreach($members as $member)<option value="{{ $member->id }}" @selected((int) old('assigned_to', auth()->id()) === (int) $member->id)>{{ $member->name }}</option>@endforeach</select>
                    <label for="quick-unit">Apartment (optional)</label><select id="quick-unit" name="unit_id"><option value="">None</option>@foreach($units as $unit)<option value="{{ $unit->id }}">{{ $unit->property_name }} / {{ $unit->unit_number }}</option>@endforeach</select>
                    <button type="submit">Add task</button>
                </form>
            </details>
        @endif
    </section>
@endforeach
@if(!$columns)<p class="muted">No tasks match these filters.</p>@endif
</div>

<dialog class="task-note-dialog" data-note-dialog aria-labelledby="note-dialog-title">
    <form method="dialog">
        <h2 id="note-dialog-title">Add a note</h2>
        <p class="muted" data-note-dialog-text>Completing or returning a task needs a short note.</p>
        <label for="note-dialog-input">Note</label><textarea id="note-dialog-input" rows="4" maxlength="3000" required data-note-dialog-input></textarea>
        <div class="actions"><button value="confirm" data-note-confirm>Move task</button><button value="cancel" class="secondary" formnovalidate>Cancel</button></div>
    </form>
</dialog>
@endsection
