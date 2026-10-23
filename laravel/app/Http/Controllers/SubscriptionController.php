<?php

namespace App\Http\Controllers;

use App\Models\SubscriptionPayment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SubscriptionController extends Controller
{
    public function show(): View
    {
        abort_unless(auth()->user()->inGroup('superadmin', 'admin'), 403);

        return view('saas.subscription', ['plans' => config('saas.plans'), 'payments' => SubscriptionPayment::where('tenant_id', app('currentTenant')->id)->latest()->limit(30)->get()]);
    }

    public function request(Request $request): RedirectResponse
    {
        abort_unless($request->user()->inGroup('superadmin', 'admin'), 403);
        $data = $request->validate(['plan' => ['required', Rule::in(array_keys(config('saas.plans')))]]);
        app('currentTenant')->update(['requested_plan' => $data['plan']]);

        return back()->with('success', 'Your plan request has been submitted. Your limits will change only after the subscription is activated.');
    }
}
