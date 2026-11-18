<?php

namespace Tests\Feature;

use App\Http\Middleware\ResolveTenant;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Facebook and Instagram comments and messages in one inbox, answered from Haven or from Meta's own apps. */
class SocialInboxTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $company;

    private User $admin;

    private int $page;

    private int $instagram;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->travelTo(CarbonImmutable::parse('2026-11-18 09:00:00', 'UTC'));
        config(['services.meta' => ['app_id' => '111', 'app_secret' => 'app-secret', 'webhook_verify_token' => 'verify-me', 'graph_version' => 'v23.0'],
            'database.connections.tenant' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]);
        DB::purge('tenant');
        $this->company = Tenant::create(['name' => 'Inbox stays', 'slug' => 'inbox-stays', 'database_name' => ':memory:', 'active' => true, 'timezone' => 'Africa/Nairobi']);
        $this->admin = $this->member($this->company, 'admin');
        app()->instance('currentTenant', $this->company);
        $this->withoutMiddleware(ResolveTenant::class)->actingAs($this->admin);
        $this->page = $this->account($this->company, 'facebook', 'PAGE1', 'Haven Suites');
        $this->instagram = $this->account($this->company, 'instagram', 'IG1', 'havensuites', 'PAGE1');
    }

    private function member(Tenant $tenant, string $role): User
    {
        $user = User::factory()->create(['active' => true]);
        $user->tenants()->attach($tenant, ['roles' => json_encode([$role]), 'legacy_user_id' => 1]);

        return $user;
    }

    private function account(Tenant $tenant, string $platform, string $id, string $name, ?string $pageId = null): int
    {
        return DB::table('social_accounts')->insertGetId(['tenant_id' => $tenant->id, 'platform' => $platform, 'external_id' => $id, 'page_id' => $pageId ?? $id,
            'name' => $name, 'access_token' => Crypt::encryptString('page-token'), 'status' => 'connected', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function webhook(array $payload, ?string $secret = 'app-secret')
    {
        $body = json_encode($payload);
        $headers = $secret ? ['X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $body, $secret), 'Content-Type' => 'application/json'] : ['Content-Type' => 'application/json'];

        return $this->call('POST', '/webhooks/meta', [], [], [], $this->transformHeadersToServerVars($headers), $body);
    }

    private function messenger(string $mid, string $text, bool $echo = false, string $object = 'page', string $account = 'PAGE1', int $at = 1794991800000): array
    {
        $message = ['mid' => $mid, 'text' => $text] + ($echo ? ['is_echo' => true] : []);

        return ['object' => $object, 'entry' => [['id' => $account, 'time' => $at, 'messaging' => [[
            'sender' => ['id' => $echo ? $account : 'GUEST1'], 'recipient' => ['id' => $echo ? 'GUEST1' : $account], 'timestamp' => $at, 'message' => $message,
        ]]]]];
    }

    private function thread(string $kind, string $externalId): ?object
    {
        return DB::table('social_threads')->where('kind', $kind)->where('external_id', $externalId)->first();
    }

    public function test_meta_verifies_the_webhook_and_unsigned_events_are_rejected(): void
    {
        $this->get('/webhooks/meta?hub.mode=subscribe&hub.verify_token=verify-me&hub.challenge=12345')->assertOk()->assertSeeText('12345');
        $this->get('/webhooks/meta?hub.mode=subscribe&hub.verify_token=wrong&hub.challenge=12345')->assertForbidden();
        $this->webhook($this->messenger('m1', 'Hello'), null)->assertForbidden();
        $this->webhook($this->messenger('m1', 'Hello'), 'wrong-secret')->assertForbidden();
        $this->assertSame(0, DB::table('social_messages')->count());
    }

    public function test_messages_arrive_once_and_replies_from_meta_apps_are_recorded(): void
    {
        Http::fake(['graph.facebook.com/v23.0/GUEST1*' => Http::response(['name' => 'Amina Otieno'])]);

        $this->webhook($this->messenger('m1', 'Is Coral Gables free 20 to 23 December for 4 people?'))->assertOk()->assertSeeText('EVENT_RECEIVED');
        $this->webhook($this->messenger('m1', 'Is Coral Gables free 20 to 23 December for 4 people?'))->assertOk();
        $thread = $this->thread('message', 'GUEST1');
        $this->assertSame(['Amina Otieno', 'needs_reply', 1], [$thread->participant_name, $thread->status, (int) $thread->unread]);
        $this->assertSame(1, DB::table('social_messages')->count(), 'Meta resending an event does not duplicate it.');

        $this->webhook($this->messenger('m2', 'Yes, it is available. Shall I send the price?', echo: true))->assertOk();
        $reply = DB::table('social_messages')->where('external_id', 'm2')->first();
        $this->assertSame(['out', 'platform'], [$reply->direction, $reply->origin], 'A reply typed in Business Suite is kept as sent from the Facebook app.');
        $this->assertSame('open', $this->thread('message', 'GUEST1')->status);

        $this->get(route('social.index', $this->company->slug))->assertOk()->assertDontSee('Amina Otieno');
        $this->get(route('social.index', $this->company->slug).'?status=all')->assertOk()->assertSee('Amina Otieno')->assertSee('Shall I send the price?');
        $this->get(route('social.show', [$this->company->slug, $thread->id]))->assertOk()
            ->assertSee('Is Coral Gables free 20 to 23 December')->assertSee('Sent from the Facebook app')->assertSee('Create sales enquiry')
            ->assertSee('source=facebook', false)->assertSee('social_thread='.$thread->id, false);
        $this->assertSame(0, (int) $this->thread('message', 'GUEST1')->unread, 'Opening the conversation marks it read.');
    }

    public function test_staff_reply_to_a_comment_from_haven_and_the_echo_is_not_duplicated(): void
    {
        Http::fake([
            'graph.facebook.com/v23.0/POST1_C1/comments' => Http::response(['id' => 'POST1_C2']),
            'graph.facebook.com/v23.0/POST1*' => Http::response(['message' => 'Christmas offer: 15% off Nyali apartments', 'permalink_url' => 'https://facebook.com/haven/posts/1']),
        ]);
        $comment = fn (string $id, string $from, string $name, string $text, string $parent = 'POST1') => ['object' => 'page', 'entry' => [['id' => 'PAGE1', 'time' => 1794991800,
            'changes' => [['field' => 'feed', 'value' => ['item' => 'comment', 'verb' => 'add', 'comment_id' => $id, 'post_id' => 'POST1', 'parent_id' => $parent,
                'from' => ['id' => $from, 'name' => $name], 'message' => $text, 'created_time' => 1794991800]]]]]];

        $this->webhook($comment('POST1_C1', 'FAN1', 'Brian K', 'How much per night?'))->assertOk();
        $thread = $this->thread('comment', 'POST1_C1');
        $this->assertSame(['Brian K', 'Christmas offer: 15% off Nyali apartments'], [$thread->participant_name, $thread->context_title]);

        $this->post(route('social.reply', [$this->company->slug, $thread->id]), ['body' => 'Hi Brian, we have sent you a message with prices.'])->assertSessionHasNoErrors();
        Http::assertSent(fn ($request) => str_contains($request->url(), 'POST1_C1/comments') && $request['message'] === 'Hi Brian, we have sent you a message with prices.');
        $this->webhook($comment('POST1_C2', 'PAGE1', 'Haven Suites', 'Hi Brian, we have sent you a message with prices.', 'POST1_C1'))->assertOk();
        $replies = DB::table('social_messages')->where('direction', 'out')->get();
        $this->assertCount(1, $replies, 'The echo of a reply sent from Haven is not stored twice.');
        $this->assertSame(['haven', $this->admin->id], [$replies[0]->origin, (int) $replies[0]->sent_by]);
        $this->assertSame('open', $this->thread('comment', 'POST1_C1')->status);

        $this->webhook($comment('POST1_C9', 'PAGE1', 'Haven Suites', 'New photos are up!'))->assertOk();
        $this->assertNull($this->thread('comment', 'POST1_C9'), 'The business commenting on its own post is not a conversation.');
    }

    public function test_instagram_comments_and_messages_and_the_reply_window(): void
    {
        Http::fake([
            'graph.facebook.com/v23.0/MEDIA1*' => Http::response(['caption' => 'Sunset from Coral Gables', 'permalink' => 'https://instagram.com/p/abc']),
            'graph.facebook.com/v23.0/GUEST1*' => Http::response(['name' => 'Wanjiru', 'username' => 'wanjiru.travels']),
            'graph.facebook.com/v23.0/me/messages*' => Http::response(['message_id' => 'igm2']),
        ]);
        $this->webhook(['object' => 'instagram', 'entry' => [['id' => 'IG1', 'time' => 1794991800, 'changes' => [['field' => 'comments', 'value' => [
            'id' => 'IGC1', 'text' => 'Wow, where is this?', 'from' => ['id' => 'FAN2', 'username' => 'kevo254'], 'media' => ['id' => 'MEDIA1']]]]]]])->assertOk();
        $comment = $this->thread('comment', 'IGC1');
        $this->assertSame(['kevo254', 'Sunset from Coral Gables', $this->instagram], [$comment->participant_username, $comment->context_title, (int) $comment->social_account_id]);

        $this->webhook($this->messenger('igm1', 'Hi! Do you have a 2 bedroom in Nyali?', object: 'instagram', account: 'IG1', at: now()->subHour()->getTimestampMs()))->assertOk();
        $dm = $this->thread('message', 'GUEST1');
        $this->assertSame('wanjiru.travels', $dm->participant_username);
        $this->post(route('social.reply', [$this->company->slug, $dm->id]), ['body' => 'Yes! Coral Gables F2 is free.'])->assertSessionHasNoErrors();
        Http::assertSent(fn ($request) => str_contains($request->url(), 'me/messages') && $request['recipient']['id'] === 'GUEST1' && $request['messaging_type'] === 'RESPONSE');

        $this->travel(25)->hours();
        $this->post(route('social.reply', [$this->company->slug, $dm->id]), ['body' => 'Still interested?'])->assertSessionHasErrors('body');
        $this->get(route('social.show', [$this->company->slug, $dm->id]))->assertOk()->assertSee('only allows a reply from the Instagram app');
    }

    public function test_conversations_are_assigned_closed_and_private_to_each_company(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['name' => 'Guest'])]);
        $this->webhook($this->messenger('m1', 'Hello'))->assertOk();
        $thread = $this->thread('message', 'GUEST1');
        $sales = $this->member($this->company, 'sales');

        $this->post(route('social.update', [$this->company->slug, $thread->id]), ['assigned_to' => $sales->id])->assertSessionHasNoErrors();
        $this->post(route('social.update', [$this->company->slug, $thread->id]), ['status' => 'done'])->assertRedirect(route('social.index', $this->company->slug));
        $this->assertSame(['done', $sales->id], [$this->thread('message', 'GUEST1')->status, (int) $this->thread('message', 'GUEST1')->assigned_to]);
        $this->post(route('social.update', [$this->company->slug, $thread->id]), ['assigned_to' => 999999])->assertSessionHasErrors('assigned_to');

        $this->webhook($this->messenger('m3', 'One more question'))->assertOk();
        $this->assertSame('needs_reply', $this->thread('message', 'GUEST1')->status, 'A new message reopens a finished conversation.');

        $other = Tenant::create(['name' => 'Other stays', 'slug' => 'other-stays', 'database_name' => 'other_stays_test', 'active' => true]);
        $outsider = $this->member($other, 'admin');
        app()->instance('currentTenant', $other);
        $this->actingAs($outsider)->get(route('social.show', [$other->slug, $thread->id]))->assertNotFound();
        $this->actingAs($outsider)->get(route('social.index', $other->slug).'?status=all')->assertOk()->assertDontSee('One more question');
    }

    public function test_connecting_facebook_stores_each_page_and_its_instagram_account(): void
    {
        DB::table('social_accounts')->delete();
        Http::fake([
            'graph.facebook.com/v23.0/oauth/access_token*' => Http::sequence()->push(['access_token' => 'short'])->push(['access_token' => 'long-user']),
            'graph.facebook.com/v23.0/me/accounts*' => Http::response(['data' => [['id' => 'PAGE9', 'name' => 'Haven Suites BNB', 'access_token' => 'page-9-token',
                'instagram_business_account' => ['id' => 'IG9', 'username' => 'havensuitesbnb', 'name' => 'Haven Suites']]]]),
            'graph.facebook.com/v23.0/PAGE9/subscribed_apps' => Http::response(['success' => true]),
        ]);
        $this->get(route('sales.connections', $this->company->slug))->assertOk()->assertSee('Connect Facebook &amp; Instagram', false);
        $redirect = $this->post(route('social.connect', $this->company->slug))->assertRedirect()->headers->get('Location');
        $this->assertStringContainsString('facebook.com/v23.0/dialog/oauth', $redirect);
        parse_str((string) parse_url($redirect, PHP_URL_QUERY), $query);
        $this->assertStringContainsString('instagram_manage_messages', $query['scope']);

        $this->get(route('meta.callback').'?code=abc&state=wrong')->assertForbidden();
        $this->post(route('social.connect', $this->company->slug));
        $state = session('meta_oauth.state');
        $this->get(route('meta.callback').'?code=abc&state='.$state)->assertRedirect(route('sales.connections', $this->company->slug))
            ->assertSessionHas('success', 'Connected Facebook: Haven Suites BNB, Instagram: Haven Suites.');
        $accounts = DB::table('social_accounts')->orderBy('platform')->get();
        $this->assertSame(['facebook', 'instagram'], $accounts->pluck('platform')->all());
        $this->assertSame(['PAGE9', 'PAGE9'], $accounts->pluck('page_id')->all());
        $this->assertSame('page-9-token', Crypt::decryptString($accounts[0]->access_token));
        $this->assertStringNotContainsString('page-9-token', (string) $accounts[0]->access_token, 'Tokens are stored encrypted.');
        Http::assertSent(fn ($request) => str_contains($request->url(), 'PAGE9/subscribed_apps') && $request['subscribed_fields'] === 'feed,messages,message_echoes');

        Http::fake(['graph.facebook.com/*' => Http::response(['success' => true])]);
        $this->post(route('social.disconnect', [$this->company->slug, $accounts[0]->id]))->assertSessionHas('success');
        $this->assertSame(['disconnected', 'disconnected'], DB::table('social_accounts')->pluck('status')->all());
        $this->assertNull(DB::table('social_accounts')->value('access_token'));
    }

    public function test_a_social_conversation_becomes_a_prefilled_sales_enquiry(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['name' => 'Amina Otieno'])]);
        $this->webhook($this->messenger('m1', 'Hi, we are 4 guests looking for a stay'))->assertOk();
        $thread = $this->thread('message', 'GUEST1');

        $this->get(route('sales.create', ['tenant' => $this->company->slug, 'name' => 'Amina Otieno', 'message' => 'Hi, we are 4 guests looking for a stay',
            'source' => 'facebook', 'social_thread' => $thread->id]))->assertOk()
            ->assertSee('value="Amina Otieno"', false)->assertSee('name="social_thread_id" value="'.$thread->id.'"', false)
            ->assertSee('Guest&#039;s Facebook message:', false)->assertSee('value="facebook" selected', false);
    }
}
