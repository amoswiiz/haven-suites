<?php

namespace App\Http\Controllers;

use App\Services\RoyceTalkSms;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class CommunicationController extends Controller
{
    public function index(RoyceTalkSms $transport): View
    {
        return view('communications.sms', ['ready' => config('services.roycetalk.manual_live') && $transport->configured(), 'sender' => config('services.roycetalk.sender_id'), 'messages' => DB::table('manual_sms_messages as m')->leftJoin('users as u', 'u.id', '=', 'm.sent_by')->where('m.tenant_id', app('currentTenant')->id)->select('m.*', 'u.name as staff_name')->latest('m.id')->paginate(15)]);
    }

    public function send(Request $request, RoyceTalkSms $transport): RedirectResponse
    {
        $data = $request->validate(['phone' => ['required', 'string', 'regex:/^\+[1-9][0-9]{7,14}$/'], 'body' => 'required|string|max:1600', 'send_token' => 'required|uuid']);
        if (! config('services.roycetalk.manual_live') || ! $transport->configured()) {
            return back()->withErrors(['sms' => 'Manual SMS is not enabled or the provider is not configured. No message sent.'])->withInput();
        }
        $tenant = app('currentTenant');
        $inserted = DB::table('manual_sms_messages')->insertOrIgnore(['tenant_id' => $tenant->id, 'sent_by' => auth()->id(), 'send_token' => $data['send_token'], 'phone' => $data['phone'], 'body' => $data['body'], 'sender_id' => config('services.roycetalk.sender_id'), 'status' => 'processing', 'created_at' => now(), 'updated_at' => now()]);
        if (! $inserted) {
            return redirect()->route('communications.sms', $tenant->slug)->with('success', 'This send request was already recorded. Check its status in the history. No duplicate sent.');
        }
        $id = DB::table('manual_sms_messages')->where('tenant_id', $tenant->id)->where('send_token', $data['send_token'])->value('id');
        $update = ['updated_at' => now()];
        try {
            $response = $transport->send($data['phone'], $data['body'], 'manual-'.$tenant->id.'-'.$id);
            $reference = $response->json('data.message_id');
            if ($response->successful() && $response->json('success') === true && is_string($reference) && preg_match('/^[a-zA-Z0-9-]{1,100}$/', $reference)) {
                $update += ['status' => 'submitted', 'provider_reference' => $reference, 'provider_status' => 'pending', 'submitted_at' => now(), 'last_error' => null];
            } elseif (in_array($response->status(), [400, 401, 403, 404, 422, 429], true)) {
                $update += ['status' => 'rejected', 'last_error' => 'Provider rejected the request (HTTP '.$response->status().'). Check the number, sender, balance or rate limit before trying again.'];
            } else {
                $update += ['status' => 'unknown', 'last_error' => 'Acceptance could not be confirmed. Check RoyceTalk before sending this message again.'];
            }
        } catch (\Throwable $exception) {
            $update += ['status' => 'unknown', 'last_error' => 'Connection interrupted. Delivery is uncertain. Check RoyceTalk before sending this message again.'];
        }
        DB::table('manual_sms_messages')->where('tenant_id', $tenant->id)->where('id', $id)->update($update);
        if ($update['status'] === 'submitted') {
            return redirect()->route('communications.sms', $tenant->slug)->with('success', 'SMS submitted to RoyceTalk. Delivery confirmation will appear in the history.');
        }

        return redirect()->route('communications.sms', $tenant->slug)->withErrors(['sms' => $update['last_error']]);
    }
}
