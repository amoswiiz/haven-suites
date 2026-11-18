<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Services\Social\MetaGraph;
use App\Services\Social\SocialInbox;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/** Connects a company's Facebook Pages and linked Instagram accounts to the social inbox. */
class SocialConnectionController extends Controller
{
    public function connect(string $tenant, MetaGraph $graph): RedirectResponse
    {
        if (! MetaGraph::configured()) {
            return back()->withErrors(['connection' => 'Facebook and Instagram are not set up on this server yet. Add the Meta app ID and secret first.']);
        }
        $state = Str::random(40);
        session(['meta_oauth' => ['state' => $state, 'tenant_id' => app('currentTenant')->id]]);

        return redirect()->away($graph->loginUrl(route('meta.callback'), $state));
    }

    /** Facebook returns here after the person chooses which Pages to connect. */
    public function callback(Request $request, MetaGraph $graph): RedirectResponse
    {
        $saved = (array) $request->session()->pull('meta_oauth', []);
        $tenant = isset($saved['tenant_id']) ? Tenant::where('id', $saved['tenant_id'])->where('active', true)->first() : null;
        abort_unless($tenant && hash_equals((string) ($saved['state'] ?? ''), (string) $request->query('state')), 403);
        abort_unless($request->user()->tenants()->whereKey($tenant->id)->exists(), 403);
        app()->instance('currentTenant', $tenant);
        abort_unless($request->user()->can('messaging.manage'), 403);
        $back = redirect()->route('sales.connections', $tenant->slug);
        if ($request->filled('error') || ! $request->filled('code')) {
            return $back->withErrors(['connection' => 'Facebook was not connected. Try again and allow access to your Page and Instagram account.']);
        }
        try {
            $pages = $graph->pages($graph->userToken((string) $request->query('code'), route('meta.callback')));
        } catch (Throwable $exception) {
            report($exception);

            return $back->withErrors(['connection' => 'Facebook could not be connected. '.Str::limit($exception->getMessage(), 200)]);
        }
        if ($pages === []) {
            return $back->withErrors(['connection' => 'No Facebook Pages were shared. Connect again and tick the Haven Suites Page.']);
        }
        $connected = [];
        $skipped = [];
        foreach ($pages as $page) {
            $accounts = [['platform' => 'facebook', 'external_id' => $page['id'], 'name' => $page['name'], 'username' => null]];
            if ($page['instagram']) {
                $accounts[] = ['platform' => 'instagram', 'external_id' => $page['instagram']['id'],
                    'name' => $page['instagram']['name'] ?: ($page['instagram']['username'] ?: 'Instagram'), 'username' => $page['instagram']['username']];
            }
            $error = null;
            try {
                $graph->subscribe($page['id'], $page['access_token']);
            } catch (Throwable $exception) {
                $error = Str::limit($exception->getMessage(), 500);
            }
            foreach ($accounts as $account) {
                $existing = DB::table('social_accounts')->where('platform', $account['platform'])->where('external_id', $account['external_id'])->first();
                if ($existing && (int) $existing->tenant_id !== (int) $tenant->id && $existing->status !== 'disconnected') {
                    $skipped[] = $account['name'];

                    continue;
                }
                DB::table('social_accounts')->updateOrInsert(['platform' => $account['platform'], 'external_id' => $account['external_id']], [
                    'tenant_id' => $tenant->id, 'page_id' => $page['id'], 'name' => Str::limit($account['name'], 145), 'username' => $account['username'],
                    'access_token' => Crypt::encryptString($page['access_token']), 'status' => $error ? 'error' : 'connected', 'last_error' => $error,
                    'connected_by' => $request->user()->id, 'updated_at' => now(), 'created_at' => $existing->created_at ?? now(),
                ]);
                $connected[] = (SocialInbox::PLATFORMS[$account['platform']] ?? '').': '.$account['name'];
            }
        }
        $message = $connected !== [] ? 'Connected '.implode(', ', $connected).'.' : 'Nothing new was connected.';
        if ($skipped !== []) {
            $message .= ' Already connected to another company: '.implode(', ', $skipped).'.';
        }

        return $back->with('success', $message);
    }

    /** Stop receiving a Page's comments and messages (and its Instagram account's). Past conversations are kept. */
    public function disconnect(string $tenant, int $id, MetaGraph $graph): RedirectResponse
    {
        $account = DB::table('social_accounts')->where('tenant_id', app('currentTenant')->id)->where('id', $id)->firstOrFail();
        $pageId = $account->page_id ?: $account->external_id;
        $linked = DB::table('social_accounts')->where('tenant_id', app('currentTenant')->id)->whereIn('platform', ['facebook', 'instagram'])
            ->where(fn ($query) => $query->where('page_id', $pageId)->orWhere('external_id', $pageId));
        if ($token = SocialInbox::token($account)) {
            rescue(fn () => $graph->unsubscribe($pageId, $token), report: false);
        }
        $names = $linked->pluck('name')->all();
        $linked->update(['status' => 'disconnected', 'access_token' => null, 'updated_at' => now()]);

        return back()->with('success', 'Disconnected '.implode(' and ', $names).'. Past conversations stay in the inbox.');
    }
}
