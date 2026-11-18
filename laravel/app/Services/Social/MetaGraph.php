<?php

namespace App\Services\Social;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Calls to Meta's Graph API for Facebook Pages and Instagram business accounts. */
class MetaGraph
{
    /** Permissions asked for when a company connects its Page and Instagram account. */
    public const SCOPES = ['pages_show_list', 'pages_manage_metadata', 'pages_read_engagement', 'pages_read_user_content', 'pages_manage_engagement',
        'pages_messaging', 'instagram_basic', 'instagram_manage_comments', 'instagram_manage_messages', 'business_management'];

    /** Page webhook fields: comments and posts, Messenger messages, and replies sent from Meta's own apps. */
    public const PAGE_FIELDS = ['feed', 'messages', 'message_echoes'];

    public static function configured(): bool
    {
        return filled(config('services.meta.app_id')) && filled(config('services.meta.app_secret'));
    }

    private function base(): string
    {
        return 'https://graph.facebook.com/'.config('services.meta.graph_version', 'v23.0');
    }

    private function client(): PendingRequest
    {
        return Http::timeout(15)->connectTimeout(5)->acceptJson();
    }

    public function loginUrl(string $redirectUri, string $state): string
    {
        return 'https://www.facebook.com/'.config('services.meta.graph_version', 'v23.0').'/dialog/oauth?'.http_build_query([
            'client_id' => config('services.meta.app_id'), 'redirect_uri' => $redirectUri, 'state' => $state,
            'scope' => implode(',', self::SCOPES), 'response_type' => 'code',
        ]);
    }

    /** Exchange the login code for a long-lived user token, whose Page tokens do not expire. */
    public function userToken(string $code, string $redirectUri): string
    {
        $short = $this->get('/oauth/access_token', ['client_id' => config('services.meta.app_id'), 'client_secret' => config('services.meta.app_secret'),
            'redirect_uri' => $redirectUri, 'code' => $code])['access_token'] ?? null;
        $long = $this->get('/oauth/access_token', ['grant_type' => 'fb_exchange_token', 'client_id' => config('services.meta.app_id'),
            'client_secret' => config('services.meta.app_secret'), 'fb_exchange_token' => $short])['access_token'] ?? null;
        if (! is_string($long) || $long === '') {
            throw new RuntimeException('Facebook did not return an access token.');
        }

        return $long;
    }

    /**
     * Pages the person manages, with each Page's token and linked Instagram business account.
     *
     * @return list<array{id: string, name: string, access_token: string, instagram: ?array{id: string, username: ?string, name: ?string}}>
     */
    public function pages(string $userToken): array
    {
        $data = $this->get('/me/accounts', ['fields' => 'id,name,access_token,instagram_business_account{id,username,name}', 'limit' => 100, 'access_token' => $userToken])['data'] ?? [];

        return array_values(array_map(fn (array $page): array => [
            'id' => (string) $page['id'], 'name' => (string) ($page['name'] ?? 'Facebook Page'), 'access_token' => (string) ($page['access_token'] ?? ''),
            'instagram' => isset($page['instagram_business_account']['id']) ? ['id' => (string) $page['instagram_business_account']['id'],
                'username' => $page['instagram_business_account']['username'] ?? null, 'name' => $page['instagram_business_account']['name'] ?? null] : null,
        ], array_filter($data, fn ($page): bool => is_array($page) && isset($page['id']))));
    }

    /** Ask Meta to send this Page's comments and messages (and its Instagram account's) to our webhook. */
    public function subscribe(string $pageId, string $pageToken): void
    {
        $this->post('/'.$pageId.'/subscribed_apps', ['subscribed_fields' => implode(',', self::PAGE_FIELDS), 'access_token' => $pageToken]);
    }

    public function unsubscribe(string $pageId, string $pageToken): void
    {
        $this->client()->delete($this->base().'/'.$pageId.'/subscribed_apps', ['access_token' => $pageToken]);
    }

    /** @return array{name: ?string, username: ?string} */
    public function profile(string $userId, string $token, string $platform): array
    {
        $data = $this->get('/'.$userId, ['fields' => $platform === 'instagram' ? 'name,username' : 'name', 'access_token' => $token]);

        return ['name' => $data['name'] ?? null, 'username' => $data['username'] ?? null];
    }

    /** @return array<string, mixed> */
    public function post(string $path, array $form): array
    {
        $response = $this->client()->asForm()->post($this->base().$path, $form);
        $this->fail($response->json(), $response->successful());

        return (array) $response->json();
    }

    /** @return array{title: ?string, url: ?string} The post or media a comment was left on. */
    public function context(string $objectId, string $token, string $platform): array
    {
        $data = $this->get('/'.$objectId, ['fields' => $platform === 'instagram' ? 'caption,permalink' : 'message,permalink_url', 'access_token' => $token]);

        return ['title' => $data['caption'] ?? $data['message'] ?? null, 'url' => $data['permalink'] ?? $data['permalink_url'] ?? null];
    }

    /** Reply under a comment. Returns the new comment's id. */
    public function replyToComment(string $platform, string $commentId, string $text, string $token): string
    {
        $data = $this->post('/'.$commentId.($platform === 'instagram' ? '/replies' : '/comments'), ['message' => $text, 'access_token' => $token]);

        return (string) ($data['id'] ?? '');
    }

    /** Send a direct message (Messenger or Instagram) as a reply within Meta's 24-hour window. Returns the message id. */
    public function sendMessage(string $recipientId, string $text, string $token): string
    {
        $response = $this->client()->post($this->base().'/me/messages?access_token='.urlencode($token), [
            'recipient' => ['id' => $recipientId], 'messaging_type' => 'RESPONSE', 'message' => ['text' => $text],
        ]);
        $this->fail($response->json(), $response->successful());

        return (string) ($response->json('message_id') ?? '');
    }

    private function get(string $path, array $query): array
    {
        $response = $this->client()->get($this->base().$path, $query);
        $this->fail($response->json(), $response->successful());

        return (array) $response->json();
    }

    private function fail(mixed $body, bool $successful): void
    {
        if (! $successful || isset($body['error'])) {
            $message = is_array($body) ? ($body['error']['message'] ?? null) : null;
            throw new RuntimeException($message ? 'Facebook: '.$message : 'Facebook did not accept the request.');
        }
    }
}
