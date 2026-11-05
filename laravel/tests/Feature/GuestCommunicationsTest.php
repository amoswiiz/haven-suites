<?php

namespace Tests\Feature;

use App\Http\Middleware\ResolveTenant;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class GuestCommunicationsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $company;

    private User $manager;

    private function url(string $path): string
    {
        return '/t/'.$this->company->slug.'/communications/'.$path;
    }

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config([
            'database.connections.tenant' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true],
            'services.roycetalk.manual_live' => true,
            'services.roycetalk.api_key' => 'test-key',
            'services.roycetalk.sender_id' => 'RoyceLTD',
        ]);
        DB::purge('tenant');
        $this->company = Tenant::create(['name' => 'Haven Test', 'slug' => 'guest-comms', 'database_name' => ':memory:', 'active' => true]);
        $this->manager = User::factory()->create(['active' => true]);
        $this->manager->tenants()->attach($this->company, ['roles' => json_encode(['admin']), 'legacy_user_id' => 1]);
        app()->instance('currentTenant', $this->company);
        $this->withoutMiddleware(ResolveTenant::class)->actingAs($this->manager);

        $schema = DB::connection('tenant')->getSchemaBuilder();
        $schema->create('properties', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
        $schema->create('units', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('property_id');
            $table->string('unit_number');
        });
        $schema->create('reservations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('unit_id');
            $table->string('guest_name');
            $table->string('guest_phone');
            $table->date('check_in');
            $table->date('check_out');
        });
        DB::connection('tenant')->table('properties')->insert(['id' => 1, 'name' => 'Beach House']);
        DB::connection('tenant')->table('units')->insert([
            ['id' => 1, 'property_id' => 1, 'unit_number' => 'A1'],
            ['id' => 2, 'property_id' => 1, 'unit_number' => 'B2'],
        ]);
    }

    protected function tearDown(): void
    {
        DB::purge('tenant');
        parent::tearDown();
    }

    private function booking(): int
    {
        return DB::connection('tenant')->table('reservations')->insertGetId([
            'unit_id' => 1,
            'guest_name' => 'Amina Guest',
            'guest_phone' => '0720602805',
            'check_in' => '2035-01-10',
            'check_out' => '2035-01-12',
        ]);
    }

    private function template(array $overrides = []): int
    {
        return DB::table('guest_message_templates')->insertGetId(array_replace([
            'tenant_id' => $this->company->id,
            'unit_id' => null,
            'name' => 'Check-in',
            'purpose' => 'check_in',
            'channel' => 'sms',
            'body' => 'Hi {{guest_name}}, welcome to {{apartment}} on {{check_in}}.',
            'active' => true,
            'version' => 1,
            'created_by' => $this->manager->id,
            'updated_by' => $this->manager->id,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    public function test_templates_are_editable_company_scoped_and_validate_apartments_and_placeholders(): void
    {
        $this->get($this->url('templates'))->assertOk()->assertSee('Ask for a review');
        $this->get($this->url('templates/new?purpose=check_in'))->assertOk()->assertSee('{{guest_name}}')->assertSee('All apartments / company-wide');
        $data = [
            'name' => 'Beach check-in', 'purpose' => 'check_in', 'unit_id' => 2,
            'body' => 'Welcome {{guest_name}} to {{apartment}}. Quiet hours begin at 10 PM.', 'active' => 1,
        ];
        $this->post($this->url('templates'), $data)->assertRedirect($this->url('templates'))->assertSessionHasNoErrors();
        $id = DB::table('guest_message_templates')->value('id');
        $this->assertDatabaseHas('guest_message_templates', ['id' => $id, 'tenant_id' => $this->company->id, 'unit_id' => 2, 'version' => 1]);
        $this->get($this->url('templates/'.$id.'/edit'))->assertOk()->assertSee('Quiet hours begin at 10 PM');

        $this->post($this->url('templates/'.$id), [...$data, 'version' => 1, 'active' => 0])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('guest_message_templates', ['id' => $id, 'active' => 0, 'version' => 2]);
        $this->post($this->url('templates/'.$id), [...$data, 'version' => 1])->assertSessionHasErrors('template');
        $this->post($this->url('templates'), [...$data, 'unit_id' => 999])->assertSessionHasErrors('unit_id');
        $this->post($this->url('templates'), [...$data, 'body' => 'Hello {{not_a_field}}'])->assertSessionHasErrors('body');
        $this->assertSame(1, DB::table('guest_message_templates')->count());

        $other = Tenant::create(['name' => 'Other Company', 'slug' => 'other-comms', 'database_name' => 'unused_other_comms', 'active' => true]);
        $foreignId = $this->template(['tenant_id' => $other->id]);
        $this->get($this->url('templates/'.$foreignId.'/edit'))->assertNotFound();
        $this->post($this->url('templates/'.$foreignId), [...$data, 'version' => 1])->assertNotFound();
    }

    public function test_booking_preview_renders_selected_template_and_does_not_send(): void
    {
        $bookingId = $this->booking();
        $templateId = $this->template(['unit_id' => 1, 'body' => 'Hello {{guest_name}}, {{apartment}} at {{property_name}} is ready on {{check_in}}.']);
        $this->get($this->url('guests'))->assertOk()->assertSee('Amina Guest')->assertSee('Check-in');
        $this->post($this->url('guests/preview'), [
            'reservation_id' => $bookingId, 'template_id' => $templateId, 'phone' => '',
        ])->assertOk()->assertSee('To: +254720602805')->assertSee('Hello Amina Guest, A1 at Beach House is ready on 2035-01-10.')
            ->assertSee('Send SMS now');
        $this->assertDatabaseCount('manual_sms_messages', 0);
        Http::assertNothingSent();
    }

    public function test_preview_rejects_wrong_apartment_archived_or_foreign_template_and_missing_context(): void
    {
        $bookingId = $this->booking();
        $templateId = $this->template(['unit_id' => 2]);
        $this->post($this->url('guests/preview'), ['reservation_id' => $bookingId, 'template_id' => $templateId])
            ->assertSessionHasErrors('template_id');
        $this->post($this->url('guests/preview'), ['reservation_id' => $bookingId, 'unit_id' => 2, 'body' => 'Hello'])
            ->assertSessionHasErrors('unit_id');
        DB::table('guest_message_templates')->where('id', $templateId)->update(['active' => false]);
        $this->post($this->url('guests/preview'), ['reservation_id' => $bookingId, 'template_id' => $templateId])->assertNotFound();
        $foreign = Tenant::create(['name' => 'Foreign', 'slug' => 'foreign-comms', 'database_name' => 'unused_foreign_comms', 'active' => true]);
        $foreignId = $this->template(['tenant_id' => $foreign->id]);
        $this->post($this->url('guests/preview'), ['reservation_id' => $bookingId, 'template_id' => $foreignId])->assertNotFound();
        $this->post($this->url('guests/preview'), ['phone' => '+254720602805', 'body' => 'Hi {{guest_name}}'])
            ->assertSessionHasErrors('body');
        $this->post($this->url('guests/preview'), ['phone' => 'bad', 'body' => 'Hello'])
            ->assertSessionHasErrors('phone');
        Http::assertNothingSent();
    }

    public function test_custom_preview_can_send_once_using_existing_sms_history(): void
    {
        $body = 'Welcome to Haven Test. We look forward to your stay.';
        $this->post($this->url('guests/preview'), ['phone' => '0720602805', 'body' => $body])
            ->assertOk()->assertSee('To: +254720602805')->assertSee($body);
        Http::assertNothingSent();

        Http::fake(['https://roycetalk.com/api/v1/sms-api/send/' => Http::response(['success' => true, 'data' => ['message_id' => 'guest-message-1']])]);
        $payload = ['phone' => '+254720602805', 'body' => $body, 'send_token' => (string) Str::uuid()];
        $this->post($this->url('sms'), $payload)->assertSessionHasNoErrors();
        $this->post($this->url('sms'), $payload)->assertRedirect();
        Http::assertSentCount(1);
        $this->assertDatabaseHas('manual_sms_messages', ['tenant_id' => $this->company->id, 'phone' => $payload['phone'], 'body' => $body, 'status' => 'submitted']);
    }

    public function test_preview_escapes_guest_supplied_text_in_the_html(): void
    {
        $bookingId = $this->booking();
        DB::connection('tenant')->table('reservations')->where('id', $bookingId)
            ->update(['guest_name' => '<script>alert(1)</script>']);

        $this->post($this->url('guests/preview'), [
            'reservation_id' => $bookingId, 'body' => 'Hello {{guest_name}}',
        ])->assertOk()->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false);

        Http::assertNothingSent();
    }

    public function test_non_members_and_disabled_messaging_cannot_access_templates_or_preview(): void
    {
        $owner = User::factory()->create(['active' => true]);
        $owner->tenants()->attach($this->company, ['roles' => json_encode(['owner']), 'legacy_user_id' => 2]);
        $this->actingAs($owner)->get($this->url('templates'))->assertForbidden();
        $this->post($this->url('guests/preview'), ['phone' => '+254720602805', 'body' => 'Hello'])->assertForbidden();
        $outsider = User::factory()->create(['active' => true]);
        $this->actingAs($outsider)->get($this->url('templates'))->assertForbidden();

        $this->company->modules = ['messaging' => false];
        $this->company->save();
        $this->actingAs($this->manager)->get($this->url('templates'))->assertForbidden();
        $this->post($this->url('guests/preview'), ['phone' => '+254720602805', 'body' => 'Hello'])->assertForbidden();
        Http::assertNothingSent();
    }
}
