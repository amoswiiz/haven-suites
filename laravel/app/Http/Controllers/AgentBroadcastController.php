<?php

namespace App\Http\Controllers;

use App\Services\AgentBroadcasts;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** Send one message about availability or offers to all referral agents, and see who received it. */
class AgentBroadcastController extends Controller
{
    public function index(AgentBroadcasts $broadcasts): View
    {
        $tenant = app('currentTenant');
        $agents = DB::table('sales_agents')->where('tenant_id', $tenant->id)->where('active', true)->orderBy('name')->get()
            ->each(function (object $agent): void {
                $agent->mobile = AgentBroadcasts::mobile($agent);
            });
        $history = DB::table('agent_broadcasts as b')->leftJoin('users as u', 'u.id', '=', 'b.created_by')->where('b.tenant_id', $tenant->id)
            ->orderByDesc('b.id')->select('b.*', 'u.name as sender_name')->paginate(15);

        return view('sales.broadcasts', [
            'agents' => $agents, 'history' => $history, 'counts' => $broadcasts->statusCounts($history->pluck('id')->all()),
            'listings' => $broadcasts->publishedListings($tenant), 'templates' => $broadcasts->templates($tenant),
            'smsAvailable' => $broadcasts->smsAvailable($tenant),
        ]);
    }

    public function store(Request $request, AgentBroadcasts $broadcasts): RedirectResponse
    {
        $tenant = app('currentTenant');
        $data = $request->validate([
            'kind' => ['required', Rule::in(array_keys(AgentBroadcasts::KINDS))],
            'message' => 'required|string|min:10|max:'.AgentBroadcasts::MAX_LENGTH,
            'agent_ids' => 'required|array|min:1|max:1000', 'agent_ids.*' => 'integer',
            'listing_ids' => 'nullable|array|max:10', 'listing_ids.*' => 'integer',
            'listing_prices' => 'nullable|array|max:200', 'listing_prices.*' => 'nullable|numeric|min:0|max:9999999',
            'confirm' => 'accepted',
        ], ['agent_ids.required' => 'Choose at least one agent.', 'confirm.accepted' => 'Tick the box to confirm you want to send this SMS.']);
        if (! $broadcasts->smsAvailable($tenant)) {
            throw ValidationException::withMessages(['message' => 'SMS sending is not switched on for this company yet. Ask the platform administrator to enable RoyceTalk SMS.']);
        }
        $listingIds = array_map('intval', $data['listing_ids'] ?? []);
        $lines = $broadcasts->listingLines($tenant, $listingIds, $data['listing_prices'] ?? []);
        $message = preg_replace("/^[ \t]*\{listings\}[ \t]*\n?/m", $lines === '' ? '' : $lines."\n", str_replace("\r\n", "\n", $data['message']));
        $message = trim(str_replace('{listings}', $lines, $message));
        if (mb_strlen($message) > AgentBroadcasts::MAX_LENGTH) {
            throw ValidationException::withMessages(['message' => 'With the chosen listings the message is too long. Choose fewer listings or shorten the text.']);
        }
        $id = $broadcasts->queue($tenant, auth()->id(), $data['kind'], $message, array_map('intval', $data['agent_ids']), $listingIds);
        $queued = DB::table('agent_broadcast_recipients')->where('broadcast_id', $id)->where('status', 'pending')->count();

        return redirect()->route('sales.broadcasts.show', [$tenant->slug, $id])
            ->with('success', $queued.' '.($queued === 1 ? 'SMS is' : 'SMS are').' queued and will be sent within a minute or two.');
    }

    public function show(string $tenant, int $id): View
    {
        $company = app('currentTenant');
        $broadcast = DB::table('agent_broadcasts as b')->leftJoin('users as u', 'u.id', '=', 'b.created_by')
            ->where('b.tenant_id', $company->id)->where('b.id', $id)->select('b.*', 'u.name as sender_name')->firstOrFail();

        return view('sales.broadcast_show', [
            'broadcast' => $broadcast,
            'recipients' => DB::table('agent_broadcast_recipients')->where('tenant_id', $company->id)->where('broadcast_id', $broadcast->id)->orderBy('agent_name')->get(),
        ]);
    }
}
