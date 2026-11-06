<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Services\EnquiryAcknowledgements;
use App\Services\PublicEnquiries;
use App\Services\PublicListingCatalog;
use App\Services\UnitAvailability;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Database\RecordNotFoundException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

class PublicEnquiriesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $company;

    private User $salesperson;

    private Connection $inventory;

    private string $inventoryFile;

    private int $listingId;

    private int $providerStatus = 200;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-06 10:00:00', 'Africa/Nairobi'));
        Http::preventStrayRequests();
        Http::fake(['https://roycetalk.com/api/v1/sms-api/send/' => fn () => $this->providerStatus >= 400
            ? Http::response([], $this->providerStatus) : Http::response(['success' => true, 'data' => ['message_id' => 'public-enquiry-123']])]);
        config(['services.roycetalk.manual_live' => true, 'services.roycetalk.api_key' => 'test-key', 'services.roycetalk.sender_id' => 'HavenTest']);
        $this->inventoryFile = tempnam(sys_get_temp_dir(), 'haven-public-enquiry-');
        $connection = ['driver' => 'sqlite', 'database' => $this->inventoryFile, 'prefix' => '', 'foreign_key_constraints' => true];
        config(['database.connections.tenant' => $connection]);
        $this->inventory = DB::build($connection);
        $schema = $this->inventory->getSchemaBuilder();
        $schema->create('properties', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
        $schema->create('units', function (Blueprint $table): void {
            $table->id();
            $table->integer('property_id');
            $table->string('unit_number');
            $table->integer('bedrooms');
            $table->integer('bathrooms');
            $table->decimal('nightly_rate', 12, 2);
            $table->string('status');
            $table->string('owner_phone')->nullable();
        });
        $schema->create('reservations', function (Blueprint $table): void {
            $table->id();
            $table->integer('unit_id');
            $table->string('guest_name')->nullable();
            $table->date('check_in');
            $table->date('check_out');
            $table->string('status');
        });
        $schema->create('partner_apartments', function (Blueprint $table): void {
            $table->id();
            $table->string('apartment_name');
            $table->string('status');
        });
        $this->inventory->table('properties')->insert(['id' => 1, 'name' => 'Coral Gables']);
        $this->inventory->table('units')->insert(['id' => 1, 'property_id' => 1, 'unit_number' => 'F5', 'bedrooms' => 2, 'bathrooms' => 2, 'nightly_rate' => 10000, 'status' => 'active', 'owner_phone' => 'PRIVATE-OWNER']);
        $this->company = Tenant::create(['name' => 'Haven Suites', 'slug' => 'public-enquiry', 'database_name' => $this->inventoryFile, 'active' => true]);
        $this->salesperson = User::factory()->create(['active' => true]);
        $this->salesperson->tenants()->attach($this->company, ['roles' => json_encode(['sales']), 'legacy_user_id' => 1]);
        DB::table('public_sites')->insert(['tenant_id' => $this->company->id, 'enabled' => true, 'enquiry_assignee_id' => $this->salesperson->id,
            'headline' => 'Find your Haven', 'contact_phone' => '+254702898694', 'response_hours' => 24, 'created_at' => now(), 'updated_at' => now()]);
        $this->listingId = DB::table('public_listings')->insertGetId(['tenant_id' => $this->company->id, 'unit_id' => 1, 'slug' => 'coral-gables-f5',
            'title' => 'Coral Gables apartment', 'description' => 'A welcoming apartment near the coast.', 'city' => 'Mombasa', 'max_guests' => 4, 'min_nights' => 2,
            'status' => 'published', 'created_at' => now(), 'updated_at' => now()]);
        Storage::fake('local');
        $key = 'listing-photos/'.$this->company->id.'/'.$this->listingId.'/'.str_repeat('a', 48).'.jpg';
        Storage::disk('local')->put($key, 'test-photo');
        DB::table('public_listing_photos')->insert(['tenant_id' => $this->company->id, 'listing_id' => $this->listingId,
            'storage_key' => $key, 'mime_type' => 'image/jpeg', 'caption' => 'Coastal living room', 'created_at' => now(), 'updated_at' => now()]);
    }

    protected function tearDown(): void
    {
        $this->inventory->disconnect();
        DB::purge('tenant');
        if (is_file($this->inventoryFile)) {
            unlink($this->inventoryFile);
        }
        parent::tearDown();
    }

    private function url(): string
    {
        return '/stays/'.$this->company->slug.'/coral-gables-f5/enquire';
    }

    private function payload(array $overrides = []): array
    {
        $this->withSession(['public_enquiry_test' => true]);
        $session = app('session')->driver();
        $request = Request::create('/');
        $request->setLaravelSession($session);
        $this->withCookie($session->getName(), $session->getId());
        $listing = app(PublicListingCatalog::class)->findPublished($this->company, 'coral-gables-f5');
        $context = app(PublicEnquiries::class)->formContext($this->company, $listing, $request);
        $this->travel(3)->seconds();

        return array_replace([
            'full_name' => 'Amina Website Guest', 'phone' => '0712345678', 'email' => 'amina-website@example.test',
            'check_in' => '2026-11-20', 'check_out' => '2026-11-23', 'guests' => 2, 'message' => 'We would love a quiet stay.',
            'consent' => '1', 'website' => '', ...$context,
        ], $overrides);
    }

    public function test_public_guest_enquiry_creates_pipeline_sms_and_follow_up_without_faking_a_staff_actor(): void
    {
        $outsider = User::factory()->create();
        $response = $this->post($this->url(), $this->payload(['tenant_id' => 999, 'unit_id' => 999, 'assigned_to' => $outsider->id, 'stage' => 'won']))
            ->assertSessionHasNoErrors()->assertRedirect();
        $lead = DB::table('sales_leads')->first();
        $this->assertSame('website', $lead->source);
        $this->assertSame('website', $lead->public_origin);
        $this->assertSame('new', $lead->stage);
        $this->assertEquals($this->company->id, $lead->tenant_id);
        $this->assertEquals($this->salesperson->id, $lead->assigned_to);
        $this->assertEquals($this->listingId, $lead->public_listing_id);
        $this->assertEquals(1, $lead->public_unit_id);
        $this->assertSame('+254712345678', $lead->phone);
        $this->assertNotNull($lead->public_consented_at);
        $this->assertDatabaseHas('sales_activities', ['lead_id' => $lead->id, 'actor_id' => null, 'type' => 'website_enquiry']);
        $this->assertDatabaseHas('manual_sms_messages', ['enquiry_id' => $lead->id, 'sent_by' => null, 'status' => 'submitted']);
        $this->assertDatabaseHas('task_items', ['id' => $lead->public_task_id, 'created_by' => null, 'assigned_to' => $this->salesperson->id, 'owner_visible' => false]);
        $this->assertDatabaseHas('task_events', ['task_id' => $lead->public_task_id, 'actor_id' => null]);
        $this->assertDatabaseHas('task_notices', ['task_id' => $lead->public_task_id, 'user_id' => $this->salesperson->id]);
        $this->assertSame(0, $this->inventory->table('reservations')->count());
        $this->assertMatchesRegularExpression('#/enquiries/[a-f0-9]{64}$#', $response->headers->get('Location'));
        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request['phone_number'] === '+254712345678' && str_contains($request['text_message'], 'our team is working on it'));
    }

    public function test_replaying_the_same_public_form_does_not_duplicate_the_enquiry_task_or_sms(): void
    {
        $payload = $this->payload();
        $first = $this->post($this->url(), $payload)->assertSessionHasNoErrors()->assertRedirect();
        $second = $this->post($this->url(), $payload)->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame($first->headers->get('Location'), $second->headers->get('Location'));
        $this->assertDatabaseCount('sales_leads', 1);
        $this->assertDatabaseCount('task_items', 1);
        $this->assertDatabaseCount('public_enquiry_receipts', 1);
        $this->assertDatabaseCount('manual_sms_messages', 1);
        app(EnquiryAcknowledgements::class)->sendPublic($this->company, DB::table('sales_leads')->value('id'));
        Http::assertSentCount(1);
    }

    public function test_receipt_is_generic_private_and_does_not_expose_guest_or_pipeline_data(): void
    {
        $response = $this->post($this->url(), $this->payload())->assertSessionHasNoErrors();
        $location = $response->headers->get('Location');
        $receipt = $this->get($location)->assertOk()->assertSee('Enquiry received')->assertSee('ENQ-')
            ->assertDontSee('Amina Website Guest')->assertDontSee('amina-website@example.test')->assertDontSee('+254712345678')
            ->assertDontSee($this->salesperson->name)->assertDontSee('PRIVATE-OWNER');
        $receipt->assertHeader('X-Robots-Tag', 'noindex, nofollow')->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertStringContainsString('no-store', $receipt->headers->get('Cache-Control'));
        $token = basename(parse_url($location, PHP_URL_PATH));
        $this->assertNotSame($token, DB::table('public_enquiry_receipts')->value('token_hash'));
        $this->get('/stays/'.$this->company->slug.'/enquiries/'.str_repeat('0', 64))->assertNotFound();
        $this->get('/stays/unknown-company/enquiries/'.$token)->assertNotFound();
    }

    public function test_tampered_expired_or_honeypot_forms_do_not_create_records_or_sms(): void
    {
        $payload = $this->payload();
        $this->post($this->url(), [...$payload, 'form_signature' => str_repeat('0', 64)])->assertSessionHasErrors('enquiry');
        $this->post($this->url(), [...$payload, 'website' => 'spam.example'])->assertSessionHasErrors('website');
        $this->post($this->url(), [...$payload, 'consent' => null])->assertSessionHasErrors('consent');
        $this->travel(2)->hours();
        $this->post($this->url(), $payload)->assertSessionHasErrors('enquiry');
        $this->assertDatabaseCount('sales_leads', 0);
        Http::assertNothingSent();
    }

    public function test_signature_is_bound_to_the_browser_session_and_minimum_form_time(): void
    {
        $payload = $this->payload();
        $this->travel(-2)->seconds();
        $this->post($this->url(), $payload)->assertSessionHasErrors('enquiry');
        $this->travel(3)->seconds();
        $session = app('session')->driver();
        $session->regenerate();
        $this->withCookie($session->getName(), $session->getId());
        $this->post($this->url(), $payload)->assertSessionHasErrors('enquiry');
        $this->assertDatabaseCount('sales_leads', 0);
        Http::assertNothingSent();
    }

    public function test_closed_dates_require_flexible_dates_and_do_not_reserve_inventory(): void
    {
        $this->inventory->table('reservations')->insert(['unit_id' => 1, 'check_in' => '2026-11-20', 'check_out' => '2026-11-22', 'status' => 'confirmed']);
        $payload = $this->payload();
        $this->post($this->url(), $payload)->assertSessionHasErrors('check_in');
        $this->post($this->url(), [...$payload, 'flexible_dates' => '1'])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertStringContainsString('currently unavailable', DB::table('sales_leads')->value('notes'));
        $this->assertSame(1, $this->inventory->table('reservations')->count());
    }

    public function test_capacity_minimum_stay_and_phone_validation_prevent_invalid_capture(): void
    {
        $payload = $this->payload();
        $this->post($this->url(), [...$payload, 'guests' => 5])->assertSessionHasErrors('guests');
        $this->post($this->url(), [...$payload, 'check_out' => '2026-11-21'])->assertSessionHasErrors('check_out');
        $this->post($this->url(), [...$payload, 'check_in' => '2026-11-01'])->assertSessionHasErrors('check_out');
        $this->post($this->url(), [...$payload, 'phone' => 'please-call-me'])->assertSessionHasErrors('phone');
        $this->assertDatabaseCount('sales_leads', 0);
        Http::assertNothingSent();
    }

    public function test_inactive_site_listing_unit_or_assignee_cannot_accept_public_enquiries(): void
    {
        $payload = $this->payload();
        DB::table('public_sites')->where('tenant_id', $this->company->id)->update(['enabled' => false]);
        $this->post($this->url(), $payload)->assertNotFound();
        DB::table('public_sites')->where('tenant_id', $this->company->id)->update(['enabled' => true]);
        DB::table('public_listings')->where('id', $this->listingId)->update(['status' => 'paused']);
        $this->post($this->url(), $payload)->assertNotFound();
        DB::table('public_listings')->where('id', $this->listingId)->update(['status' => 'published']);
        $this->inventory->table('units')->where('id', 1)->update(['status' => 'inactive']);
        $this->post($this->url(), $payload)->assertNotFound();
        $this->inventory->table('units')->where('id', 1)->update(['status' => 'active']);
        $this->salesperson->forceFill(['active' => false])->save();
        $this->post($this->url(), $payload)->assertSessionHasErrors('enquiry');
        $this->assertDatabaseCount('sales_leads', 0);
        Http::assertNothingSent();
    }

    public function test_sms_failure_keeps_the_enquiry_and_never_resends_an_uncertain_attempt(): void
    {
        $this->providerStatus = 500;
        $payload = $this->payload();
        $this->post($this->url(), $payload)->assertSessionHasNoErrors()->assertRedirect();
        $this->post($this->url(), $payload)->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseHas('manual_sms_messages', ['status' => 'unknown', 'sent_by' => null]);
        $this->assertDatabaseCount('sales_leads', 1);
        $this->assertDatabaseCount('task_items', 1);
        Http::assertSentCount(1);
    }

    public function test_multiple_enquiries_for_one_phone_only_send_one_acknowledgement_per_ten_minutes(): void
    {
        $this->post($this->url(), $this->payload())->assertSessionHasNoErrors();
        $this->post($this->url(), $this->payload(['message' => 'We can also stay the following week.']))->assertSessionHasNoErrors();
        $this->assertDatabaseCount('sales_leads', 2);
        $this->assertDatabaseHas('manual_sms_messages', ['status' => 'skipped', 'sent_by' => null]);
        Http::assertSentCount(1);
    }

    public function test_phone_rate_limit_caps_public_capture_without_losing_existing_enquiries(): void
    {
        for ($index = 0; $index < 5; $index++) {
            $this->post($this->url(), $this->payload(['message' => 'Enquiry '.$index]))->assertSessionHasNoErrors();
        }
        $this->post($this->url(), $this->payload())->assertSessionHasErrors('enquiry');
        $this->assertDatabaseCount('sales_leads', 5);
        Http::assertSentCount(1);
    }

    public function test_disabled_sms_or_tasks_preserves_pipeline_and_audits_the_public_origin(): void
    {
        config(['services.roycetalk.manual_live' => false]);
        $this->company->forceFill(['modules' => ['tasks' => false]])->save();
        $this->post($this->url(), $this->payload())->assertSessionHasNoErrors();
        $this->assertDatabaseCount('sales_leads', 1);
        $this->assertDatabaseCount('task_items', 0);
        $this->assertDatabaseHas('manual_sms_messages', ['status' => 'skipped', 'sent_by' => null]);
        Http::assertNothingSent();
    }

    public function test_receipt_write_failure_rolls_back_lead_audit_task_and_sms_intent(): void
    {
        DB::statement("CREATE TRIGGER fail_public_receipt BEFORE INSERT ON public_enquiry_receipts BEGIN SELECT RAISE(ABORT, 'Receipt unavailable'); END");
        $this->withoutExceptionHandling();
        try {
            $this->post($this->url(), $this->payload());
            $this->fail('Receipt failure must roll back the public enquiry.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('Receipt unavailable', $exception->getMessage());
            $this->assertDatabaseCount('sales_leads', 0);
            $this->assertDatabaseCount('sales_activities', 0);
            $this->assertDatabaseCount('task_items', 0);
            $this->assertDatabaseCount('manual_sms_messages', 0);
            Http::assertNothingSent();
        }
    }

    public function test_public_sms_api_refuses_a_staff_origin_lead(): void
    {
        $id = DB::table('sales_leads')->insertGetId(['tenant_id' => $this->company->id, 'name' => 'Staff lead', 'phone' => '+254700000001',
            'source' => 'phone', 'channel' => 'phone', 'assigned_to' => $this->salesperson->id, 'next_action' => 'Call guest', 'created_at' => now(), 'updated_at' => now()]);
        try {
            app(EnquiryAcknowledgements::class)->preparePublic($this->company, $id);
            $this->fail('The anonymous SMS path must not accept staff-origin leads.');
        } catch (RecordNotFoundException|NotFoundHttpException $exception) {
            $this->assertDatabaseCount('manual_sms_messages', 0);
            Http::assertNothingSent();
        }
    }

    public function test_listing_rule_changes_during_capture_require_a_fresh_form(): void
    {
        $real = new UnitAvailability;
        $availability = $this->mock(UnitAvailability::class);
        $availability->shouldReceive('calendar')->once()->andReturnUsing(function ($tenant, $unitId, $from, $to) use ($real): array {
            $result = $real->calendar($tenant, $unitId, $from, $to);
            DB::table('public_listings')->where('id', $this->listingId)->update(['max_guests' => 1]);

            return $result;
        });
        $this->post($this->url(), $this->payload())->assertSessionHasErrors('enquiry');
        $this->assertDatabaseCount('sales_leads', 0);
        Http::assertNothingSent();
    }

    public function test_unit_deactivated_during_capture_cannot_accept_the_enquiry(): void
    {
        $real = new UnitAvailability;
        $calls = 0;
        $availability = $this->mock(UnitAvailability::class);
        $availability->shouldReceive('calendar')->twice()->andReturnUsing(function ($tenant, $unitId, $from, $to) use ($real, &$calls): array {
            $result = $real->calendar($tenant, $unitId, $from, $to);
            if (++$calls === 1) {
                $this->inventory->table('units')->where('id', 1)->update(['status' => 'inactive']);
            }

            return $result;
        });
        $this->post($this->url(), $this->payload())->assertNotFound();
        $this->assertDatabaseCount('sales_leads', 0);
        Http::assertNothingSent();
    }

    public function test_staff_can_follow_the_public_listing_and_truthfully_attributed_follow_up_task(): void
    {
        $this->post($this->url(), $this->payload())->assertSessionHasNoErrors();
        $lead = DB::table('sales_leads')->first();
        $this->actingAs($this->salesperson)->get('/t/'.$this->company->slug.'/sales/'.$lead->id)
            ->assertOk()->assertSee('Website enquiry')->assertSee('Requested apartment: Coral Gables / F5')
            ->assertSee('View public listing')->assertSee('/stays/'.$this->company->slug.'/coral-gables-f5', false)
            ->assertSee('View follow-up task');
        $this->get('/t/'.$this->company->slug.'/tasks/'.$lead->public_task_id)->assertOk()
            ->assertSee('System: Automatically created from a website enquiry.');
        DB::table('public_listings')->where('id', $this->listingId)->update(['status' => 'paused']);
        $this->get('/t/'.$this->company->slug.'/sales/'.$lead->id)->assertOk()->assertDontSee('View public listing')
            ->assertSee('The listing is not currently published.');
    }

    public function test_booking_handoff_preselects_only_the_active_requested_unit_and_preserves_guest_dates(): void
    {
        $this->post($this->url(), $this->payload())->assertSessionHasNoErrors();
        $lead = DB::table('sales_leads')->first();
        $this->salesperson->tenants()->updateExistingPivot($this->company->id, ['roles' => json_encode(['manager'])]);
        $this->company->forceFill(['modules' => ['finance' => false]])->save();
        $url = '/t/'.$this->company->slug.'/reservations/create?lead_id='.$lead->id;
        $response = $this->actingAs($this->salesperson)->get($url)->assertOk()
            ->assertSee('Amina Website Guest')->assertSee('2026-11-20')->assertSee('2026-11-23')
            ->assertSee('requested apartment is preselected');
        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $nodes = (new \DOMXPath($document))->query('//select[@id="unit_id"]/option[@selected]');
        $this->assertSame(1, $nodes->length);
        $this->assertSame('1', $nodes->item(0)->getAttribute('value'));

        $this->inventory->table('units')->where('id', 1)->update(['status' => 'inactive']);
        $response = $this->get($url)->assertOk();
        @$document->loadHTML($response->getContent());
        $nodes = (new \DOMXPath($document))->query('//select[@id="unit_id"]/option[@selected]');
        $this->assertSame(0, $nodes->length);
    }
}
