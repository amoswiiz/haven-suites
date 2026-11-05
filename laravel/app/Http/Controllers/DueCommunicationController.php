<?php

namespace App\Http\Controllers;

use App\Services\DueCommunications;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class DueCommunicationController extends Controller
{
    public function index(string $tenant, Request $request, DueCommunications $service): View
    {
        abort_unless($request->user()?->active && $request->user()->can('messaging.view'), 403);
        $data = $request->validate(['state' => ['nullable', Rule::in(['all', 'ready', 'review'])]]);
        $summary = $service->summary(PHP_INT_MAX);
        $state = $data['state'] ?? 'all';
        $items = $state === 'all' ? $summary['rows'] : $summary['rows']->where('state', $state)->values();
        $page = max(1, LengthAwarePaginator::resolveCurrentPage());
        $rows = new LengthAwarePaginator($items->forPage($page, 20)->values(), $items->count(), 20, $page, [
            'path' => $request->url(), 'query' => $request->only('state'),
        ]);

        return view('communications.due', ['communicationQueue' => $summary, 'rows' => $rows, 'state' => $state]);
    }
}
