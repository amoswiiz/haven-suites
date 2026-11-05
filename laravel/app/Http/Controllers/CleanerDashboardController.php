<?php

namespace App\Http\Controllers;

use App\Services\CleanerDashboardSummary;
use Illuminate\View\View;

class CleanerDashboardController extends Controller
{
    public function index(string $tenant): View
    {
        abort_unless(auth()->user()?->active && auth()->user()->inGroup('cleaner')
            && auth()->user()->can('cleaner.dashboard'), 403);

        return view('dashboard.cleaner', [
            'pageTitle' => 'My dashboard',
            'summary' => app(CleanerDashboardSummary::class)->summary(),
        ]);
    }
}
