<?php

namespace App\Http\Controllers;

use App\Services\Social\SocialInbox;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** One place to read and answer Facebook and Instagram comments and messages. */
class SocialInboxController extends Controller
{
    private function members(): Collection
    {
        return app('currentTenant')->users()->where('active', true)->orderBy('name')->get()
            ->filter(fn ($user) => (bool) array_intersect(json_decode($user->pivot->roles, true) ?: [], ['superadmin', 'admin', 'manager', 'operations', 'sales']))->values();
    }

    private function thread(int $id): object
    {
        return DB::table('social_threads')->where('tenant_id', app('currentTenant')->id)->where('id', $id)->firstOrFail();
    }

    public function index(Request $request): View
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['needs_reply', 'open', 'done', 'all'])], 'platform' => ['nullable', Rule::in(array_keys(SocialInbox::PLATFORMS))],
            'kind' => ['nullable', Rule::in(['comment', 'message'])], 'mine' => 'nullable|boolean', 'q' => 'nullable|string|max:100',
        ]);
        $tenantId = app('currentTenant')->id;
        $status = $filters['status'] ?? 'needs_reply';
        $query = DB::table('social_threads AS t')->join('social_accounts AS a', 'a.id', '=', 't.social_account_id')->where('t.tenant_id', $tenantId)
            ->select('t.*', 'a.platform', 'a.name AS account_name');
        if ($status !== 'all') {
            $query->where('t.status', $status);
        }
        foreach (['platform' => 'a.platform', 'kind' => 't.kind'] as $filter => $column) {
            if (! empty($filters[$filter])) {
                $query->where($column, $filters[$filter]);
            }
        }
        if (! empty($filters['mine'])) {
            $query->where('t.assigned_to', auth()->id());
        }
        if (! empty($filters['q'])) {
            $term = '%'.str_replace(['%', '_'], ['\%', '\_'], $filters['q']).'%';
            $query->where(fn ($inner) => $inner->where('t.participant_name', 'like', $term)->orWhere('t.participant_username', 'like', $term)->orWhere('t.last_preview', 'like', $term));
        }
        $counts = DB::table('social_threads')->where('tenant_id', $tenantId)->selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total', 'status');

        return view('social.index', [
            'threads' => $query->orderByDesc('t.last_message_at')->paginate(30)->withQueryString(), 'filters' => $filters + ['status' => $status],
            'counts' => $counts, 'members' => $this->members()->keyBy('id'),
            'accounts' => DB::table('social_accounts')->where('tenant_id', $tenantId)->where('status', '!=', 'disconnected')->get(),
        ]);
    }

    public function show(string $tenant, int $id): View
    {
        $thread = $this->thread($id);
        if ($thread->unread) {
            DB::table('social_threads')->where('id', $thread->id)->update(['unread' => false]);
        }
        $account = DB::table('social_accounts')->where('id', $thread->social_account_id)->first();
        $messages = DB::table('social_messages')->where('social_thread_id', $thread->id)->orderBy('sent_at')->orderBy('id')->get();
        $lastGuestMessage = $messages->where('direction', 'in')->last();

        return view('social.show', [
            'thread' => $thread, 'account' => $account, 'messages' => $messages, 'members' => $this->members(),
            'senders' => DB::table('users')->whereIn('id', $messages->pluck('sent_by')->filter()->unique()->all() ?: [0])->pluck('name', 'id'),
            'canReply' => SocialInbox::canReply($thread) && $account?->status === 'connected',
            'lead' => $thread->sales_lead_id ? DB::table('sales_leads')->where('tenant_id', app('currentTenant')->id)->where('id', $thread->sales_lead_id)->first() : null,
            'leadUrl' => route('sales.create', ['tenant' => app('currentTenant')->slug, 'name' => $thread->participant_name ?: $thread->participant_username,
                'message' => $lastGuestMessage?->body, 'source' => $account?->platform, 'social_thread' => $thread->id]),
        ]);
    }

    public function reply(Request $request, string $tenant, int $id, SocialInbox $inbox): RedirectResponse
    {
        $data = $request->validate(['body' => 'required|string|max:2000']);
        $inbox->reply($this->thread($id), trim($data['body']), (int) auth()->id());

        return redirect()->route('social.show', [$tenant, $id])->with('success', 'Reply sent.');
    }

    public function update(Request $request, string $tenant, int $id): RedirectResponse
    {
        $thread = $this->thread($id);
        $data = $request->validate(['status' => ['sometimes', Rule::in(array_keys(SocialInbox::STATUSES))],
            'assigned_to' => ['sometimes', 'nullable', Rule::in($this->members()->pluck('id')->all())]]);
        DB::table('social_threads')->where('id', $thread->id)->update($data + ['updated_at' => now()]);
        $message = isset($data['status']) ? ($data['status'] === 'done' ? 'Marked as done.' : 'Moved back to the inbox.') : 'Conversation assigned.';

        return isset($data['status']) && $data['status'] === 'done'
            ? redirect()->route('social.index', $tenant)->with('success', $message)
            : redirect()->route('social.show', [$tenant, $id])->with('success', $message);
    }
}
