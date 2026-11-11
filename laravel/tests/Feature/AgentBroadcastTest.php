<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Services\AgentBroadcasts;
use App\Services\PublicListingCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AgentBroadcastTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    /** @var array<string, int> */
    private array $agents = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['app.url' => 'http://localhost', 'services.roycetalk.api_key' => 'test-key', 'services.roycetalk.sender_id' => 'HAVEN', 'services.roycetalk.manual_live' => true]);
        $this->tenant = Tenant::create(['name' => 'Haven Suites', 'slug' => 'broadcast-test', 'database_name' => 'not_connected_broadcast', 'active' => true]);
        $this->user = User::factory()->create(['name' => 'Amos Mwaura']);
        $this->user->tenants()->attach($this->tenant, ['roles' => json_encode(['manager']), 'legacy_user_id' => 1]);
        foreach ([
            'grace' => ['name' => 'Grace Achieng', 'phone' => '0712 345 678'],
            'wa' => ['name' => 'Peter Otieno', 'phone' => '020 222 3333', 'whatsapp' => '+254733111222'],
            'optout' => ['name' => 'Opted Out', 'phone' => '0722 000 111', 'broadcast_opt_out' => true],
            'nonumber' => ['name' => 'No Number', 'phone' => null],
        ] as $key => $agent) {
            $this->agents[$key] = DB::table('sales_agents')->insertGetId($agent + ['tenant_id' => $this->tenant->id, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->mock(PublicListingCatalog::class)->shouldReceive('published')->andReturn(collect([[
            'id' => 7, 'slug' => 'sea-view', 'title' => 'Sea View', 'city' => 'Mombasa', 'neighbourhood' => 'Nyali', 'bedrooms' => 2,
            'property_type' => null, 'layout_label' => '2 bedrooms', 'starting_price' => '12000.00', 'currency' => 'KES',
        ]]));
    }

    private function url(string $path = ''): string
    {
        return '/t/broadcast-test/sales/agents/broadcasts'.$path;
    }

    public function test_message_is_personalised_queued_and_sent_by_sms_with_skips_recorded(): void
    {
        Http::fake(['roycetalk.com/*' => Http::sequence()
            ->push(['success' => true, 'data' => ['message_id' => 'msg-1']])
            ->push(['success' => true, 'data' => ['message_id' => 'msg-2']])]);
        $this->actingAs($this->user)->get('/t/broadcast-test/sales/agents')->assertOk()->assertSee('Message all agents');
        $this->get($this->url())->assertOk()->assertSee('Grace Achieng')->assertSee('Opted out of broadcasts')->assertSee('No mobile number')
            ->assertSee('Sea View')->assertSee('2 bedrooms, Nyali')->assertSee('No messages sent to agents yet.');

        $payload = ['kind' => 'availability', 'message' => "Hi {name}, units available this weekend:\n{listings}\nCall us to book.",
            'agent_ids' => array_values($this->agents), 'listing_ids' => [7]];
        $this->post($this->url(), $payload)->assertSessionHasErrors('confirm');
        $this->post($this->url(), $payload + ['confirm' => 1])->assertSessionHasNoErrors()->assertRedirect();
        $broadcast = DB::table('agent_broadcasts')->first();
        $this->assertMatchesRegularExpression('#^Hi \{name\}, units available this weekend:\n- Sea View \(2 bedrooms, Nyali\) KES 12,000/night: https?://[^/]+/stays/broadcast-test/sea-view\nCall us to book\.$#D', $broadcast->message);
        $this->assertSame(4, (int) $broadcast->recipient_count);
        $statuses = DB::table('agent_broadcast_recipients')->pluck('status', 'agent_id');
        $this->assertSame('pending', $statuses[$this->agents['grace']]);
        $this->assertSame('pending', $statuses[$this->agents['wa']]);
        $this->assertSame('skipped', $statuses[$this->agents['optout']]);
        $this->assertSame('skipped', $statuses[$this->agents['nonumber']]);
        Http::assertNothingSent();

        $this->artisan('haven:deliver-agent-broadcasts')->assertSuccessful();
        Http::assertSent(fn (HttpRequest $request): bool => $request['phone_number'] === '+254712345678' && str_starts_with($request['text_message'], 'Hi Grace, units available') && $request['sender_id'] === 'HAVEN');
        Http::assertSent(fn (HttpRequest $request): bool => $request['phone_number'] === '+254733111222' && str_starts_with($request['text_message'], 'Hi Peter,'));
        Http::assertSentCount(2);
        $this->assertSame(2, DB::table('agent_broadcast_recipients')->where('status', 'submitted')->count());
        $this->artisan('haven:deliver-agent-broadcasts')->assertSuccessful();
        Http::assertSentCount(2);

        $this->get($this->url('/'.$broadcast->id))->assertOk()->assertSee('Grace Achieng')->assertSee('Sent')->assertSee('Agent asked not to receive broadcasts.')->assertSee('Copy message');
        $this->get($this->url())->assertOk()->assertSee('2 sent')->assertSee('2 skipped');
    }

    public function test_sending_is_refused_when_sms_is_off_and_other_companies_cannot_see_broadcasts(): void
    {
        config(['services.roycetalk.manual_live' => false]);
        $this->actingAs($this->user)->get($this->url())->assertOk()->assertSee('SMS sending is not switched on yet.');
        $this->post($this->url(), ['kind' => 'offer', 'message' => 'Hi {name}, 20% off this week.', 'agent_ids' => [$this->agents['grace']], 'confirm' => 1])->assertSessionHasErrors('message');
        $this->assertSame(0, DB::table('agent_broadcasts')->count());

        $otherTenant = Tenant::create(['name' => 'Other', 'slug' => 'other-broadcast', 'database_name' => 'not_connected_other', 'active' => true]);
        $otherId = DB::table('agent_broadcasts')->insertGetId(['tenant_id' => $otherTenant->id, 'kind' => 'offer', 'channel' => 'sms', 'message' => 'Private offer', 'created_at' => now(), 'updated_at' => now()]);
        $this->get($this->url('/'.$otherId))->assertNotFound();
    }

    public function test_sms_segments_follow_gsm_and_unicode_limits(): void
    {
        $this->assertSame(1, AgentBroadcasts::segments(str_repeat('a', 160)));
        $this->assertSame(2, AgentBroadcasts::segments(str_repeat('a', 161)));
        $this->assertSame(1, AgentBroadcasts::segments(str_repeat('a', 158).'€'));
        $this->assertSame(2, AgentBroadcasts::segments(str_repeat('a', 159).'€'));
        $this->assertSame(1, AgentBroadcasts::segments(str_repeat('a', 68).'😊'), 'An emoji uses two Unicode SMS characters.');
        $this->assertSame(2, AgentBroadcasts::segments(str_repeat('a', 69).'😊'));
        $this->assertSame('Hi Grace,', AgentBroadcasts::personalise('Hi {name},', 'Grace Achieng'));
    }
}
