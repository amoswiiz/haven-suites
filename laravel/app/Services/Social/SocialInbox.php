<?php

namespace App\Services\Social;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/** Stores social comments and messages as conversations, and sends staff replies back to the platform. */
class SocialInbox
{
    public const PLATFORMS = ['facebook' => 'Facebook', 'instagram' => 'Instagram', 'whatsapp' => 'WhatsApp', 'google' => 'Google'];

    public const STATUSES = ['needs_reply' => 'Needs reply', 'open' => 'Replied', 'done' => 'Done'];

    /** Meta lets businesses reply to a direct message only within this many hours of the person's last message. */
    public const REPLY_WINDOW_HOURS = 24;

    public function __construct(private MetaGraph $graph) {}

    public static function token(object $account): ?string
    {
        if (empty($account->access_token)) {
            return null;
        }
        try {
            return Crypt::decryptString($account->access_token);
        } catch (Throwable) {
            return null;
        }
    }

    /** Whether a direct message can still be answered from Haven. Comments can always be answered. */
    public static function canReply(object $thread): bool
    {
        return $thread->kind !== 'message' || ($thread->last_inbound_at !== null
            && Carbon::parse($thread->last_inbound_at)->greaterThan(now()->subHours(self::REPLY_WINDOW_HOURS)));
    }

