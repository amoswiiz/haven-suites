<?php

namespace Tests\Feature;

use App\Http\Middleware\ResolveTenant;
use App\Models\CompanyInvitation;
use App\Models\Tenant;
use App\Models\User;
use App\Services\InvestorNotifications;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class InvestorPortalTest extends TestCase
{
    use RefreshDatabase;

    private function company(string $slug = 'investor-test'): Tenant
    {
        return Tenant::create(['name' => 'Investor Test', 'slug' => $slug, 'database_name' => $slug === 'investor-test' ? 'haven_suites_laravel13' : 'unused_'.$slug, 'active' => true]);
    }

    private function member(Tenant $tenant, string $role = 'owner'): User
    {
        $user = User::factory()->create();
        $user->tenants()->attach($tenant, ['roles' => json_encode([$role]), 'legacy_user_id' => 1]);

        return $user;
    }

    private function profile(Tenant $tenant, ?User $user = null): object
    {
        $id = DB::table('investors')->insertGetId(['tenant_id' => $tenant->id, 'user_id' => $user?->id, 'name' => $user?->name ?? 'Property investor', 'email' => $user?->email ?? 'investor@example.test', 'phone' => '+254712345678', 'active' => true, 'email_notifications' => true, 'sms_notifications' => true, 'sms_consented_at' => now(), 'phone_verified_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        return DB::table('investors')->where('id', $id)->first();
    }

    public function test_investor_reports_filter_published_flows_and_keep_latest_balances(): void
    {
        $tenant = $this->company();
        $owner = $this->member($tenant);
        $investor = $this->profile($tenant, $owner);
        $assignment = DB::table('investor_assignments')->insertGetId(['tenant_id' => $tenant->id, 'investor_id' => $investor->id, 'unit_id' => 1, 'share_basis_points' => 10000, 'starts_on' => '2026-01-01', 'agreement_reference' => 'REPORT-TEST', 'created_at' => now(), 'updated_at' => now()]);
        foreach ([['2026-01-01', 1, '100.00', '100.00', null], ['2026-02-01', 1, '999.00', '999.00', now()], ['2026-02-01', 2, '-20.00', '80.00', null], ['2025-12-01', 1, '500.00', '500.00', null]] as [$month, $revision, $entitlement, $balance, $superseded]) {
            DB::table('investor_statements')->insert(['tenant_id' => $tenant->id, 'investor_id' => $investor->id, 'assignment_id' => $assignment, 'unit_id' => 1, 'period_month' => $month, 'revision' => $revision, 'snapshot' => json_encode(['property' => '=PRIVATE-FORMULA', 'currency' => $tenant->currency, 'totals' => ['entitlement' => $entitlement, 'closing_balance' => $balance]]), 'source_hash' => str_repeat('a', 64), 'published_by' => $owner->id, 'published_at' => now(), 'superseded_at' => $superseded, 'created_at' => now(), 'updated_at' => now()]);
        }
        $url = '/t/'.$tenant->slug.'/investor-portal/reports';
        foreach (['2026-02-10', '2025-12-10'] as $date) {
            DB::table('investor_payouts')->insert(['tenant_id' => $tenant->id, 'investor_id' => $investor->id, 'assignment_id' => $assignment, 'remittance_id' => null, 'property_label' => 'Payout unit', 'payment_date' => $date, 'amount_minor' => 2500, 'currency' => $tenant->currency, 'reference' => 'REPORT-PAYOUT', 'published_by' => $owner->id, 'created_at' => now(), 'updated_at' => now()]);
        }
        $response = $this->actingAs($owner)->get($url.'?period=yearly&year=2026')->assertOk()->assertSee('Download PDF')->assertSee('Investor reports');
        $this->assertCount(2, $response->viewData('rows'));
        $this->assertSame(2500, $response->viewData('payouts')->sum('amount_minor'));
        $this->assertSame(8000, $response->viewData('totals')['entitlement']);
        $this->assertSame(8000, $response->viewData('balances')[$assignment]['amount']);
        $this->assertSame('2026-02', $response->viewData('balances')[$assignment]['month']);
        $dashboard = $this->get('/t/'.$tenant->slug.'/investor-portal?month=2026-02')->assertOk()->assertSee('Your investment overview')->assertSee('KES -20.00')->assertSee('Haven funds operating expenses');
        $this->assertLessThan(strpos($dashboard->getContent(), 'Your unit bookings'), strpos($dashboard->getContent(), 'Net entitlement'));
        $dashboard->assertDontSee('Email and SMS preferences')->assertDontSee('Reservations &amp; Guests', false);
        foreach (['properties', 'statements', 'payouts', 'agreements', 'settings'] as $section) {
            $page = $this->get('/t/'.$tenant->slug.'/investor-portal/'.$section.'?unit_id=1&month=2026-02')->assertOk();
            $this->assertSame($section, $page->viewData('section'));
            $page->assertDontSee('Your unit bookings');
        }
        $this->get('/t/'.$tenant->slug.'/investor-portal/settings')->assertOk()->assertSee('Email and SMS preferences');
        $this->get('/t/'.$tenant->slug.'/investor-portal?tab=invalid')->assertSessionHasErrors('tab');
        $monthly = $this->get($url.'?month=2026-02')->assertOk();
        $this->assertCount(1, $monthly->viewData('rows'));
        $this->assertSame(-2000, $monthly->viewData('totals')['entitlement']);
        $csv = $this->get($url.'?period=yearly&year=2026&download=csv')->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString("'=PRIVATE-FORMULA", $csv->getContent());
        $this->assertStringNotContainsString('999.00', $csv->getContent());
        $pdf = $this->get($url.'?month=2026-02&download=pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $pdf->getContent());
        $this->get($url.'?unit_id=999999')->assertNotFound();
        $this->get($url.'?month=bad')->assertSessionHasErrors('month');
        $this->get($url.'?year=1900&period=yearly')->assertSessionHasErrors('year');
        $other = $this->member($tenant);
        $this->profile($tenant, $other);
        $this->actingAs($other)->get($url.'?period=yearly&year=2026')->assertOk()->assertDontSee('PRIVATE-FORMULA');
        $this->actingAs($other)->get($url.'?unit_id=1&download=csv')->assertNotFound();
        $tenant->modules = ['investors' => false];
        $tenant->save();
        $this->actingAs($owner)->get($url)->assertForbidden();
    }

    public function test_company_pdf_branding_is_configurable_and_restricted_to_company_admins(): void
    {
        Storage::fake('local');
        $tenant = $this->company();
        $admin = $this->member($tenant, 'admin');
        $logo = UploadedFile::fake()->createWithContent('logo.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aTf8AAAAASUVORK5CYII='));
        $url = '/t/'.$tenant->slug.'/company/settings';
        $this->actingAs($admin)->post($url, ['name' => 'Coastal Investments', 'contact_email' => 'office@example.test', 'report_logo' => $logo, 'report_primary_color' => '#123456', 'report_accent_color' => '#abcdef'])->assertSessionHasNoErrors()->assertRedirect();
        $tenant->refresh();
        $this->assertSame('#123456', $tenant->report_primary_color);
        Storage::disk('local')->assertExists($tenant->report_logo_path);
        app()->instance('currentTenant', $tenant);
        try {
            $html = view('investors.pdf_brand')->render();
            $this->assertStringContainsString('data:image/png;base64,', $html);
            $this->assertStringContainsString('#123456', $html);
            $this->assertStringContainsString('Coastal Investments', $html);
            $this->assertStringContainsString('office@example.test', $html);
            $this->assertStringContainsString('Confidential', $html);
            $other = $this->company('other-brand');
            app()->instance('currentTenant', $other);
            $otherHtml = view('investors.pdf_brand')->render();
            $this->assertStringNotContainsString('data:image/png;base64,', $otherHtml);
            $this->assertStringNotContainsString('office@example.test', $otherHtml);
        } finally {
            app()->forgetInstance('currentTenant');
        }
        $this->actingAs($admin)->post($url, ['name' => $tenant->name, 'report_primary_color' => 'red;invalid'])->assertSessionHasErrors('report_primary_color');
        $owner = $this->member($tenant);
        $this->actingAs($owner)->post($url, ['name' => 'Changed'])->assertForbidden();
    }

    public function test_booking_lists_respect_unit_ownership_dates_and_hide_guest_details(): void
    {
        $tenant = $this->company();
        $owner = $this->member($tenant);
        $investor = $this->profile($tenant, $owner);
        config(['database.connections.tenant.database' => $tenant->database_name]);
        DB::purge('tenant');
        $db = DB::connection('tenant');
        $db->beginTransaction();
        $this->withoutMiddleware(ResolveTenant::class);
        app()->instance('currentTenant', $tenant);
        $this->travelTo(Carbon::parse('2026-10-24 12:00:00', 'Africa/Nairobi'));
        try {
            $original = (array) $db->table('units')->first();
            $otherUnit = $original['id'];
            unset($original['id']);
            $original['unit_number'] = 'INV-BOOKINGS-'.bin2hex(random_bytes(3));
            $unit = $db->table('units')->insertGetId($original);
            DB::table('investor_assignments')->insert(['tenant_id' => $tenant->id, 'investor_id' => $investor->id, 'unit_id' => $unit, 'share_basis_points' => 10000, 'starts_on' => '2026-10-01', 'ends_on' => '2026-12-31', 'agreement_reference' => 'BOOKING-TEST', 'created_at' => now(), 'updated_at' => now()]);
            $visible = [];
            foreach ([[$unit, '2026-10-29', '2026-11-02', 'confirmed', true], [$unit, '2026-10-23', '2026-10-26', 'checked_in', true], [$unit, '2026-10-05', '2026-10-08', 'checked_out', true], [$unit, '2026-09-29', '2026-10-02', 'checked_out', false], [$unit, '2026-12-30', '2027-01-02', 'confirmed', false], [$otherUnit, '2026-10-29', '2026-11-02', 'confirmed', false], [$unit, '2026-11-15', '2026-11-17', 'cancelled', false]] as [$unitId, $in, $out, $status, $allowed]) {
                $id = $db->table('reservations')->insertGetId(['unit_id' => $unitId, 'booking_type' => 'managed', 'guest_name' => 'PRIVATE GUEST NAME', 'guest_phone' => 'PRIVATE-PHONE', 'guest_email' => 'private-guest@example.test', 'check_in' => $in, 'check_out' => $out, 'nightly_rate' => 5000, 'total_amount' => 10000, 'status' => $status]);
                if ($allowed) {
                    $visible[] = $id;
                }
            }
            $response = $this->actingAs($owner)->get('/t/'.$tenant->slug.'/investor-portal')->assertOk()->assertSee('Upcoming')->assertSee('Currently checked in')->assertSee('Completed')->assertDontSee('PRIVATE GUEST NAME')->assertDontSee('PRIVATE-PHONE')->assertDontSee('private-guest@example.test');
            $this->get('/t/'.$tenant->slug.'/investor-portal?tab=upcoming')->assertOk()->assertSee('Upcoming bookings')->assertDontSee('<h2>Currently checked in', false);
            $this->get('/t/'.$tenant->slug.'/investor-portal?tab=completed')->assertOk()->assertSee('Checked-out bookings');
            $actual = collect($response->viewData('bookingLists'))->flatMap(fn ($page) => $page->pluck('id'))->sort()->values()->all();
            sort($visible);
            $this->assertSame($visible, $actual);
            $response->assertSee('Nightly rate')->assertSee('Total stay price')->assertSee('Monthly report summary')->assertSee('5,000.00');
            $this->assertSame(3, $response->viewData('monthly')['bookings']);
            $this->assertSame(9, $response->viewData('monthly')['nights']);
            $this->assertSame(2750000, $response->viewData('monthly')['value_minor']);
            $november = $this->get('/t/'.$tenant->slug.'/investor-portal?month=2026-11')->assertOk()->viewData('monthly');
            $this->assertSame(1, $november['bookings']);
            $this->assertSame(1, $november['nights']);
            $this->assertSame(250000, $november['value_minor']);
            $this->get('/t/'.$tenant->slug.'/investor-portal?month=invalid')->assertSessionHasErrors('month');
            $this->get('/t/'.$tenant->slug.'/investor-portal?unit_id='.$otherUnit)->assertNotFound();
            $tenant->modules = ['reservations' => false];
            $tenant->save();
            $this->get('/t/'.$tenant->slug.'/investor-portal')->assertOk()->assertDontSee('Your unit bookings');
        } finally {
            while ($db->transactionLevel() > 0) {
                $db->rollBack();
            }
            DB::purge('tenant');
            app()->forgetInstance('currentTenant');
            $this->travelBack();
        }
    }

    public function test_directory_and_owner_portal_are_isolated_and_suspension_blocks_access(): void
    {
        $tenant = $this->company();
        $owner = $this->member($tenant);
        $investor = $this->profile($tenant, $owner);
        $other = $this->profile($tenant, $this->member($tenant));
        $admin = $this->member($tenant, 'admin');
        $this->actingAs($admin)->get('/t/'.$tenant->slug.'/investors')->assertOk()->assertSee($investor->name);
        $this->get('/t/'.$tenant->slug.'/investors/'.$investor->id)->assertOk()->assertSee('Property assignments');
        $this->get('/t/'.$tenant->slug.'/investor-notifications')->assertOk();
        $this->actingAs($owner)->get('/')->assertRedirect('/t/'.$tenant->slug.'/investor-portal');
        $this->get('/t/'.$tenant->slug.'/investor-portal')->assertOk()->assertSee($investor->name)->assertDontSee($other->email);
        $this->get('/t/'.$tenant->slug.'/investors')->assertForbidden();
        $this->get('/t/'.$tenant->slug.'/invoices')->assertForbidden();
        DB::table('investors')->where('id', $investor->id)->update(['active' => false]);
        $this->get('/t/'.$tenant->slug.'/investor-portal')->assertNotFound();
    }

    public function test_property_share_overlap_and_permissions_are_enforced(): void
    {
        $tenant = $this->company();
        $admin = $this->member($tenant, 'admin');
        $investor = $this->profile($tenant);
        $second = $this->profile($tenant, $this->member($tenant));
        $this->actingAs($admin);
        // Resolve the existing operational database only for a read-only unit lookup.
        config(['database.connections.tenant.database' => $tenant->database_name]);
        DB::purge('tenant');
        $unit = DB::connection('tenant')->table('units')->first();
        $data = ['unit_id' => $unit->id, 'share' => 70, 'starts_on' => '2020-01-01', 'agreement_reference' => 'INV-TEST'];
        $this->post('/t/'.$tenant->slug.'/investors/'.$investor->id.'/assignments', $data)->assertSessionHasNoErrors();
        $this->post('/t/'.$tenant->slug.'/investors/'.$second->id.'/assignments', [...$data, 'share' => 40])->assertSessionHasErrors('share');
        $this->post('/t/'.$tenant->slug.'/investors/'.$second->id.'/assignments', [...$data, 'share' => 30])->assertSessionHasNoErrors();
        $this->assertSame(2, DB::table('investor_assignments')->count());
        $manager = $this->member($tenant, 'manager');
        $this->actingAs($manager)->post('/t/'.$tenant->slug.'/investors/'.$investor->id.'/assignments', $data)->assertForbidden();
    }

    public function test_investor_notifications_are_deduplicated_and_recheck_access_and_preferences(): void
    {
        $tenant = $this->company();
        $owner = $this->member($tenant);
        $investor = $this->profile($tenant, $owner);
        $service = app(InvestorNotifications::class);
        $service->record($investor, 'statement:test', 'statement_ready', 'Statement ready', 'Please sign in.');
        $service->record($investor, 'statement:test', 'statement_ready', 'Statement ready', 'Please sign in.');
        $this->assertSame(1, DB::table('investor_events')->count());
        $this->assertSame(2, DB::table('investor_deliveries')->count());
        $email = DB::table('investor_deliveries')->where('channel', 'email')->value('id');
        $sms = DB::table('investor_deliveries')->where('channel', 'sms')->value('id');
        Mail::fake();
        Http::preventStrayRequests();
        $service->deliver($email);
        $this->assertDatabaseHas('investor_deliveries', ['id' => $email, 'status' => 'simulated']);
        $service->deliver($sms);
        $this->assertDatabaseHas('investor_deliveries', ['id' => $sms, 'status' => 'skipped']);
        Mail::assertNothingSent();
        $event = $service->record($investor, 'statement:second', 'statement_ready', 'Statement ready', 'Please sign in.');
        DB::table('investors')->where('id', $investor->id)->update(['active' => false]);
        $id = DB::table('investor_deliveries')->where('event_id', $event)->where('channel', 'email')->value('id');
        $service->deliver($id);
        $this->assertDatabaseHas('investor_deliveries', ['id' => $id, 'status' => 'blocked']);
    }

    public function test_invites_use_owner_role_and_only_verified_matching_recipient_can_accept(): void
    {
        Notification::fake();
        $tenant = $this->company();
        $admin = $this->member($tenant, 'admin');
        $investor = $this->profile($tenant);
        $this->actingAs($admin)->post('/t/'.$tenant->slug.'/investors/'.$investor->id.'/invite')->assertSessionHasNoErrors();
        $invitation = CompanyInvitation::first();
        $this->assertSame('owner', $invitation->role);
        $this->assertSame($investor->id, $invitation->investor_id);
        $this->assertNull(DB::table('investors')->where('id', $investor->id)->value('user_id'));
        $this->assertDatabaseCount('investor_audit_events', 1);
    }

    public function test_published_statements_are_private_and_pdf_downloads_share_access_rules(): void
    {
        $tenant = $this->company();
        $owner = $this->member($tenant);
        $investor = $this->profile($tenant, $owner);
        $other = $this->member($tenant);
        $this->profile($tenant, $other);
        $admin = $this->member($tenant, 'admin');
        config(['database.connections.tenant.database' => $tenant->database_name]);
        DB::purge('tenant');
        $db = DB::connection('tenant');
        $db->beginTransaction();
        $this->withoutMiddleware(ResolveTenant::class);
        app()->instance('currentTenant', $tenant);
        try {
            $unit = $db->table('units')->first();
            $assignment = DB::table('investor_assignments')->insertGetId(['tenant_id' => $tenant->id, 'investor_id' => $investor->id, 'unit_id' => $unit->id, 'share_basis_points' => 5000, 'starts_on' => '1999-01-01', 'agreement_reference' => 'INV-PRIVATE', 'created_at' => now(), 'updated_at' => now()]);
            $json = json_encode(['collected' => '1000.00', 'expenses' => '100.00', 'management_fee' => '100.00', 'closing_balance' => '800.00', 'issues' => []]);
            $db->table('owner_statement_closings')->insert(['unit_id' => $unit->id, 'period_month' => '1999-02-01', 'statement_json' => $json, 'statement_hash' => hash('sha256', $json), 'approved_by' => 1, 'approved_at' => now()]);
            $this->actingAs($admin)->post('/t/'.$tenant->slug.'/investors/'.$investor->id.'/statements', ['assignment_id' => $assignment, 'month' => '1999-02'])->assertSessionHasNoErrors();
            $statement = DB::table('investor_statements')->first();
            $this->assertSame('500.00', json_decode($statement->snapshot, true)['totals']['collected']);
            $this->actingAs($owner)->get('/t/'.$tenant->slug.'/investor-portal/statements/'.$statement->id)->assertOk()->assertSee('500.00');
            $this->get('/t/'.$tenant->slug.'/investor-portal/statements/'.$statement->id.'?download=1')->assertOk()->assertHeader('Content-Type', 'application/pdf');
            $this->actingAs($other)->get('/t/'.$tenant->slug.'/investor-portal/statements/'.$statement->id)->assertNotFound();
            $this->actingAs($admin)->post('/t/'.$tenant->slug.'/investors/'.$investor->id.'/statements', ['assignment_id' => $assignment, 'month' => '1999-02'])->assertSessionHasErrors('correction_reason');
            $this->post('/t/'.$tenant->slug.'/investors/'.$investor->id.'/statements', ['assignment_id' => $assignment, 'month' => '1999-02', 'correction_reason' => 'Reviewed presentation'])->assertSessionHasNoErrors();
            $this->assertSame(2, DB::table('investor_statements')->count());
            DB::table('investor_assignments')->where('id', $assignment)->update(['ends_on' => '1999-12-31']);
            $remittance = $db->table('owner_remittances')->insertGetId(['unit_id' => $unit->id, 'period_month' => '1999-02-01', 'payment_date' => '2000-01-10', 'amount' => '80.01', 'payment_method' => 'bank', 'reference' => 'INV-TEST-ONLY', 'recorded_by' => 1, 'created_at' => now(), 'updated_at' => now()]);
            $this->post('/t/'.$tenant->slug.'/investors/'.$investor->id.'/payouts', ['assignment_id' => $assignment, 'remittance_id' => $remittance])->assertSessionHasNoErrors();
            $this->assertDatabaseHas('investor_payouts', ['investor_id' => $investor->id, 'amount_minor' => 4000]);
            $this->post('/t/'.$tenant->slug.'/investors/'.$investor->id.'/payouts', ['assignment_id' => $assignment, 'remittance_id' => $remittance])->assertSessionHasErrors('remittance_id');
        } finally {
            while ($db->transactionLevel() > 0) {
                $db->rollBack();
            }DB::purge('tenant');
            app()->forgetInstance('currentTenant');
        }
    }

    public function test_live_sms_transport_is_mocked_and_sensitive_details_stay_in_portal(): void
    {
        $tenant = $this->company();
        $owner = $this->member($tenant);
        $investor = $this->profile($tenant, $owner);
        DB::table('investor_notification_settings')->insert(['tenant_id' => $tenant->id, 'email_enabled' => true, 'sms_enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        config(['investors.live_delivery' => true, 'investors.sms_driver' => 'twilio', 'investors.twilio_sid' => 'AC'.str_repeat('1', 32), 'investors.twilio_token' => 'fake-token', 'investors.sms_sender' => '+15551234567']);
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM-TEST-ONLY'], 201)]);
        $service = app(InvestorNotifications::class);
        $event = $service->record($investor, 'sms:mock', 'payout_completed', 'Payout recorded', 'Sensitive amount 999999.00');
        $sms = DB::table('investor_deliveries')->where('event_id', $event)->where('channel', 'sms')->value('id');
        $service->deliver($sms);
        $this->assertDatabaseHas('investor_deliveries', ['id' => $sms, 'status' => 'submitted', 'provider_reference' => 'SM-TEST-ONLY']);
        Http::assertSent(fn ($request) => $request['To'] === $investor->phone && ! str_contains($request['Body'], '999999.00') && str_contains($request['Body'], 'investor-portal'));
        $service->deliver($sms);
        Http::assertSentCount(1);
    }

    public function test_cross_company_access_and_module_deactivation_are_blocked(): void
    {
        $tenant = $this->company();
        $investor = $this->profile($tenant);
        $other = $this->company('other-investors');
        $admin = $this->member($other, 'admin');
        $this->actingAs($admin)->get('/t/'.$other->slug.'/investors/'.$investor->id)->assertNotFound();
        $this->post('/t/'.$other->slug.'/investors/'.$investor->id, ['active' => false])->assertNotFound();
        $owner = $this->member($tenant);
        $this->profile($tenant, $owner);
        $tenant->modules = ['investors' => false];
        $tenant->save();
        $this->actingAs($owner)->get('/t/'.$tenant->slug.'/investor-portal')->assertForbidden();
    }
}
