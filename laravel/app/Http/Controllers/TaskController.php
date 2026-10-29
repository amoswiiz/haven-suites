<?php

namespace App\Http\Controllers;

use App\Services\OwnerRemittanceCalculator;
use App\Services\TaskAccess;
use App\Services\TaskNotifications;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TaskController extends Controller
{
    public const STATUSES = ['todo' => 'To do', 'in_progress' => 'In progress', 'review' => 'Awaiting review', 'done' => 'Done', 'cancelled' => 'Cancelled'];

    private function rows(): Builder
    {
        return DB::table('task_items')->where('tenant_id', app('currentTenant')->id);
    }

    private function task(int $id): object
    {
        $task = $this->rows()->where('id', $id)->firstOrFail();
        abort_unless(app(TaskAccess::class)->visible(auth()->user(), $task), 404);

        return $task;
    }

    private function units(): Collection
    {
        return DB::connection('tenant')->table('units as u')->leftJoin('properties as p', 'p.id', '=', 'u.property_id')->select('u.id', 'u.unit_number', 'p.name as property_name')->orderBy('p.name')->orderBy('u.unit_number')->get();
    }

    private function members(): Collection
    {
        return app('currentTenant')->users()->where('users.active', true)->get()->filter(fn ($u) => $u->can('tasks.view'));
    }

    public function index(Request $request, TaskAccess $access): View
    {
        $data = $request->validate(['scope' => 'nullable|in:mine,team', 'status' => ['nullable', Rule::in([...array_keys(self::STATUSES), 'open', 'overdue'])], 'q' => 'nullable|string|max:100', 'view' => 'nullable|in:list,board']);
        $owner = ! auth()->user()->can('tasks.view');
        $team = ($data['scope'] ?? 'mine') === 'team';
        abort_if($team && ! auth()->user()->can('tasks.manage'), 403);
        $q = $access->scope(auth()->user());
        if (! $owner && ! $team) {
            $q->where(fn ($q) => $q->where('assigned_to', auth()->id())->orWhere('reviewer_id', auth()->id()));
        }
        $status = $request->exists('status') ? ($data['status'] ?? '') : 'open';
        if ($status === 'open' || $status === 'overdue') {
            $q->whereNotIn('status', ['done', 'cancelled']);
        } elseif ($status) {
            $q->where('status', $status);
        }
        if ($status === 'overdue') {
            $q->where('due_date', '<', now(app('currentTenant')->timezone)->toDateString());
        }
        if (! empty($data['q'])) {
            $q->where('title', 'like', '%'.$data['q'].'%');
        }
        $tasks = $q->orderByRaw("CASE WHEN status IN ('done','cancelled') THEN 1 ELSE 0 END")->orderBy('due_date')->orderByDesc('id')->paginate(30)->withQueryString();

        return view('tasks.index', ['tasks' => $tasks, 'owner' => $owner, 'members' => $owner ? collect() : $this->members(), 'units' => $this->units(), 'statuses' => self::STATUSES, 'team' => $team, 'unread' => DB::table('task_notices')->where('tenant_id', app('currentTenant')->id)->where('user_id', auth()->id())->whereNull('read_at')->count()]);
    }

    public function create(): View
    {
        abort_unless(auth()->user()->can('tasks.manage'), 403);

        return view('tasks.form', ['task' => null, 'units' => $this->units(), 'members' => $this->members()]);
    }

    public function edit(string $tenant, int $id): View
    {
        abort_unless(auth()->user()->can('tasks.manage'), 403);

        return view('tasks.form', ['task' => $this->task($id), 'units' => $this->units(), 'members' => $this->members()]);
    }

    private function input(Request $request): array
    {
        $data = $request->validate(['title' => 'required|string|max:150', 'description' => 'nullable|string|max:5000', 'unit_id' => 'nullable|integer|min:1', 'assigned_to' => 'required|integer', 'reviewer_id' => 'nullable|integer', 'due_date' => 'required|date_format:Y-m-d', 'priority' => 'required|in:low,normal,high,urgent', 'kind' => 'required|in:routine,cost', 'estimate' => 'nullable|required_if:kind,cost|numeric|min:0.01|max:10000000', 'owner_visible' => 'required|boolean', 'owner_description' => 'nullable|required_if:owner_visible,1|string|max:3000', 'share_cost' => 'required|boolean']);
        $members = $this->members();
        if (! $members->contains('id', (int) $data['assigned_to'])) {
            throw ValidationException::withMessages(['assigned_to' => 'Choose an active member of this company.']);
        }
        if (! empty($data['reviewer_id']) && ! $members->firstWhere('id', (int) $data['reviewer_id'])?->can('tasks.manage')) {
            throw ValidationException::withMessages(['reviewer_id' => 'Choose a company manager to review completion.']);
        }
        if (! empty($data['reviewer_id']) && (int) $data['reviewer_id'] === (int) $data['assigned_to']) {
            throw ValidationException::withMessages(['reviewer_id' => 'Choose a reviewer other than the assignee.']);
        }
        if (! empty($data['unit_id'])) {
            abort_unless((app('currentTenant')->modules['properties'] ?? true) && DB::connection('tenant')->table('units')->where('id', $data['unit_id'])->exists(), 422, 'Choose an apartment from this company.');
        }
        if ($data['owner_visible'] && (empty($data['unit_id']) || ! (app('currentTenant')->modules['investors'] ?? true))) {
            throw ValidationException::withMessages(['owner_visible' => 'Owner-visible tasks need an apartment and an enabled investor module.']);
        }
        $data['estimate_minor'] = $data['kind'] === 'cost' ? (new OwnerRemittanceCalculator)->cents((string) $data['estimate']) : null;
        $data['share_cost'] = $data['kind'] === 'cost' && $data['owner_visible'] && $data['share_cost'];
        unset($data['estimate']);

        return $data;
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless(auth()->user()->can('tasks.manage'), 403);
        $data = $this->input($request);
        $id = DB::transaction(function () use ($data): int {
            $id = DB::table('task_items')->insertGetId([...$data, 'tenant_id' => app('currentTenant')->id, 'created_by' => auth()->id(), 'cost_status' => $data['kind'] === 'cost' ? 'requested' : 'none', 'created_at' => now(), 'updated_at' => now()]);
            $task = $this->rows()->where('id', $id)->firstOrFail();
            $this->event($task, 'Task created and assigned.');
            app(TaskNotifications::class)->record($task, 'created', 'A task has been assigned.', auth()->id(), (bool) $task->owner_visible, $this->financeRecipients($task));

            return $id;
        });

        return redirect()->route('tasks.show', [app('currentTenant')->slug, $id])->with('success', 'Task assigned.');
    }

    public function update(string $tenant, int $id, Request $request): RedirectResponse
    {
        abort_unless(auth()->user()->can('tasks.manage'), 403);
        $data = $this->input($request);
        $this->mutate($id, $request, function ($task) use ($data): void {
            if (in_array($task->status, ['done', 'cancelled'])) {
                throw ValidationException::withMessages(['task' => 'Reopen this task before editing it.']);
            }
            if ($task->expense_id && ($data['kind'] !== 'cost' || ($data['unit_id'] ?? null) != $task->unit_id)) {
                throw ValidationException::withMessages(['unit_id' => 'Finance must unlink the expense before changing the apartment or task type.']);
            }
            $changedCost = ($task->cost_status === 'rejected' && $data['kind'] === 'cost') || $data['estimate_minor'] != $task->estimate_minor || $data['kind'] !== $task->kind || ($data['unit_id'] ?? null) != $task->unit_id;
            $changes = $data;
            if ($changedCost) {
                $changes['cost_status'] = $data['kind'] === 'cost' ? 'requested' : 'none';
                $changes['cost_approved_by'] = null;
            }
            $this->rows()->where('id', $task->id)->update($changes);
            $fresh = $this->rows()->where('id', $task->id)->first();
            $this->event($task, 'Task details updated; assignee #'.$task->assigned_to.' to #'.$fresh->assigned_to.', due '.$task->due_date.' to '.$fresh->due_date.($changedCost ? '; cost approval reset.' : '.'));
            $key = $task->assigned_to != $data['assigned_to'] ? 'reassigned:' : 'updated:';
            app(TaskNotifications::class)->record($fresh, $key.($task->version + 1), 'Task assignment or details changed.', auth()->id(), (bool) $fresh->owner_visible, [$task->assigned_to, ...$this->financeRecipients($fresh)]);
        });

        return back()->with('success', 'Task updated.');
    }

    private function mutate(int $id, Request $request, callable $action): void
    {
        $request->validate(['version' => 'required|integer|min:1']);
        $this->task($id);
        DB::transaction(function () use ($id, $request, $action): void {
            $task = $this->rows()->where('id', $id)->lockForUpdate()->firstOrFail();
            abort_unless(app(TaskAccess::class)->visible(auth()->user(), $task), 404);
            if ((int) $task->version !== $request->integer('version')) {
                throw ValidationException::withMessages(['version' => 'This task changed. Refresh the page before saving.']);
            }
            $action($task);
            $this->rows()->where('id', $id)->update(['version' => $task->version + 1, 'updated_at' => now()]);
        });
    }

    private function event(object $task, string $message): void
    {
        DB::table('task_events')->insert(['task_id' => $task->id, 'actor_id' => auth()->id(), 'message' => $message, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function financeRecipients(object $task): array
    {
        return $task->cost_status === 'requested' ? $this->members()->filter(fn ($u) => $u->can('finance.manage'))->pluck('id')->all() : [];
    }

    public function action(string $tenant, int $id, Request $request): RedirectResponse
    {
        abort_unless(auth()->user()->can('tasks.view'), 403);
        $data = $request->validate(['action' => 'required|in:start,complete,approve,return,reopen,cancel,approve_cost,reject_cost,link_expense', 'note' => 'nullable|string|max:3000', 'expense_id' => 'nullable|integer|min:1']);
        $this->mutate($id, $request, function ($task) use ($data): void {
            $manager = auth()->user()->can('tasks.manage');
            $action = $data['action'];
            $changes = [];
            $owners = false;
            if (in_array($action, ['approve_cost', 'reject_cost', 'link_expense'])) {
                abort_unless(auth()->user()->can('finance.manage') && $task->kind === 'cost', 403);
                if ($action === 'link_expense') {
                    if ($task->cost_status !== 'approved') {
                        throw ValidationException::withMessages(['expense_id' => 'Approve the cost estimate before linking an expense.']);
                    }
                    if (! empty($data['expense_id'])) {
                        $expense = DB::connection('tenant')->table('unit_expenses')->where('id', $data['expense_id'])->first();
                        if (! $expense || ($task->unit_id && (int) $expense->unit_id !== (int) $task->unit_id)) {
                            throw ValidationException::withMessages(['expense_id' => 'Select an expense for this task apartment.']);
                        }
                        if ($this->rows()->where('expense_id', $expense->id)->where('id', '!=', $task->id)->exists()) {
                            throw ValidationException::withMessages(['expense_id' => 'This expense is already linked to another task.']);
                        }
                    }
                    $changes['expense_id'] = $data['expense_id'] ?? null;
                } else {
                    if ($task->cost_status !== 'requested') {
                        throw ValidationException::withMessages(['cost' => 'Only requested costs can be reviewed.']);
                    }
                    if ($action === 'reject_cost' && empty($data['note'])) {
                        throw ValidationException::withMessages(['note' => 'Explain why the cost was declined.']);
                    }
                    $changes = ['cost_status' => $action === 'approve_cost' ? 'approved' : 'rejected', 'cost_approved_by' => auth()->id()];
                }
            } else {
                abort_unless($manager || (int) $task->assigned_to === auth()->id() || (int) $task->reviewer_id === auth()->id(), 403);
                if (in_array($action, ['approve', 'return'])) {
                    abort_unless($task->status === 'review' && (int) $task->reviewer_id === auth()->id(), 403);
                } elseif (in_array($action, ['cancel', 'reopen'])) {
                    abort_unless($manager, 403);
                } else {
                    abort_unless((int) $task->assigned_to === auth()->id(), 403);
                }
                $allowed = match ($action) {
                    'start' => ['todo'], 'complete' => ['todo', 'in_progress'], 'approve','return' => ['review'], 'reopen' => ['done', 'cancelled'], 'cancel' => ['todo', 'in_progress', 'review'], default => []
                };
                if (! in_array($task->status, $allowed)) {
                    throw ValidationException::withMessages(['action' => 'This action is not available for the current task status.']);
                }
                if (in_array($action, ['start', 'complete', 'approve']) && $task->kind === 'cost' && $task->cost_status !== 'approved') {
                    throw ValidationException::withMessages(['cost' => 'Finance must approve the estimate before this work proceeds.']);
                }
                if (in_array($action, ['complete', 'return']) && empty($data['note'])) {
                    throw ValidationException::withMessages(['note' => 'Add completion notes or a reason for returning the task.']);
                }
                $changes['status'] = match ($action) {
                    'start','return' => 'in_progress', 'complete' => $task->reviewer_id ? 'review' : 'done', 'approve' => 'done', 'reopen' => 'todo', 'cancel' => 'cancelled'
                };
                $owners = in_array($changes['status'], ['in_progress', 'done', 'cancelled', 'todo']) && $action !== 'return';
            }
            $this->rows()->where('id', $task->id)->update($changes);
            $fresh = $this->rows()->where('id', $task->id)->first();
            $label = ucfirst(str_replace('_', ' ', $action));
            $this->event($task, $label.'.');
            if (! empty($data['note'])) {
                DB::table('task_updates')->insert(['task_id' => $task->id, 'user_id' => auth()->id(), 'body' => $data['note'], 'shared' => false, 'created_at' => now(), 'updated_at' => now()]);
            }
            app(TaskNotifications::class)->record($fresh, 'action:'.($task->version + 1), $label.': a task needs your attention.', auth()->id(), $owners && (bool) $task->owner_visible);
        });

        return redirect()->route('tasks.show', [$tenant, $id])->with('success', 'Task updated.');
    }

    public function show(string $tenant, int $id): View
    {
        $task = $this->task($id);
        $owner = ! auth()->user()->can('tasks.view');
        $updates = DB::table('task_updates')->where('task_id', $id)->when($owner, fn ($q) => $q->where('shared', true))->latest('id')->get();
        $expense = null;
        $choices = collect();
        if ($task->kind === 'cost' && (! $owner || $task->share_cost) && (app('currentTenant')->modules['finance'] ?? true)) {
            $expense = $task->expense_id ? DB::connection('tenant')->table('unit_expenses')->where('id', $task->expense_id)->first() : null;
            if (! $owner && auth()->user()->can('finance.manage')) {
                $choices = DB::connection('tenant')->table('unit_expenses')->when($task->unit_id, fn ($q) => $q->where('unit_id', $task->unit_id))->orderByDesc('expense_date')->limit(100)->get();
            }
        }

        return view('tasks.show', ['task' => $task, 'owner' => $owner, 'updates' => $updates, 'events' => $owner ? collect() : DB::table('task_events')->where('task_id', $id)->latest('id')->get(), 'members' => $owner ? collect() : $this->members(), 'unit' => $this->units()->firstWhere('id', $task->unit_id), 'statuses' => self::STATUSES, 'expense' => $expense, 'expenseChoices' => $choices]);
    }

    public function comment(string $tenant, int $id, Request $request): RedirectResponse
    {
        abort_unless(auth()->user()->can('tasks.view'), 403);
        $task = $this->task($id);
        $data = $request->validate(['body' => 'required|string|max:3000', 'shared' => 'required|boolean', 'attachment' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:5120', 'mention_id' => 'nullable|integer']);
        abort_if($data['shared'] && (! auth()->user()->can('tasks.manage') || ! $task->owner_visible), 403);
        $mention = null;
        if (! empty($data['mention_id'])) {
            $mention = $this->members()->firstWhere('id', (int) $data['mention_id']);
            if (! $mention || ! app(TaskAccess::class)->visible($mention, $task)) {
                throw ValidationException::withMessages(['mention_id' => 'Mention a participant or manager with access to this task.']);
            }
        }
        $path = $request->file('attachment')?->store('tasks/'.app('currentTenant')->id, 'local');
        try {
            DB::transaction(function () use ($task, $data, $path, $request, $mention): void {
                $id = DB::table('task_updates')->insertGetId(['task_id' => $task->id, 'user_id' => auth()->id(), 'body' => $data['body'], 'shared' => $data['shared'], 'path' => $path, 'filename' => $request->file('attachment')?->getClientOriginalName(), 'created_at' => now(), 'updated_at' => now()]);
                app(TaskNotifications::class)->record($task, 'comment:'.$id, 'A task comment was added.', auth()->id(), (bool) $data['shared'], $mention ? [$mention->id] : []);
                $this->event($task, $data['shared'] ? 'An owner-visible update was published.' : 'An internal comment was added.');
            });
        } catch (\Throwable $e) {
            if ($path) {
                Storage::disk('local')->delete($path);
            } throw $e;
        }

        return back()->with('success', $data['shared'] ? 'Owner update published.' : 'Internal note saved.');
    }

    public function attachment(string $tenant, int $id, int $update): StreamedResponse
    {
        $task = $this->task($id);
        $owner = ! auth()->user()->can('tasks.view');
        $row = DB::table('task_updates')->where('task_id', $task->id)->where('id', $update)->when($owner, fn ($q) => $q->where('shared', true))->firstOrFail();
        abort_unless($row->path && Storage::disk('local')->exists($row->path), 404);

        return Storage::disk('local')->download($row->path, $row->filename, ['X-Content-Type-Options' => 'nosniff']);
    }

    public function notices(): View
    {
        abort_unless(auth()->user()->can('tasks.view') || auth()->user()->can('tasks.owner'), 403);
        $notices = DB::table('task_notices')->where('tenant_id', app('currentTenant')->id)->where('user_id', auth()->id())->latest('id')->paginate(30);

        return view('tasks.notifications', compact('notices'));
    }

    public function readNotice(string $tenant, int $id): RedirectResponse
    {
        DB::table('task_notices')->where('tenant_id', app('currentTenant')->id)->where('user_id', auth()->id())->where('id', $id)->update(['read_at' => now()]);

        return back();
    }

    public function preferences(): View
    {
        abort_unless(auth()->user()->can('tasks.view'), 403);

        return view('tasks.preferences', ['preferences' => DB::table('task_preferences')->where('tenant_id', app('currentTenant')->id)->where('user_id', auth()->id())->first()]);
    }

    public function savePreferences(Request $request): RedirectResponse
    {
        abort_unless(auth()->user()->can('tasks.view'), 403);
        $data = $request->validate(['email_enabled' => 'required|boolean', 'sms_enabled' => 'required|boolean', 'phone' => ['nullable', 'required_if:sms_enabled,1', 'regex:/^\+[1-9][0-9]{7,14}$/'], 'sms_consent' => 'required|boolean']);
        if ($data['sms_enabled'] && ! $data['sms_consent']) {
            throw ValidationException::withMessages(['sms_consent' => 'Please consent to SMS task notifications.']);
        }
        $consent = $data['sms_consent'] && ! empty($data['phone']) ? now() : null;
        unset($data['sms_consent']);
        DB::table('task_preferences')->updateOrInsert(['tenant_id' => app('currentTenant')->id, 'user_id' => auth()->id()],[...$data, 'sms_consented_at' => $consent, 'created_at' => now(), 'updated_at' => now()]);

        return back()->with('success','Notification preferences saved.');
    }
}
