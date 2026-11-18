<?php

namespace App\Services\Social;

/**
 * Turns Meta webhook payloads (Facebook Page feed and Messenger, Instagram comments and messages) into simple
 * inbox events. Messages the business sent from Meta's own apps arrive as echoes and are kept as outgoing messages.
 */
final class MetaWebhook
{
    /** Whether the payload was signed with our app secret. */
    public static function signed(string $rawBody, ?string $signature): bool
    {
        $secret = (string) config('services.meta.app_secret');

        return $secret !== '' && is_string($signature) && str_starts_with($signature, 'sha256=')
            && hash_equals('sha256='.hash_hmac('sha256', $rawBody, $secret), $signature);
    }

    /**
     * @return list<array{platform: string, account_id: string, kind: string, thread_id: string, participant: array{id: ?string, name: ?string, username: ?string},
     *     context_id: ?string, message: array{external_id: string, direction: string, author_name: ?string, body: ?string, attachments: list<array{type: string, url: ?string}>, sent_at: int},
     *     action: string}>
     */
    public static function events(array $payload): array
    {
        $platform = match ($payload['object'] ?? null) {
            'page' => 'facebook', 'instagram' => 'instagram', default => null,
        };
        if ($platform === null) {
            return [];
        }
        $events = [];
        foreach ((array) ($payload['entry'] ?? []) as $entry) {
            $accountId = (string) ($entry['id'] ?? '');
            if ($accountId === '') {
                continue;
            }
            foreach ((array) ($entry['messaging'] ?? []) as $messaging) {
                if ($event = self::message($platform, $accountId, (array) $messaging)) {
                    $events[] = $event;
                }
            }
            foreach ((array) ($entry['changes'] ?? []) as $change) {
                if ($event = self::comment($platform, $accountId, (array) $change, (int) ($entry['time'] ?? time()))) {
                    $events[] = $event;
                }
            }
        }

        return $events;
    }

    private static function message(string $platform, string $accountId, array $messaging): ?array
    {
        $message = $messaging['message'] ?? null;
        if (! is_array($message) || empty($message['mid'])) {
            return null;
        }
        $echo = ! empty($message['is_echo']) || (string) ($messaging['sender']['id'] ?? '') === $accountId;
        $participant = (string) ($echo ? ($messaging['recipient']['id'] ?? '') : ($messaging['sender']['id'] ?? ''));
        if ($participant === '') {
            return null;
        }
        $attachments = array_values(array_map(fn ($attachment): array => ['type' => (string) ($attachment['type'] ?? 'file'), 'url' => $attachment['payload']['url'] ?? null],
            array_filter((array) ($message['attachments'] ?? []), 'is_array')));

        return [
            'platform' => $platform, 'account_id' => $accountId, 'kind' => 'message', 'thread_id' => $participant,
            'participant' => ['id' => $participant, 'name' => null, 'username' => null], 'context_id' => null,
            'message' => ['external_id' => (string) $message['mid'], 'direction' => $echo ? 'out' : 'in', 'author_name' => null,
                'body' => isset($message['text']) ? (string) $message['text'] : null, 'attachments' => $attachments,
                'sent_at' => (int) floor(((int) ($messaging['timestamp'] ?? time() * 1000)) / 1000)],
            'action' => ! empty($message['is_deleted']) ? 'remove' : 'add',
        ];
    }

    private static function comment(string $platform, string $accountId, array $change, int $time): ?array
    {
        $value = (array) ($change['value'] ?? []);
        if ($platform === 'facebook') {
            if (($change['field'] ?? null) !== 'feed' || ($value['item'] ?? null) !== 'comment' || empty($value['comment_id'])) {
                return null;
            }
            $commentId = (string) $value['comment_id'];
            $postId = (string) ($value['post_id'] ?? '');
            $parentId = (string) ($value['parent_id'] ?? '');
            $threadId = $parentId !== '' && $parentId !== $postId ? $parentId : $commentId;
            $from = (array) ($value['from'] ?? []);
            $sentAt = isset($value['created_time']) ? (is_numeric($value['created_time']) ? (int) $value['created_time'] : (int) strtotime((string) $value['created_time'])) : $time;
            $body = $value['message'] ?? null;
            $action = match ($value['verb'] ?? 'add') {
                'remove' => 'remove', 'edited' => 'edit', default => 'add',
            };
        } else {
            if (($change['field'] ?? null) !== 'comments' || empty($value['id'])) {
                return null;
            }
            $commentId = (string) $value['id'];
            $threadId = (string) ($value['parent_id'] ?? '') ?: $commentId;
            $postId = (string) ($value['media']['id'] ?? '');
            $from = ['id' => $value['from']['id'] ?? null, 'name' => null, 'username' => $value['from']['username'] ?? null];
            $sentAt = $time;
            $body = $value['text'] ?? null;
            $action = 'add';
        }
        $fromId = (string) ($from['id'] ?? '');
        $own = $fromId !== '' && $fromId === $accountId;

        return [
            'platform' => $platform, 'account_id' => $accountId, 'kind' => 'comment', 'thread_id' => $threadId,
            'participant' => $own ? ['id' => null, 'name' => null, 'username' => null] : ['id' => $fromId ?: null, 'name' => $from['name'] ?? null, 'username' => $from['username'] ?? null],
            'context_id' => $postId !== '' ? $postId : null,
            'message' => ['external_id' => $commentId, 'direction' => $own ? 'out' : 'in', 'author_name' => $own ? null : ($from['name'] ?? $from['username'] ?? null),
                'body' => $body !== null ? (string) $body : null, 'attachments' => [], 'sent_at' => $sentAt],
            'action' => $action,
        ];
    }
}
