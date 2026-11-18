<?php

namespace App\Http\Controllers;

use App\Services\Social\MetaWebhook;
use App\Services\Social\SocialInbox;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Throwable;

/** Receives Facebook and Instagram comments and messages from Meta for every connected company. */
class MetaWebhookController extends Controller
{
    /** Meta checks the webhook URL once, when it is added in the Meta app. */
    public function verify(Request $request): Response
    {
        $token = (string) config('services.meta.webhook_verify_token');
        abort_unless($token !== '' && $request->query('hub_mode') === 'subscribe' && hash_equals($token, (string) $request->query('hub_verify_token')), 403);

        return response((string) $request->query('hub_challenge'), 200, ['Content-Type' => 'text/plain']);
    }

    public function receive(Request $request, SocialInbox $inbox): Response
    {
        $body = $request->getContent();
        abort_unless(MetaWebhook::signed($body, $request->header('X-Hub-Signature-256')), 403);
        foreach (MetaWebhook::events((array) json_decode($body, true)) as $event) {
            try {
                $inbox->record($event);
            } catch (Throwable $exception) {
                // One bad event must not make Meta retry the whole batch.
                report($exception);
            }
        }

        return response('EVENT_RECEIVED', 200, ['Content-Type' => 'text/plain']);
    }
}