    /**
     * Record one event from a webhook. Returns the message id, or null when it was ignored or already recorded.
     *
     * @param  array<string, mixed>  $event  From MetaWebhook::events().
     */
    public function record(array $event): ?int
    {
        $account = DB::table('social_accounts')->where('platform', $event['platform'])->where('external_id', $event['account_id'])
            ->where('status', '!=', 'disconnected')->first();
        if (! $account) {
            return null;
        }
        $message = $event['message'];
        DB::table('social_accounts')->where('id', $account->id)->update(['last_event_at' => now()]);
        if ($event['action'] === 'remove' || $event['action'] === 'edit') {
            $changes = $event['action'] === 'remove' ? ['status' => 'deleted'] : ['body' => $message['body']];
            DB::table('social_messages')->where('social_account_id', $account->id)->where('external_id', $message['external_id'])->update($changes + ['updated_at' => now()]);

            return null;
        }
        $thread = DB::table('social_threads')->where('social_account_id', $account->id)->where('kind', $event['kind'])->where('external_id', $event['thread_id'])->first();
        if (! $thread && $message['direction'] === 'out' && $event['kind'] === 'comment') {
            // The business commenting on its own post is not a conversation with a guest.
            return null;
        }
        $sentAt = Carbon::createFromTimestamp($message['sent_at']);
        $thread ??= $this->newThread($account, $event, $sentAt);
        try {
            $id = DB::table('social_messages')->insertGetId([
                'tenant_id' => $account->tenant_id, 'social_account_id' => $account->id, 'social_thread_id' => $thread->id,
                'external_id' => $message['external_id'], 'direction' => $message['direction'], 'origin' => $message['direction'] === 'in' ? 'guest' : 'platform',
                'author_name' => $message['author_name'], 'body' => $message['body'],
                'attachments' => $message['attachments'] !== [] ? json_encode($message['attachments'], JSON_THROW_ON_ERROR) : null,
                'status' => $message['direction'] === 'in' ? 'received' : 'sent', 'sent_at' => $sentAt, 'created_at' => now(), 'updated_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            // Already recorded: Meta resends events, and replies sent from Haven come back as echoes.
            return null;
        }
        $this->touch($thread, $message['direction'], $sentAt, $this->preview($message['body'], $message['attachments']));
        if ($message['direction'] === 'in' && $event['kind'] === 'comment' && empty($thread->participant_name) && ! empty($message['author_name'])) {
            DB::table('social_threads')->where('id', $thread->id)->update(['participant_name' => $message['author_name']]);
        }

        return $id;
    }

    /** Send a staff reply from Haven and record it in the conversation. */
    public function reply(object $thread, string $text, int $userId): int
    {
        $account = DB::table('social_accounts')->where('id', $thread->social_account_id)->first();
        $token = $account && $account->status === 'connected' ? self::token($account) : null;
        if ($token === null) {
            throw ValidationException::withMessages(['body' => 'This account is not connected. Reconnect it in Channel connections, then try again.']);
        }
        if (! self::canReply($thread)) {
            throw ValidationException::withMessages(['body' => 'More than '.self::REPLY_WINDOW_HOURS.' hours have passed since this person last wrote, so Meta does not allow a reply from Haven. Reply from the '
                .(self::PLATFORMS[$account->platform] ?? 'platform').' app instead.']);
        }
        try {
            $externalId = $thread->kind === 'comment'
                ? $this->graph->replyToComment($account->platform, $thread->external_id, $text, $token)
                : $this->graph->sendMessage((string) $thread->participant_id, $text, $token);
        } catch (Throwable $exception) {
            report($exception);
            throw ValidationException::withMessages(['body' => 'The reply was not sent. '.Str::limit($exception->getMessage(), 200)]);
        }
        $sentAt = now();
        try {
            $id = DB::table('social_messages')->insertGetId([
                'tenant_id' => $thread->tenant_id, 'social_account_id' => $account->id, 'social_thread_id' => $thread->id,
                'external_id' => $externalId !== '' ? $externalId : null, 'direction' => 'out', 'origin' => 'haven',
                'body' => $text, 'sent_by' => $userId, 'status' => 'sent', 'sent_at' => $sentAt, 'created_at' => now(), 'updated_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            // Meta's echo of this reply arrived first; mark it as sent from Haven by this person.
            $query = DB::table('social_messages')->where('social_account_id', $account->id)->where('external_id', $externalId);
            $query->update(['origin' => 'haven', 'sent_by' => $userId, 'updated_at' => now()]);
            $id = (int) $query->value('id');
        }
        $this->touch($thread, 'out', $sentAt, $this->preview($text, []));

        return $id;
    }

    private function newThread(object $account, array $event, Carbon $sentAt): object
    {
        $participant = $event['participant'];
        $values = [
            'tenant_id' => $account->tenant_id, 'social_account_id' => $account->id, 'kind' => $event['kind'], 'external_id' => $event['thread_id'],
            'participant_id' => $participant['id'], 'participant_name' => $participant['name'], 'participant_username' => $participant['username'],
            'context_id' => $event['context_id'], 'status' => 'needs_reply', 'unread' => true, 'last_message_at' => $sentAt,
            'created_at' => now(), 'updated_at' => now(),
        ];
        $token = self::token($account);
        if ($token !== null) {
            try {
                if ($event['kind'] === 'message' && $participant['id'] && ! $participant['name']) {
                    $profile = $this->graph->profile($participant['id'], $token, $account->platform);
                    $values['participant_name'] = $profile['name'] ? Str::limit($profile['name'], 145) : null;
                    $values['participant_username'] = $profile['username'] ? Str::limit($profile['username'], 145) : null;
                }
                if ($event['context_id']) {
                    $context = $this->graph->context($event['context_id'], $token, $account->platform);
                    $values['context_title'] = $context['title'] ? Str::limit(preg_replace('/\s+/u', ' ', $context['title']), 250) : null;
                    $values['context_url'] = $context['url'] && strlen($context['url']) <= 500 ? $context['url'] : null;
                }
            } catch (Throwable) {
                // Names and post details are a convenience; the message is recorded without them.
            }
        }
        try {
            $id = DB::table('social_threads')->insertGetId($values);
        } catch (UniqueConstraintViolationException) {
            $id = (int) DB::table('social_threads')->where('social_account_id', $account->id)->where('kind', $event['kind'])->where('external_id', $event['thread_id'])->value('id');
        }

        return DB::table('social_threads')->where('id', $id)->first();
    }

    private function touch(object $thread, string $direction, Carbon $sentAt, string $preview): void
    {
        $fresh = DB::table('social_threads')->where('id', $thread->id)->first();
        $latest = $fresh->last_message_at === null || $sentAt->greaterThanOrEqualTo(Carbon::parse($fresh->last_message_at));
        $changes = ['updated_at' => now()];
        if ($latest) {
            $changes += ['last_message_at' => $sentAt, 'last_preview' => $preview];
        }
        if ($direction === 'in') {
            $changes += ['status' => 'needs_reply', 'unread' => true];
            if ($fresh->last_inbound_at === null || $sentAt->greaterThan(Carbon::parse($fresh->last_inbound_at))) {
                $changes['last_inbound_at'] = $sentAt;
            }
        } elseif ($latest && $fresh->status === 'needs_reply') {
            // Answered, from Haven or from the platform's own app.
            $changes['status'] = 'open';
        }
        DB::table('social_threads')->where('id', $thread->id)->update($changes);
    }

    /** @param  list<array{type: string, url: ?string}>  $attachments */
    private function preview(?string $body, array $attachments): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', (string) $body));
        if ($text === '' && $attachments !== []) {
            $text = '['.ucfirst($attachments[0]['type']).']';
        }

        return Str::limit($text, 250);
    }
}
