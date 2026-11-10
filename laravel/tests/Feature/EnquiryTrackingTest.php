<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Services\EnquiryTracking;
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

    public function test_references_avoid_look_alike_characters_and_stay_unique(): void
    {
        $references = [];
        for ($index = 0; $index < 25; $index++) {
            $reference = app(EnquiryTracking::class)->issue($this->company, $this->lead())['reference'];
            $this->assertMatchesRegularExpression('/^ENQ-[A-HJKMNP-Z2-9]{6}$/', $reference);
            $references[] = $reference;
        }
        $this->assertCount(25, array_unique($references));
    }
}
