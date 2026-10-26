<?php

namespace Tests\Feature;

use App\Http\Middleware\ResolveTenant;
use App\Models\Tenant;
use App\Models\User;
use App\Support\InvoiceDefaults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BusinessWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $tenant = Tenant::create(['name' => 'Workflow test', 'slug' => 'workflow', 'database_name' => 'haven_suites_laravel13', 'active' => true]);
        $user = User::create(['name' => 'Test manager', 'username' => 'workflow', 'email' => 'workflow@example.test', 'password' => 'Testing!Password123', 'active' => true]);
        $user->tenants()->attach($tenant, ['roles' => json_encode(['superadmin']), 'legacy_user_id' => 1]);
        $this->actingAs($user)->withoutMiddleware(ResolveTenant::class);
        app()->instance('currentTenant', $tenant);
        config(['database.connections.tenant.database' => $tenant->database_name]);
        DB::purge('tenant');
        DB::connection('tenant')->beginTransaction();
    }

    protected function tearDown(): void
    {
        $connection = DB::connection('tenant');
        while ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }
        DB::purge('tenant');
        parent::tearDown();
    }

    private function unit(): int
    {
        $unit = (array) DB::connection('tenant')->table('units')->first();
        unset($unit['id']);
        $unit['unit_number'] = 'TEST-'.bin2hex(random_bytes(4));
        $unit['status'] = 'active';

        return DB::connection('tenant')->table('units')->insertGetId($unit);
    }

    private function reservation(int $unit): array
    {
        return ['booking_type' => 'managed', 'unit_id' => $unit, 'guest_name' => 'Migration Workflow Guest', 'guest_phone' => '0700000000',
            'guest_email' => 'workflow-guest@example.test', 'check_in' => '2035-01-10', 'check_out' => '2035-01-12',
            'adults' => 1, 'children' => 0, 'nightly_rate' => '100.00', 'amount_paid' => '0.00', 'status' => 'confirmed', 'booking_source' => 'Direct', 'notes' => 'Transactional migration test'];
    }

    public function test_sales_won_requires_verified_payments_and_links_the_matching_booking(): void
    {
        $booking = $this->reservation($this->unit());
        $this->post('/t/workflow/reservations/store', $booking)->assertSessionHas('success');
        $reservationId = DB::connection('tenant')->table('reservations')->where('unit_id', $booking['unit_id'])->value('id');
        $this->post('/t/workflow/sales', [
            'name' => $booking['guest_name'], 'phone' => $booking['guest_phone'], 'source' => 'instagram', 'channel' => 'whatsapp',
            'assigned_to' => auth()->id(), 'check_in' => $booking['check_in'], 'check_out' => $booking['check_out'], 'guests' => 1,
            'next_action' => 'Collect deposit', 'follow_up_at' => now()->addDay()->format('Y-m-d H:i:s'),
        ])->assertSessionHasNoErrors();
        $leadId = DB::table('sales_leads')->value('id');
        $this->post('/t/workflow/sales/'.$leadId.'/win', ['reservation_id' => $reservationId, 'required_deposit' => 100])->assertSessionHasErrors('required_deposit');
        $this->assertSame('new', DB::table('sales_leads')->value('stage'));
        $this->post('/t/workflow/payments/guests/'.$reservationId.'/total', ['guest_total' => 200])->assertSessionHas('success');
        $this->post('/t/workflow/payments/guests/'.$reservationId, ['amount' => 100, 'payment_date' => date('Y-m-d'), 'payment_method' => 'cash', 'reference' => 'TEST-DEPOSIT', 'notes' => 'Sales integration test'])->assertSessionHas('success');
        $this->post('/t/workflow/sales/'.$leadId.'/win', ['reservation_id' => $reservationId, 'required_deposit' => 100])->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->assertDatabaseHas('sales_leads', ['id' => $leadId, 'stage' => 'won', 'reservation_id' => $reservationId]);
    }

    public function test_unlinked_staff_account_form_renders(): void
    {
        $id = DB::connection('tenant')->table('staff')->insertGetId([
            'full_name' => 'Migration test staff', 'phone' => '0700000000', 'email' => 'staff-test@example.test', 'role' => 'cleaner',
            'location' => 'Nairobi', 'status' => 'active', 'user_id' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->get('/t/workflow/staff/'.$id.'/account/create')->assertOk()->assertSee('Create Staff Login Account');
    }

    public function test_sales_moves_to_invoice_and_deposit_stages_without_a_quotation(): void
    {
        $booking = $this->reservation($this->unit());
        $this->post('/t/workflow/reservations/store', $booking)->assertSessionHas('success');
        $reservation = DB::connection('tenant')->table('reservations')->where('unit_id', $booking['unit_id'])->value('id');
        $this->post('/t/workflow/sales', ['name' => $booking['guest_name'], 'phone' => $booking['guest_phone'], 'source' => 'direct', 'channel' => 'phone', 'assigned_to' => auth()->id(), 'check_in' => $booking['check_in'], 'check_out' => $booking['check_out'], 'guests' => 1, 'next_action' => 'Prepare invoice', 'follow_up_at' => now()->addDay()])->assertSessionHasNoErrors();
        $lead = DB::table('sales_leads')->value('id');
        $url = '/t/workflow/sales/'.$lead;
        $this->post($url.'/booking', ['reservation_id' => $reservation, 'version' => 1])->assertSessionHasNoErrors();
        $this->get($url)->assertOk()->assertSee('Create booking invoice')->assertDontSee('Prepare quotation');
        $stage = ['stage' => 'awaiting_deposit', 'assigned_to' => auth()->id(), 'next_action' => 'Collect deposit', 'follow_up_at' => now()->addDay(), 'version' => 2];
        $this->post($url, $stage)->assertSessionHasErrors('stage');
        $this->post('/t/workflow/invoices', ['invoice_type' => 'reservation', 'reservation_id' => $reservation, 'issue_date' => date('Y-m-d'), 'due_date' => date('Y-m-d')])->assertSessionHas('success');
        $invoice = DB::connection('tenant')->table('invoices')->where('reservation_id', $reservation)->value('id');
        $this->post($url, $stage)->assertSessionHasErrors('stage');
        $this->post('/t/workflow/invoices/'.$invoice.'/issue', [])->assertSessionHas('success');
        $this->post($url, [...$stage, 'stage' => 'invoice_issued'])->assertSessionHasNoErrors();
        $this->post($url, [...$stage, 'version' => 3])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('sales_leads', ['id' => $lead, 'stage' => 'awaiting_deposit', 'reservation_id' => $reservation]);
        $this->assertDatabaseCount('sales_quotes', 0);
        $this->get('/t/workflow/sales')->assertOk()->assertSee('Invoice issued')->assertDontSee('Quotation sent')->assertDontSee('require a valid quotation');
        $this->get($url)->assertOk()->assertSee('invoices/'.$invoice, false);
        $this->post($url.'/booking', ['reservation_id' => $reservation, 'version' => 1])->assertSessionHasErrors('version');
    }

    public function test_sales_can_select_an_eligible_invoice_without_typing_its_number(): void
    {
        $db = DB::connection('tenant');
        $booking = $this->reservation($this->unit());
        $this->post('/t/workflow/reservations/store', $booking)->assertSessionHas('success');
        $reservation = $db->table('reservations')->where('unit_id', $booking['unit_id'])->value('id');
        $this->post('/t/workflow/invoices', ['invoice_type' => 'reservation', 'reservation_id' => $reservation, 'issue_date' => date('Y-m-d'), 'due_date' => date('Y-m-d')])->assertSessionHas('success');
        $invoice = $db->table('invoices')->where('reservation_id', $reservation)->value('id');
        $leadData = ['name' => $booking['guest_name'], 'phone' => $booking['guest_phone'], 'source' => 'direct', 'channel' => 'phone', 'assigned_to' => auth()->id(), 'check_in' => $booking['check_in'], 'check_out' => $booking['check_out'], 'guests' => 1, 'next_action' => 'Link invoice', 'follow_up_at' => now()->addDay()];
        $this->post('/t/workflow/sales', $leadData)->assertSessionHasNoErrors();
        $lead = DB::table('sales_leads')->value('id');
        $url = '/t/workflow/sales/'.$lead;
        $this->get($url)->assertOk()->assertSee('candidate-invoice-'.$invoice)->assertSee('Link selected invoice');
        $this->get($url.'?invoice_search=nonexistent-invoice')->assertOk()->assertDontSee('candidate-invoice-'.$invoice);
        $db->table('invoices')->where('id', $invoice)->update(['status' => 'void']);
        $this->get($url)->assertOk()->assertDontSee('candidate-invoice-'.$invoice);
        $this->post($url.'/booking', ['invoice_id' => $invoice, 'version' => 1])->assertSessionHasErrors('invoice_id');
        $db->table('invoices')->where('id', $invoice)->update(['status' => 'draft']);
        $db->table('reservations')->where('id', $reservation)->update(['guest_name' => 'Another guest']);
        $this->post($url.'/booking', ['invoice_id' => $invoice, 'version' => 1])->assertSessionHasErrors('reservation_id');
        $db->table('reservations')->where('id', $reservation)->update(['guest_name' => $booking['guest_name']]);
        $this->post($url.'/booking', ['invoice_id' => $invoice, 'version' => 1])->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->assertDatabaseHas('sales_leads', ['id' => $lead, 'reservation_id' => $reservation, 'version' => 2]);
        $this->assertSame('draft', $db->table('invoices')->where('id', $invoice)->value('status'));
        $this->assertSame(0, $db->table('guest_payments')->where('reservation_id', $reservation)->count());
        $this->post('/t/workflow/sales', [...$leadData, 'phone' => '0712345678'])->assertSessionHasNoErrors();
        $otherLead = DB::table('sales_leads')->max('id');
        $this->get('/t/workflow/sales/'.$otherLead)->assertOk()->assertDontSee('candidate-invoice-'.$invoice);
        $this->post('/t/workflow/sales/'.$otherLead.'/booking', ['invoice_id' => $invoice, 'version' => 1])->assertSessionHasErrors('reservation_id');
    }

    public function test_customer_bookings_show_apartments_and_link_to_their_invoice(): void
    {
        $db = DB::connection('tenant');
        $customer = $db->table('customers')->insertGetId(['full_name' => 'Booking profile test', 'primary_phone' => 'DEMO-NO-CONTACT']);
        $unit = $this->unit();
        $booking = $db->table('reservations')->insertGetId([...$this->reservation($unit), 'customer_id' => $customer, 'total_amount' => 200]);
        $unitNumber = $db->table('units')->where('id', $unit)->value('unit_number');
        $profile = '/t/workflow/customers/'.$customer;
        $this->get($profile)->assertOk()->assertSee('Booking / Apartment')->assertSee($unitNumber)->assertSee('invoices/create?reservation_id='.$booking, false);
        $this->get('/t/workflow/invoices/create?reservation_id='.$booking)->assertOk()->assertSee('value="'.$booking.'" selected', false);
        $this->get('/t/workflow/invoices/create?reservation_id=999999999')->assertNotFound();
        $this->post('/t/workflow/invoices', ['invoice_type' => 'reservation', 'reservation_id' => $booking, 'issue_date' => date('Y-m-d'), 'due_date' => date('Y-m-d')])->assertSessionHas('success');
        $invoice = $db->table('invoices')->where('reservation_id', $booking)->value('id');
        $this->assertNotNull($invoice);
        $this->get($profile)->assertOk()->assertSee('Draft #'.$invoice)->assertSee('invoices/'.$invoice, false)->assertDontSee('invoices/create?reservation_id='.$booking, false);
        $partner = $db->table('reservations')->insertGetId([...$this->reservation($unit), 'unit_id' => null, 'booking_type' => 'partner', 'customer_id' => $customer, 'total_amount' => 200]);
        $this->get($profile)->assertOk()->assertSee('Partner apartment');
        $db->table('reservations')->where('id', $partner)->update(['status' => 'cancelled']);
        $this->get('/t/workflow/invoices/create?reservation_id='.$partner)->assertNotFound();
        $otherCustomer = $db->table('customers')->insertGetId(['full_name' => 'Booking profile test', 'primary_phone' => 'DEMO-NO-CONTACT']);
        $this->get('/t/workflow/customers/'.$otherCustomer)->assertOk()->assertDontSee($unitNumber)->assertDontSee('Draft #'.$invoice);
    }

    public function test_cleaning_form_lists_bookings_and_rejects_wrong_or_cancelled_selections(): void
    {
        $db = DB::connection('tenant');
        $unit = $this->unit();
        $otherUnit = $this->unit();
        $db->table('units')->where('id', $unit)->update(['bedrooms' => 1]);
        $db->table('cleaning_rates')->updateOrInsert(['bedrooms' => 1], ['amount' => 500]);
        $booking = $db->table('reservations')->insertGetId([...$this->reservation($unit), 'guest_name' => 'Picker active guest', 'total_amount' => 200]);
        $cancelled = $db->table('reservations')->insertGetId([...$this->reservation($unit), 'guest_name' => 'Picker cancelled guest', 'total_amount' => 200, 'status' => 'cancelled']);
        $recent = $db->table('reservations')->insertGetId([...$this->reservation($unit), 'guest_name' => 'Picker checkout guest', 'total_amount' => 200, 'status' => 'checked_out', 'check_in' => date('Y-m-d', strtotime('-3 days')), 'check_out' => date('Y-m-d')]);
        $old = $db->table('reservations')->insertGetId([...$this->reservation($unit), 'guest_name' => 'Picker old guest', 'total_amount' => 200, 'status' => 'checked_out', 'check_in' => '2020-01-01', 'check_out' => '2020-01-03']);
        $url = '/t/workflow/housekeeping/my-cleanings';
        $this->get($url.'/new')->assertOk()->assertSee('Booking being cleaned')->assertSee('Picker active guest')->assertSee('Picker checkout guest')->assertDontSee('Picker cancelled guest')->assertDontSee('Picker old guest');
        $staff = $db->table('staff')->insertGetId(['full_name' => 'Picker test cleaner', 'phone' => '', 'location' => 'Nairobi', 'role' => 'cleaner', 'status' => 'active']);
        $payload = ['unit_id' => $unit, 'assigned_staff_id' => $staff, 'bedrooms_cleaned' => 1, 'cleaning_date' => date('Y-m-d'), 'cleaning_type' => 'checkout', 'reservation_id' => $booking];
        $this->post($url, [...$payload, 'unit_id' => $otherUnit])->assertSessionHas('error');
        $this->post($url, [...$payload, 'reservation_id' => $cancelled])->assertSessionHas('error');
        $this->assertSame(0, $db->table('housekeeping')->where('assigned_staff_id', $staff)->count());
        $this->post($url, $payload)->assertSessionHas('success');
        $this->assertSame($booking, (int) $db->table('housekeeping')->where('assigned_staff_id', $staff)->value('reservation_id'));
    }

    public function test_booking_creation_prevents_overlap_and_rejects_invalid_dates(): void
    {
        $data = $this->reservation($this->unit());
        $this->post('/t/workflow/reservations/store', $data)->assertRedirect('/t/workflow/reservations');
        $this->assertSame(1, DB::connection('tenant')->table('reservations')->where('unit_id', $data['unit_id'])->count());
        $this->post('/t/workflow/reservations/store', $data)->assertSessionHas('error');
        $this->assertSame(1, DB::connection('tenant')->table('reservations')->where('unit_id', $data['unit_id'])->count());
        $this->post('/t/workflow/reservations/store', [...$data, 'check_out' => '2035-01-09'])->assertSessionHas('error');
        $row = DB::connection('tenant')->table('reservations')->where('unit_id', $data['unit_id'])->first();
        $this->assertEquals('200.00', $row->total_amount);
    }

    public function test_reservation_invoice_payment_creates_receipt_and_updates_displayed_balance(): void
    {
        $db = DB::connection('tenant');
        $unit = $this->unit();
        $booking = $db->table('reservations')->insertGetId([...$this->reservation($unit), 'total_amount' => 200]);
        $url = '/t/workflow/reservations/'.$booking;
        $this->get($url)->assertOk()->assertSee('Create invoice');
        $this->post('/t/workflow/invoices', ['invoice_type' => 'reservation', 'reservation_id' => $booking, 'issue_date' => date('Y-m-d'), 'due_date' => date('Y-m-d')])->assertSessionHas('success');
        $invoice = $db->table('invoices')->where('reservation_id', $booking)->value('id');
        $this->get($url)->assertOk()->assertSee('Review and issue invoice');
        $this->post('/t/workflow/invoices/'.$invoice.'/issue')->assertSessionHas('success');
        $this->get($url)->assertOk()->assertSee('Save payment &amp; generate receipt', false);
        $endpoint = '/t/workflow/invoices/'.$invoice.'/payments/record';
        $payment = ['amount' => '50.00', 'payment_date' => date('Y-m-d'), 'payment_method' => 'Cash', 'reference' => 'RES-'.bin2hex(random_bytes(8))];
        $lock = $db->table('accounting_period_locks')->insertGetId(['unit_id' => $unit, 'period_month' => date('Y-m-01'), 'is_locked' => 1]);
        $this->post($endpoint, $payment)->assertSessionHas('error');
        $this->assertSame(0, $db->table('guest_payments')->where('reservation_id', $booking)->count());
        $db->table('accounting_period_locks')->where('id', $lock)->delete();
        $response = $this->post($endpoint, $payment)->assertSessionHas('success');
        $allocation = $db->table('invoice_payment_allocations')->where('invoice_id', $invoice)->first();
        $response->assertRedirect('/t/workflow/receipts/'.$allocation->id);
        $this->assertSame(1, $db->table('guest_payments')->where('reservation_id', $booking)->count());
        $this->get($url)->assertOk()->assertSee('KES 150.00')->assertSee('KES 50.00');
        $this->post($endpoint, $payment)->assertSessionHas('error');
        $this->post($endpoint, [...$payment, 'amount' => '151.00', 'reference' => ''])->assertSessionHas('error');
        $this->assertSame(1, $db->table('guest_payments')->where('reservation_id', $booking)->count());
        $db->table('guest_payments')->insert(['reservation_id' => $booking, 'payment_date' => date('Y-m-d'), 'amount' => 10, 'payment_method' => 'Cash']);
        $this->get($url)->assertOk()->assertSee('Allocate existing payment')->assertDontSee('Save payment &amp; generate receipt', false);
        $this->post($endpoint, [...$payment, 'reference' => ''])->assertSessionHas('error');
        $this->assertSame(2, $db->table('guest_payments')->where('reservation_id', $booking)->count());
        $user = auth()->user();
        $user->tenants()->updateExistingPivot(app('currentTenant')->id, ['roles' => json_encode(['manager'])]);
        $user->unsetRelation('tenants');
        $this->get($url)->assertOk()->assertDontSee('id="reservation-payment"', false);
        $this->post($endpoint, $payment)->assertForbidden();
    }

    public function test_company_invoice_settings_control_contact_details_and_new_invoice_defaults(): void
    {
        $tenant = app('currentTenant');
        $this->get('/t/workflow/invoices/settings')->assertOk()->assertSee('Save invoice settings');
        $settings = ['business_name' => 'Custom billing company', 'business_email' => 'billing@example.test', 'business_phone' => "0700000001 - Contact One\n0700000002 - Contact Two", 'business_website' => 'https://example.test', 'business_address' => 'Custom billing address', 'payment_instructions' => 'Custom bank account instructions', 'default_notes' => 'Custom invoice greeting', 'default_terms' => "Deposit terms\n\nArrival terms\n\nCancellation terms"];
        $this->post('/t/workflow/invoices/settings', $settings)->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->assertSame($settings, $tenant->fresh()->invoice_preferences);
        $this->get('/t/workflow/invoices/create')->assertOk()->assertSee('Custom invoice greeting')->assertSee('Cancellation terms');
        $this->post('/t/workflow/invoices', ['invoice_type' => 'standalone', 'customer_name' => 'Settings invoice customer', 'issue_date' => date('Y-m-d'), 'due_date' => date('Y-m-d'), 'item_description' => ['Stay'], 'item_quantity' => [1], 'item_price' => [1000]])->assertSessionHas('success');
        $invoice = DB::connection('tenant')->table('invoices')->where('customer_name', 'Settings invoice customer')->value('id');
        $this->get('/t/workflow/invoices/'.$invoice)->assertOk()->assertSee('0700000001 - Contact One')->assertSee('0700000002 - Contact Two')->assertSee('Custom bank account instructions')->assertSee('doc-terms-box');
        $this->post('/t/workflow/invoices/settings', [...$settings, 'default_terms' => 'Changed terms for future invoices'])->assertSessionHas('success');
        $this->get('/t/workflow/invoices/'.$invoice)->assertOk()->assertSee('Cancellation terms')->assertDontSee('Changed terms for future invoices');
        $this->post('/t/workflow/invoices/settings', [...$settings, 'business_email' => 'invalid'])->assertSessionHasErrors('business_email');
        $other = Tenant::create(['name' => 'Other invoice company', 'slug' => 'other-invoice', 'database_name' => 'other_invoice_database', 'active' => true]);
        app()->instance('currentTenant', $other);
        $this->assertSame('', InvoiceDefaults::forCompany()['notes']);
        $this->assertArrayNotHasKey('business_phone', InvoiceDefaults::settings());
        app()->instance('currentTenant', $tenant);
    }

    public function test_haven_invoice_text_is_prefilled_editable_and_can_be_cleared(): void
    {
        $tenant = app('currentTenant');
        $tenant->update(['slug' => 'haven']);
        $defaults = InvoiceDefaults::forCompany();
        $this->get('/t/haven/invoices/create')->assertOk()->assertSee('Paybill 247 247 Account 898 694')->assertSee('Check-in: 11:00 AM')->assertSee('3-6 days: 50% refund.');
        $payload = ['invoice_type' => 'standalone', 'customer_name' => 'Invoice defaults test', 'issue_date' => date('Y-m-d'), 'due_date' => date('Y-m-d'), 'item_description' => ['Accommodation'], 'item_quantity' => [1], 'item_price' => [5000]];
        $db = DB::connection('tenant');
        foreach ([[], ['notes' => 'Custom payment note', 'terms' => 'Custom arrival terms'], ['notes' => '', 'terms' => '']] as $overrides) {
            $this->post('/t/haven/invoices', [...$payload, ...$overrides])->assertSessionHas('success');
            $invoice = $db->table('invoices')->where('customer_name', 'Invoice defaults test')->latest('id')->first();
            $this->assertSame($overrides['terms'] ?? $defaults['terms'], $invoice->terms);
            $this->assertSame(($overrides['notes'] ?? $defaults['notes']) ?: null, $invoice->notes);
            $page = $this->get('/t/haven/invoices/'.$invoice->id)->assertOk();
            if ($overrides === []) {
                $page->assertSee('Check-in: 11:00 AM')->assertSee('Thank you for choosing Haven Suites BNB');
                $this->get('/t/haven/invoices/'.$invoice->id.'/pdf')->assertNotFound();
                $this->post('/t/haven/invoices/'.$invoice->id.'/issue')->assertSessionHas('success');
                $pdf = $this->get('/t/haven/invoices/'.$invoice->id.'/pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');
                $this->assertStringStartsWith('%PDF-', $pdf->getContent());
                $this->assertSame(1, preg_match_all('~/Type\s*/Page\b~', $pdf->getContent()));
                $this->get('/t/haven/invoices/'.$invoice->id)->assertOk()->assertDontSee('No payments allocated to this invoice yet.')->assertSee('Amount due')->assertSee('Payment details')->assertSee('Download PDF', false);
            } elseif ($overrides['terms'] !== '') {
                $page->assertSee('Custom arrival terms')->assertSee('Custom payment note')->assertDontSee('Check-in: 11:00 AM');
            } else {
                $page->assertDontSee('Check-in: 11:00 AM')->assertDontSee('Guests are responsible for any damages.');
            }
        }
        $tenant->update(['slug' => 'workflow']);
        $this->assertSame(['notes' => '', 'terms' => 'Other company terms'], InvoiceDefaults::forCompany(['default_terms' => 'Other company terms']));
    }

    public function test_long_invoice_terms_flow_onto_multiple_pdf_pages(): void
    {
        $data = ['invoice_type' => 'standalone', 'customer_name' => 'Long invoice test', 'issue_date' => date('Y-m-d'), 'due_date' => date('Y-m-d'), 'item_description' => ['Accommodation'], 'item_quantity' => [1], 'item_price' => [5000], 'terms' => implode("\n", array_fill(0, 90, 'A separate condition retained for this booking.'))];
        $this->post('/t/workflow/invoices', $data)->assertSessionHas('success');
        $invoice = DB::connection('tenant')->table('invoices')->where('customer_name', 'Long invoice test')->value('id');
        $this->post('/t/workflow/invoices/'.$invoice.'/issue')->assertSessionHas('success');
        $pdf = $this->get('/t/workflow/invoices/'.$invoice.'/pdf')->assertOk();
        $this->assertGreaterThan(1, preg_match_all('~/Type\s*/Page\b~', $pdf->getContent()));
    }

    public function test_invoice_types_share_billing_fields_and_save_address_and_terms(): void
    {
        $db = DB::connection('tenant');
        $booking = $db->table('reservations')->insertGetId([...$this->reservation($this->unit()), 'total_amount' => 200]);
        $this->get('/t/workflow/invoices/create?reservation_id='.$booking)->assertOk()
            ->assertSee('Bill to')->assertSee('name="customer_address"', false)->assertSee('name="terms"', false)
            ->assertSee('Invoice Items')->assertDontSee('id="standalone-items"', false);
        foreach (['reservation', 'standalone'] as $type) {
            $this->post('/t/workflow/invoices', [
                'invoice_type' => $type, 'reservation_id' => $booking,
                'customer_name' => 'Corporate billing test', 'customer_email' => 'billing@example.test', 'customer_phone' => '0700000000',
                'customer_address' => 'Nairobi billing address', 'terms' => 'Payment due on arrival',
                'issue_date' => date('Y-m-d'), 'due_date' => date('Y-m-d'),
                'item_description' => ['Direct charge'], 'item_quantity' => [2], 'item_price' => [100],
            ])->assertSessionHas('success');
            $invoice = $db->table('invoices')->where('invoice_type', $type)->where('customer_name', 'Corporate billing test')->orderByDesc('id')->first();
            $this->assertSame('Nairobi billing address', $invoice->customer_address);
            $this->assertSame('Payment due on arrival', $invoice->terms);
            $this->assertEquals(200, $invoice->total_amount);
            $this->get('/t/workflow/invoices/'.$invoice->id)->assertOk()->assertSee('Nairobi billing address')->assertSee('Payment due on arrival');
        }
    }

    public function test_payment_cannot_exceed_guest_balance(): void
    {
        $data = $this->reservation($this->unit());
        $this->post('/t/workflow/reservations/store', $data)->assertSessionHas('success');
        $id = DB::connection('tenant')->table('reservations')->where('unit_id', $data['unit_id'])->value('id');
        $this->post('/t/workflow/payments/guests/'.$id.'/total', ['guest_total' => '200.00'])->assertSessionHas('success');
        $payment = ['amount' => '50.00', 'payment_date' => date('Y-m-d'), 'payment_method' => 'cash', 'reference' => '', 'notes' => 'Test'];
        $this->post('/t/workflow/payments/guests/'.$id, $payment)->assertSessionHas('success');
        $this->assertEquals(50, DB::connection('tenant')->table('guest_payments')->where('reservation_id', $id)->sum('amount'));
        $this->post('/t/workflow/payments/guests/'.$id, [...$payment, 'amount' => '151.00'])->assertSessionHas('error');
        $this->assertEquals(50, DB::connection('tenant')->table('guest_payments')->where('reservation_id', $id)->sum('amount'));
    }

    public function test_quotation_claim_review_does_not_create_a_payment(): void
    {
        $data = $this->reservation($this->unit());
        $this->post('/t/workflow/reservations/store', $data)->assertSessionHas('success');
        $id = DB::connection('tenant')->table('reservations')->where('unit_id', $data['unit_id'])->value('id');
        $response = $this->post('/t/workflow/reservations/'.$id.'/quotation/issue');
        $response->assertRedirect();
        $url = $response->headers->get('Location');
        $this->get($url)->assertOk()->assertSee('QT-', false);
        $this->post($url.'/payment', ['amount' => '100.00', 'mpesa_message' => 'TEST123456X Confirmed payment to HAVEN SUITES for migration testing.'])->assertSessionHas('notice');
        $quote = DB::connection('tenant')->table('haven_quotations')->where('reservation_id', $id)->value('id');
        $claim = DB::connection('tenant')->table('haven_quote_payment_claims')->where('quotation_id', $quote)->value('id');
        $this->post('/t/workflow/quotations/payment-claims/'.$claim.'/review', ['decision' => 'reviewed'])->assertSessionHas('success');
        $this->assertSame('reviewed', DB::connection('tenant')->table('haven_quote_payment_claims')->where('id', $claim)->value('status'));
        $this->assertSame(0, DB::connection('tenant')->table('guest_payments')->where('reservation_id', $id)->count());
    }

    public function test_kyc_guest_upload_is_private_and_reviewable(): void
    {
        $data = $this->reservation($this->unit());
        $this->post('/t/workflow/reservations/store', $data)->assertSessionHas('success');
        $id = DB::connection('tenant')->table('reservations')->where('unit_id', $data['unit_id'])->value('id');
        $this->post('/t/workflow/reservations/'.$id.'/kyc-link')->assertRedirect();
        $url = session('kyc_invitation_url');
        $this->assertNotEmpty($url);
        $this->get($url)->assertOk()->assertSee('Guest');
        try {
            $this->post($url.'/submit', [
                'full_name' => 'Migration Verified Guest', 'primary_phone' => '0700000000', 'email' => 'kyc-test@example.test',
                'document_type' => 'national_id', 'identity_document' => UploadedFile::fake()->image('identity.png', 10, 10),
            ])->assertRedirect('/t/workflow/kyc/thank-you');
            $record = DB::connection('tenant')->table('customer_kyc')->where('reservation_id', $id)->first();
            $this->assertSame('pending', $record->status);
            $this->get('/t/workflow/staff/kyc/'.$record->id.'/document')->assertOk()->assertHeader('content-type', 'image/png');
            $this->get($url)->assertNotFound();
        } finally {
            $key = DB::connection('tenant')->table('customer_kyc')->where('reservation_id', $id)->value('document_storage_key');
            if ($key && preg_match('/^[a-f0-9]{48}\.png$/D', $key)) {
                $file = haven_private_path().'private/kyc/'.$key;
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }
}
