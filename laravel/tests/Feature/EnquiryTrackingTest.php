<?php

namespace Tests\Feature;

use App\Http\Middleware\ResolveTenant;
use App\Models\Tenant;
use App\Models\User;
use App\Services\EnquiryTracking;
use App\Services\PublicListingCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EnquiryTrackingTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $company;

    private User $salesperson;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Tenant::create(['name' => 'Haven Suites', 'slug' => 'tracking', 'database_name' => 'unused_tracking', 'active' => true]);
        $this->salesperson = User::factory()->create(['active' => true, 'name' => 'Private Salesperson']);
        $this->salesperson->tenants()->attach($this->company, ['roles' => json_encode(['sales']), 'legacy_user_id' => 22]);
    }

    private function lead(array $overrides = []): object
    {
        $id = DB::table('sales_leads')->insertGetId(array_replace([
            'tenant_id' => $this->company->id, 'name' => 'Amina Wanjiru', 'phone' => '+254712345678', 'phone_normalized' => '254712345678',
            'source' => 'phone', 'channel' => 'phone', 'assigned_to' => $this->salesperson->id, 'check_in' => '2026-12-12', 'check_out' => '2026-12-15',
            'guests' => 3, 'location' => 'Nyali', 'unit_preference' => 'Amalia, Links Road', 'budget' => '45000.00', 'stage' => 'new',
            'next_action' => 'Call the guest', 'notes' => 'PRIVATE STAFF NOTE', 'created_at' => now(), 'updated_at' => now(),
        ], $overrides));

        return DB::table('sales_leads')->find($id);
    }

    private function trackingPath(object $lead): string
    {
        return parse_url(app(EnquiryTracking::class)->issue($this->company, $lead)['url'], PHP_URL_PATH);
    }

    public function test_each_pipeline_stage_is_shown_to_the_guest_in_plain_language(): void
    {
        $lead = $this->lead();
        $path = $this->trackingPath($lead);
        $this->assertMatchesRegularExpression('#^/e/[A-Za-z0-9_-]{12}$#', $path);

        foreach (EnquiryTracking::STATUSES as $stage => $status) {
            DB::table('sales_leads')->where('id', $lead->id)->update(['stage' => $stage]);
            $response = $this->get($path)->assertOk()->assertSee($status['label'])->assertSee($status['message']);
            if ($stage === 'lost') {
                $response->assertDontSee('aria-label="Enquiry progress"', false);
            } else {
                $response->assertSee('aria-label="Enquiry progress"', false)->assertSee('aria-current="step"', false);
            }
        }
    }

    public function test_tracking_page_shows_the_stay_but_never_private_pipeline_details(): void
    {
        $lead = $this->lead();
        $reference = app(EnquiryTracking::class)->issue($this->company, $lead)['reference'];

        $this->get($this->trackingPath($lead))->assertOk()
            ->assertSee('Hi Amina')->assertSee($reference)->assertSee('Amalia, Links Road')->assertSee('Sat 12 Dec 2026')->assertSee('3')
            ->assertDontSee('PRIVATE STAFF NOTE')->assertDontSee('Private Salesperson')->assertDontSee('Call the guest')
            ->assertDontSee('45,000')->assertDontSee('+254712345678')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')->assertHeader('Referrer-Policy', 'no-referrer');
    }

    public function test_issuing_twice_keeps_the_same_reference_and_link(): void
    {
        $lead = $this->lead();
        $first = app(EnquiryTracking::class)->issue($this->company, $lead);
        $second = app(EnquiryTracking::class)->issue($this->company, $lead);

        $this->assertSame($first, $second);
        $this->assertDatabaseCount('enquiry_tracking_links', 1);
    }

    public function test_unknown_expired_and_inactive_company_links_are_not_found(): void
    {
        $this->get('/e/AAAAAAAAAAAA')->assertNotFound();

        $lead = $this->lead();
        $path = $this->trackingPath($lead);
        $this->get($path)->assertOk();

        DB::table('enquiry_tracking_links')->where('lead_id', $lead->id)->update(['expires_at' => now()->subMinute()]);
        $this->get($path)->assertNotFound();

        DB::table('enquiry_tracking_links')->where('lead_id', $lead->id)->update(['expires_at' => now()->addDay()]);
        $this->company->update(['active' => false]);
        $this->get($path)->assertNotFound();
    }

    public function test_links_last_until_well_after_a_late_check_out(): void
    {
        $lead = $this->lead(['check_in' => now()->addMonths(9)->toDateString(), 'check_out' => now()->addMonths(9)->addDays(3)->toDateString()]);
        app(EnquiryTracking::class)->issue($this->company, $lead);

        $expiresAt = DB::table('enquiry_tracking_links')->where('lead_id', $lead->id)->value('expires_at');
        $this->assertTrue(now()->parse($expiresAt)->greaterThan(now()->addMonths(9)->addDays(30)));
    }

    public function test_references_use_prefix_month_and_a_running_number_that_restarts_monthly(): void
    {
        $this->travelTo(now('Africa/Nairobi')->setDate(2026, 11, 30)->setTime(10, 0));
        $tracking = app(EnquiryTracking::class);
        $this->assertSame('HS-2611-001', $tracking->issue($this->company, $this->lead())['reference']);
        $this->assertSame('HS-2611-002', $tracking->issue($this->company, $this->lead())['reference']);

        $this->travelTo(now('Africa/Nairobi')->setDate(2026, 12, 1)->setTime(9, 0));
        $this->assertSame('HS-2612-001', $tracking->issue($this->company, $this->lead())['reference']);

        $this->company->update(['reference_prefix' => 'HAVEN']);
        $this->assertSame('HAVEN-2612-002', $tracking->issue($this->company->fresh(), $this->lead())['reference']);
    }

    public function test_numbers_are_per_company_and_prefixes_may_coincide(): void
    {
        $other = Tenant::create(['name' => 'Highland Stays', 'slug' => 'highland', 'database_name' => 'unused_highland', 'active' => true]);
        $tracking = app(EnquiryTracking::class);

        $this->assertSame('HS', $tracking->prefix($other));
        $this->assertSame($tracking->newReference($this->company), $tracking->newReference($other));
        $this->assertSame('ENQ', $tracking->prefix(new Tenant(['name' => '!!!'])));
        $this->assertSame('MA', $tracking->prefix(new Tenant(['name' => 'Malaika'])));
    }

    public function test_company_administrators_can_set_the_reference_prefix(): void
    {
        $admin = User::factory()->create(['active' => true]);
        $admin->tenants()->attach($this->company, ['roles' => json_encode(['admin']), 'legacy_user_id' => 30]);
        app()->instance('currentTenant', $this->company);
        $this->withoutMiddleware(ResolveTenant::class)->actingAs($admin);
        $base = ['name' => 'Haven Suites'];

        $this->post('/t/tracking/company/settings', [...$base, 'reference_prefix' => 'h1'])->assertSessionHasErrors('reference_prefix');
        $this->post('/t/tracking/company/settings', [...$base, 'reference_prefix' => ' hvn '])->assertSessionHasNoErrors();
        $this->assertSame('HVN', $this->company->fresh()->reference_prefix);
        $this->post('/t/tracking/company/settings', [...$base, 'reference_prefix' => ''])->assertSessionHasNoErrors();
        $this->assertNull($this->company->fresh()->reference_prefix);
    }

    private function enableSite(): void
    {
        DB::table('public_sites')->insert(['tenant_id' => $this->company->id, 'enabled' => true, 'response_hours' => 24, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_guests_can_find_their_enquiry_with_the_reference_and_last_four_phone_digits(): void
    {
        $this->enableSite();
        $lead = $this->lead();
        $tracking = app(EnquiryTracking::class)->issue($this->company, $lead);

        $this->get('/stays/tracking/track')->assertOk()->assertSee('Track your enquiry')->assertSee('name="phone_last_digits"', false);
        $this->from('/stays/tracking/track')->post('/stays/tracking/track', ['reference' => ' '.strtolower(str_replace('-', ' ', $tracking['reference'])).' ', 'phone_last_digits' => '5678'])
            ->assertRedirect($tracking['url']);
    }

    public function test_wrong_details_get_one_generic_message_and_repeated_failures_lock_the_reference(): void
    {
        $this->enableSite();
        $lead = $this->lead();
        $reference = app(EnquiryTracking::class)->issue($this->company, $lead)['reference'];
        $message = 'We could not find an enquiry with those details.';

        $this->from('/stays/tracking/track')->post('/stays/tracking/track', ['reference' => 'HS-2611-999', 'phone_last_digits' => '5678'])
            ->assertRedirect('/stays/tracking/track')->assertSessionHasErrors('reference');
        $this->assertStringContainsString($message, session('errors')->first('reference'));
        for ($attempt = 0; $attempt < EnquiryTracking::LOOKUP_ATTEMPTS; $attempt++) {
            $this->assertNull(app(EnquiryTracking::class)->lookup($this->company, $reference, '0000'));
        }
        $this->assertNull(app(EnquiryTracking::class)->lookup($this->company, $reference, '5678'), 'Correct digits must not work while the reference is locked.');
    }

    public function test_older_website_enquiries_without_a_tracking_link_can_still_be_looked_up(): void
    {
        $lead = $this->lead();
        DB::table('public_enquiry_receipts')->insert(['tenant_id' => $this->company->id, 'lead_id' => $lead->id, 'token_hash' => str_repeat('a', 64),
            'reference' => 'ENQ-PT4AMP', 'created_at' => now(), 'updated_at' => now()]);

        $url = app(EnquiryTracking::class)->lookup($this->company, 'enq-pt4amp', '5678');

        $this->assertNotNull($url);
        $this->assertSame('ENQ-PT4AMP', app(EnquiryTracking::class)->forLead($this->company, (int) $lead->id)['reference']);
    }

    public function test_track_page_needs_an_enabled_public_site_and_cannot_be_used_as_a_listing_address(): void
    {
        $this->get('/stays/tracking/track')->assertNotFound();
        $this->assertContains('track', PublicListingCatalog::RESERVED_SLUGS);
    }
}
