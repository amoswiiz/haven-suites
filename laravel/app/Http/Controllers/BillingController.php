<?php

namespace App\Http\Controllers;

use App\Models\SubscriptionPayment;
use App\Services\SubscriptionBilling;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class BillingController extends Controller
{
    public function start(Request $request, SubscriptionBilling $billing): RedirectResponse
    {
        abort_unless($request->user()->inGroup('superadmin', 'admin'), 403);
        $data = $request->validate(['plan' => ['required', Rule::in(array_keys(config('saas.plans')))], 'provider' => ['required', Rule::in(['stripe', 'mpesa', 'manual'])], 'phone' => 'nullable|string|max:20']);
        $result = $billing->start(app('currentTenant'), $request->user(), $data['plan'], $data['provider'], $data['phone'] ?? null);
        if ($result['url']) {
            return redirect()->away($result['url']);
        }

        return back()->with('success', $data['provider'] === 'manual' ? 'Sandbox invoice created. Do not transfer real money.' : 'Sandbox M-Pesa request sent. Access changes only after verification.');
    }

    public function evidence(string $tenant, string $reference, Request $request): RedirectResponse
    {
        abort_unless($request->user()->inGroup('superadmin', 'admin'), 403);
        $data = $request->validate(['transaction_reference' => 'required|string|max:100']);
        $payment = $this->payment($reference);
        abort_unless($payment->provider === 'manual' && $payment->status === 'pending', 409);
        $payment->update(['metadata' => ['submitted_reference' => $data['transaction_reference']]]);

        return back()->with('success', 'Test reference submitted for platform review. This does not activate the subscription.');
    }

    public function approve(int $id, Request $request, SubscriptionBilling $billing): RedirectResponse
    {
        $data = $request->validate(['transaction_reference' => 'required|string|max:100']);
        $payment = SubscriptionPayment::findOrFail($id);
        abort_unless($payment->provider === 'manual', 403);
        $billing->settle($payment, 'manual-test:'.$data['transaction_reference'], $request->user()->id);

        return back()->with('success', 'Sandbox invoice approved and test subscription activated.');
    }

    public function invoice(string $tenant, string $reference): Response
    {
        abort_unless(auth()->user()->inGroup('superadmin', 'admin'), 403);
        $payment = $this->payment($reference);
        abort_unless($payment->provider === 'manual', 404);
        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $pdf = new Dompdf($options);
        $pdf->loadHtml(view('saas.subscription-invoice', compact('payment'))->render());
        $pdf->setPaper('A4');
        $pdf->render();

        return response($pdf->output())->header('Content-Type', 'application/pdf')->header('Content-Disposition', 'attachment; filename="sandbox-subscription-'.$payment->reference.'.pdf"')->header('Cache-Control', 'private, no-store');
    }

    public function stripe(Request $request, SubscriptionBilling $billing): JsonResponse
    {
        $billing->stripeWebhook($request->getContent(), (string) $request->header('Stripe-Signature'));

        return response()->json(['received' => true]);
    }

    public function mpesa(string $token, Request $request, SubscriptionBilling $billing): JsonResponse
    {
        $billing->mpesaWebhook($token, $request->all());

        return response()->json(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
    }

    private function payment(string $reference): SubscriptionPayment
    {
        return SubscriptionPayment::where('tenant_id', app('currentTenant')->id)->where('reference', $reference)->firstOrFail();
    }
}
