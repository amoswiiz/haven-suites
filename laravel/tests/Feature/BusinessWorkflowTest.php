<?php

namespace Tests\Feature;

use App\Http\Middleware\ResolveTenant;
use App\Models\Tenant;
use App\Models\User;
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
