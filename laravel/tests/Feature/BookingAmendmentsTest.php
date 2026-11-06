<?php

namespace Tests\Feature;

use App\Http\Middleware\ResolveTenant;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BookingAmendments;
use App\Services\BookingAmendmentSchema;
use App\Services\ExternalCalendars;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\AssertionFailedError;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;
use Throwable;

class BookingAmendmentsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $company;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.tenant' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]);
        DB::purge('tenant');
        $this->company = Tenant::create(['name' => 'Amendment test', 'slug' => 'amendments', 'database_name' => ':memory:', 'active' => true]);
        $this->admin = User::factory()->create(['active' => true]);
        $this->admin->tenants()->attach($this->company, ['roles' => json_encode(['admin']), 'legacy_user_id' => 22]);
        app()->instance('currentTenant', $this->company);
        $this->withoutMiddleware(ResolveTenant::class)->actingAs($this->admin);
        $schema = DB::connection('tenant')->getSchemaBuilder();
        $schema->create('properties', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
        $schema->create('units', function (Blueprint $table): void {
            $table->id();
            $table->integer('property_id');
            $table->string('unit_number');
            $table->string('status')->default('active');
        });
        $schema->create('partner_apartments', function (Blueprint $table): void {
            $table->id();
            $table->string('apartment_name');
            $table->string('status')->default('active');
        });
        $schema->create('reservations', function (Blueprint $table): void {
            $table->id();
            $table->integer('unit_id')->nullable();
            $table->integer('partner_apartment_id')->nullable();
            $table->string('booking_type')->default('managed');
            $table->integer('customer_id')->nullable();
            $table->string('guest_name');
            $table->string('guest_phone');
            $table->string('guest_email')->nullable();
            $table->date('check_in');
            $table->date('check_out');
            $table->integer('adults')->default(1);
            $table->integer('children')->default(0);
            $table->string('booking_source')->default('Direct');
            $table->string('status')->default('confirmed');
            $table->decimal('nightly_rate', 12, 2);
            $table->decimal('total_amount', 12, 2);
            $table->decimal('amount_paid', 12, 2)->default(0);
            $table->decimal('owner_net_amount', 12, 2)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
        $schema->create('invoices', function (Blueprint $table): void {
            $table->id();
            $table->integer('reservation_id')->nullable();
            $table->string('invoice_number')->nullable();
            $table->string('invoice_type')->default('reservation');
            $table->string('customer_name');
            $table->string('customer_email')->nullable();
            $table->string('customer_phone')->nullable();
            $table->string('customer_address')->nullable();
            $table->date('issue_date')->nullable();
            $table->date('due_date')->nullable();
            $table->string('currency')->default('KES');
            $table->string('status')->default('issued');
            $table->decimal('subtotal', 12, 2);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2);
            $table->text('notes')->nullable();
            $table->text('terms')->nullable();
            $table->integer('created_by')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->timestamps();
        });
        $schema->create('invoice_items', function (Blueprint $table): void {
            $table->id();
            $table->integer('invoice_id');
            $table->text('description');
            $table->decimal('quantity', 12, 2)->default(1);
            $table->decimal('unit_price', 12, 2);
            $table->decimal('line_total', 12, 2);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });
        $schema->create('guest_payments', function (Blueprint $table): void {
            $table->id();
            $table->integer('reservation_id');
            $table->date('payment_date');
            $table->decimal('amount', 12, 2);
            $table->string('payment_method')->default('mpesa');
            $table->string('reference')->nullable();
            $table->text('notes')->nullable();
            $table->integer('recorded_by')->nullable();
            $table->timestamps();
        });
        $schema->create('invoice_payment_allocations', function (Blueprint $table): void {
            $table->id();
            $table->integer('invoice_id');
            $table->integer('guest_payment_id');
            $table->decimal('amount', 12, 2);
            $table->timestamp('allocated_at')->nullable();
            $table->integer('allocated_by')->nullable();
        });
        $schema->create('booking_financials', function (Blueprint $table): void {
            $table->id();
            $table->integer('reservation_id')->unique();
            $table->decimal('guest_total', 12, 2)->default(0);
            $table->string('host_name')->nullable();
            $table->string('host_phone')->nullable();
            $table->decimal('host_total', 12, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
        });
        $schema->create('accounting_period_locks', function (Blueprint $table): void {
            $table->id();
            $table->integer('unit_id');
            $table->date('period_month');
            $table->boolean('is_locked');
        });
        $db = DB::connection('tenant');
        $db->table('properties')->insert(['id' => 1, 'name' => 'Coral Gables']);
        $db->table('units')->insert([['id' => 1, 'property_id' => 1, 'unit_number' => 'F5'], ['id' => 2, 'property_id' => 1, 'unit_number' => 'F6']]);
        $db->table('reservations')->insert([
            'id' => 1, 'unit_id' => 1, 'guest_name' => 'Agent Daves', 'guest_phone' => '+254729398261',
            'check_in' => '2026-11-04', 'check_out' => '2026-11-08', 'nightly_rate' => '10000.00',
            'total_amount' => '40000.00', 'amount_paid' => '10000.00', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $db->table('booking_financials')->insert(['reservation_id' => 1, 'guest_total' => '40000.00', 'host_total' => '20000.00', 'host_name' => 'Unchanged host', 'created_at' => now(), 'updated_at' => now()]);
        app(BookingAmendmentSchema::class)->install($db);
    }

    protected function tearDown(): void
    {
        DB::purge('tenant');
        parent::tearDown();
    }

    private function reservation(): array
    {
        return (array) DB::connection('tenant')->table('reservations')->find(1);
    }

    private function invoice(array $overrides = []): int
    {
        $db = DB::connection('tenant');
        $id = $db->table('invoices')->insertGetId(array_replace([
            'reservation_id' => 1, 'invoice_number' => 'INV-HAVEN-2026-000100', 'customer_name' => 'Agent Daves',
            'status' => 'issued', 'issue_date' => '2026-11-04', 'due_date' => '2026-11-08',
            'subtotal' => '40000.00', 'total_amount' => '40000.00', 'issued_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ], $overrides));
        $db->table('invoice_items')->insert([
            'invoice_id' => $id, 'description' => 'Accommodation — booking #1 (2026-11-04 to 2026-11-08, 4 nights)',
            'quantity' => 1, 'unit_price' => '40000.00', 'line_total' => '40000.00', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function payment(int $invoiceId, string $amount = '10000.00', ?string $allocated = null): int
    {
        $db = DB::connection('tenant');
        $id = $db->table('guest_payments')->insertGetId(['reservation_id' => 1, 'payment_date' => '2026-11-04', 'amount' => $amount, 'reference' => 'MPESA-ORIGINAL', 'created_at' => now(), 'updated_at' => now()]);
        $db->table('invoice_payment_allocations')->insert(['invoice_id' => $invoiceId, 'guest_payment_id' => $id, 'amount' => $allocated ?? $amount, 'allocated_at' => now(), 'allocated_by' => 22]);

        return $id;
    }

    private function payload(array $overrides = []): array
    {
        $reservation = $this->reservation();
        unset($reservation['id'], $reservation['created_at'], $reservation['amount_paid'], $reservation['booking_type'], $reservation['customer_id']);

        return array_replace($reservation, ['check_out' => '2026-11-07', 'total_amount' => '30000.00'], $overrides);
    }

    private function save(array $overrides = [], ?string $snapshot = null, bool $consent = true, string $reason = 'Guest requested an earlier departure', ?User $actor = null): void
    {
        $service = app(BookingAmendments::class);
        $service->save(1, $this->payload($overrides), $snapshot ?? $service->preview($this->reservation())['snapshot'], $consent, $reason, $actor ?? $this->admin);
    }

    private function assertRejected(callable $action): void
    {
        try {
            $action();
        } catch (Throwable $exception) {
            if ($exception instanceof QueryException || $exception instanceof \Error) {
                throw $exception;
            }
            $this->assertTrue($exception->getMessage() !== '' || ($exception instanceof HttpExceptionInterface && $exception->getStatusCode() === 403));
            $this->assertSame('2026-11-08', $this->reservation()['check_out']);
            $this->assertEquals(40000, $this->reservation()['total_amount']);
            $this->assertSame(0, DB::connection('tenant')->table('booking_amendments')->count());

            return;
        }
        $this->fail('The protected booking amendment should have been rejected.');
    }

    public function test_earlier_checkout_amends_invoice_and_booking_together_without_changing_payments(): void
    {
        $id = $this->invoice();
        $paymentId = $this->payment($id);
        $db = DB::connection('tenant');
        $paymentBefore = (array) $db->table('guest_payments')->find($paymentId);
        $allocationBefore = (array) $db->table('invoice_payment_allocations')->first();

        $this->save();

        $this->assertSame('2026-11-07', $this->reservation()['check_out']);
        $this->assertEquals(30000, $this->reservation()['total_amount']);
        $this->assertEquals(10000, $this->reservation()['amount_paid']);
        $invoice = $db->table('invoices')->find($id);
        $this->assertEquals(30000, $invoice->subtotal);
        $this->assertEquals(30000, $invoice->total_amount);
        $this->assertSame('INV-HAVEN-2026-000100', $invoice->invoice_number);
        $this->assertSame('issued', $invoice->status);
        $this->assertSame($paymentBefore, (array) $db->table('guest_payments')->find($paymentId));
        $this->assertSame($allocationBefore, (array) $db->table('invoice_payment_allocations')->first());
        $this->assertEquals(20000, $invoice->total_amount - $db->table('invoice_payment_allocations')->sum('amount'));
        $item = $db->table('invoice_items')->where('invoice_id', $id)->first();
        $this->assertStringContainsString('2026-11-07', $item->description);
        $this->assertStringContainsString('3 nights', $item->description);
        $this->assertEquals(30000, $item->line_total);
        $financials = $db->table('booking_financials')->first();
        $this->assertEquals(30000, $financials->guest_total);
        $this->assertEquals(20000, $financials->host_total);
        $history = $db->table('booking_amendments')->first();
        $this->assertEquals($this->admin->id, $history->actor_id);
        $this->assertSame('Guest requested an earlier departure', $history->reason);
        $before = json_decode($history->before_snapshot, true, flags: JSON_THROW_ON_ERROR);
        $after = json_decode($history->after_snapshot, true, flags: JSON_THROW_ON_ERROR);
        $this->assertEquals(40000, $before['invoices'][0]['invoice']['total_amount']);
        $this->assertEquals(30000, $after['invoices'][0]['invoice']['total_amount']);
        $this->assertEquals($allocationBefore['id'], $before['invoices'][0]['allocations'][0]['id']);
    }

    public function test_issued_invoice_amendment_requires_explicit_consent_and_a_reason(): void
    {
        $this->invoice();
        $this->assertRejected(fn () => $this->save(consent: false));
        $this->assertRejected(fn () => $this->save(reason: ''));
        $this->assertRejected(fn () => $this->save(reason: 'ok'));
        $this->assertRejected(fn () => $this->save(reason: str_repeat('x', 2001)));
    }

    public function test_staff_without_invoice_permission_cannot_amend_issued_or_draft_invoices(): void
    {
        $id = $this->invoice();
        $actor = User::factory()->create(['active' => true]);
        $actor->tenants()->attach($this->company, ['roles' => json_encode(['cleaner']), 'legacy_user_id' => 23]);
        $this->assertRejected(fn () => $this->save(actor: $actor));
        DB::connection('tenant')->table('invoices')->where('id', $id)->update(['status' => 'draft']);
        $this->assertRejected(fn () => $this->save(consent: false, reason: '', actor: $actor));
    }

    public function test_draft_invoice_stays_in_sync_without_issued_invoice_consent(): void
    {
        $id = $this->invoice(['status' => 'draft', 'invoice_number' => null, 'issued_at' => null]);
        $this->save(consent: false, reason: '');
        $this->assertSame('draft', DB::connection('tenant')->table('invoices')->find($id)->status);
        $this->assertEquals(30000, DB::connection('tenant')->table('invoice_items')->first()->line_total);
        $this->assertSame(1, DB::connection('tenant')->table('booking_amendments')->count());
    }

    public function test_same_total_date_changes_still_update_the_invoice_accommodation_dates(): void
    {
        $this->invoice();
        $this->assertRejected(fn () => $this->save(['check_in' => '2026-11-03', 'total_amount' => '40000.00'], consent: false));
        $this->save(['check_in' => '2026-11-03', 'total_amount' => '40000.00']);
        $this->assertStringContainsString('2026-11-03 to 2026-11-07, 4 nights', DB::connection('tenant')->table('invoice_items')->first()->description);
    }

    public function test_revised_total_cannot_be_lower_than_recorded_or_historical_payments(): void
    {
        $id = $this->invoice();
        $this->payment($id, '35000.00');
        $this->assertRejected(fn () => $this->save());
        DB::connection('tenant')->table('invoice_payment_allocations')->delete();
        DB::connection('tenant')->table('guest_payments')->delete();
        DB::connection('tenant')->table('reservations')->where('id', 1)->update(['amount_paid' => '35000.00']);
        $this->assertRejected(fn () => $this->save());
    }

    public function test_revised_invoice_cannot_be_lower_than_its_existing_allocations(): void
    {
        $id = $this->invoice();
        $this->payment($id, '10000.00', '35000.00');
        $this->assertRejected(fn () => $this->save());
        $this->assertEquals(40000, DB::connection('tenant')->table('invoices')->find($id)->total_amount);
    }

    public function test_stale_booking_and_invoice_snapshots_are_rejected(): void
    {
        $id = $this->invoice();
        $service = app(BookingAmendments::class);
        $snapshot = $service->preview($this->reservation())['snapshot'];
        DB::connection('tenant')->table('reservations')->where('id', 1)->update(['guest_name' => 'Updated by a colleague']);
        $this->assertRejected(fn () => $this->save(snapshot: $snapshot));
        $snapshot = $service->preview($this->reservation())['snapshot'];
        DB::connection('tenant')->table('invoice_items')->where('invoice_id', $id)->update(['description' => 'Changed invoice line']);
        $this->assertRejected(fn () => $this->save(snapshot: $snapshot));
    }

    public function test_customized_invoices_are_not_silently_overwritten(): void
    {
        $id = $this->invoice();
        $db = DB::connection('tenant');
        foreach ([['tax_amount' => '1000.00'], ['discount_amount' => '1000.00'], ['currency' => 'USD']] as $change) {
            $db->table('invoices')->where('id', $id)->update(array_replace(['tax_amount' => 0, 'discount_amount' => 0, 'currency' => 'KES'], $change));
            $this->assertRejected(fn () => $this->save());
        }
        $db->table('invoices')->where('id', $id)->update(['currency' => 'KES']);
        $db->table('invoice_items')->insert(['invoice_id' => $id, 'description' => 'Additional charge', 'quantity' => 1, 'unit_price' => 100, 'line_total' => 100]);
        $this->assertRejected(fn () => $this->save());
    }

    public function test_multiple_active_invoices_require_separate_finance_review(): void
    {
        $this->invoice();
        $this->invoice(['invoice_number' => 'INV-HAVEN-2026-000101']);
        $this->assertRejected(fn () => $this->save());
        $this->assertEquals(80000, DB::connection('tenant')->table('invoices')->sum('total_amount'));
    }

    public function test_void_invoices_do_not_block_booking_changes_or_get_rewritten(): void
    {
        $id = $this->invoice(['status' => 'void', 'voided_at' => now()]);
        $this->save(consent: false, reason: '');
        $this->assertSame('2026-11-07', $this->reservation()['check_out']);
        $this->assertEquals(40000, DB::connection('tenant')->table('invoices')->find($id)->total_amount);
        $this->assertStringContainsString('2026-11-08', DB::connection('tenant')->table('invoice_items')->first()->description);
    }

    public function test_old_and_new_accounting_months_are_protected_and_changes_roll_back(): void
    {
        $id = $this->invoice();
        $db = DB::connection('tenant');
        $db->table('accounting_period_locks')->insert(['unit_id' => 1, 'period_month' => '2026-11-01', 'is_locked' => true]);
        $this->assertRejected(fn () => $this->save());
        $db->table('accounting_period_locks')->delete();
        $db->table('accounting_period_locks')->insert(['unit_id' => 2, 'period_month' => '2026-12-01', 'is_locked' => true]);
        $this->assertRejected(fn () => $this->save(['unit_id' => 2, 'check_in' => '2026-12-04', 'check_out' => '2026-12-07']));
        $this->assertEquals(40000, $db->table('invoices')->find($id)->total_amount);
        $this->assertEquals(40000, $db->table('booking_financials')->first()->guest_total);
    }

    public function test_overlapping_bookings_prevent_the_amendment(): void
    {
        $this->invoice();
        $db = DB::connection('tenant');
        $overlap = $this->reservation();
        unset($overlap['id']);
        $overlap['unit_id'] = 2;
        $db->table('reservations')->insert($overlap);
        $this->assertRejected(fn () => $this->save(['unit_id' => 2]));
    }

    public function test_missing_audit_schema_prevents_an_unaudited_invoice_change(): void
    {
        $id = $this->invoice();
        $snapshot = app(BookingAmendments::class)->preview($this->reservation())['snapshot'];
        DB::connection('tenant')->getSchemaBuilder()->drop('booking_amendments');
        try {
            $this->save(snapshot: $snapshot);
            $this->fail('An invoice must not be amended without its audit history.');
        } catch (Throwable $exception) {
            if ($exception instanceof AssertionFailedError) {
                throw $exception;
            }
            $this->assertSame('2026-11-08', $this->reservation()['check_out']);
            $this->assertEquals(40000, DB::connection('tenant')->table('invoices')->find($id)->total_amount);
            $this->assertEquals(40000, DB::connection('tenant')->table('booking_financials')->first()->guest_total);
        }
    }

    public function test_booking_edit_submission_updates_both_documents_and_preserves_attempts_on_validation_error(): void
    {
        $id = $this->invoice();
        $this->payment($id);
        $url = '/t/amendments/reservations/update/1';
        $snapshot = app(BookingAmendments::class)->preview($this->reservation())['snapshot'];
        $data = array_replace($this->payload(), ['booking_snapshot' => $snapshot, 'amendment_reason' => 'Guest requests earlier checkout']);
        $this->from('/t/amendments/reservations/1/edit')->post($url, $data)
            ->assertRedirect('/t/amendments/reservations/1/edit')->assertSessionHas('error')
            ->assertSessionHas('_old_input.check_out', '2026-11-07');
        $this->post($url, array_replace($data, ['amend_invoice' => '1']))
            ->assertRedirect('/t/amendments/reservations/1')->assertSessionHas('success');
        $this->assertSame('2026-11-07', $this->reservation()['check_out']);
        $this->assertEquals(30000, DB::connection('tenant')->table('invoices')->find($id)->total_amount);
        $this->assertStringContainsString('2026-11-07', DB::connection('tenant')->table('invoice_items')->first()->description);
    }

    public function test_booking_edit_form_links_to_invoice_and_has_an_explicit_amendment_control(): void
    {
        $id = $this->invoice();
        $this->payment($id);
        $this->get('/t/amendments/reservations/1/edit')->assertOk()
            ->assertSee('/t/amendments/invoices/'.$id, false)
            ->assertSee('name="amend_invoice"', false)->assertSee('name="amendment_reason"', false)
            ->assertSee('name="booking_snapshot"', false)->assertSee('INV-HAVEN-2026-000100');
    }

    public function test_partner_bookings_and_independently_changed_guest_totals_need_finance_review(): void
    {
        $this->invoice();
        $db = DB::connection('tenant');
        $db->table('booking_financials')->where('reservation_id', 1)->update(['guest_total' => '35000.00']);
        $this->assertRejected(fn () => $this->save());
        $db->table('booking_financials')->where('reservation_id', 1)->update(['guest_total' => '40000.00']);
        $db->table('reservations')->where('id', 1)->update(['booking_type' => 'partner']);
        $this->assertRejected(fn () => $this->save());
    }

    public function test_external_calendar_blocks_prevent_date_amendment(): void
    {
        $this->invoice();
        $calendar = $this->mock(ExternalCalendars::class);
        $calendar->shouldReceive('overlaps')->once()->with($this->company->id, 2, '2026-11-04', '2026-11-07')->andReturnTrue();
        $this->assertRejected(fn () => $this->save(['unit_id' => 2]));
    }

    public function test_owner_and_maintenance_blocks_prevent_stay_amendment(): void
    {
        $this->invoice();
        DB::table('public_unit_closures')->insert([
            'tenant_id' => $this->company->id, 'unit_id' => 2, 'start_date' => '2026-11-04', 'end_date' => '2026-11-07',
            'kind' => 'maintenance', 'reason' => 'Private repair note', 'created_by' => $this->admin->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertRejected(fn () => $this->save(['unit_id' => 2]));
    }

    public function test_audit_write_failure_rolls_back_every_financial_change(): void
    {
        $id = $this->invoice();
        $this->payment($id);
        $db = DB::connection('tenant');
        $db->statement("CREATE TRIGGER fail_booking_audit BEFORE INSERT ON booking_amendments BEGIN SELECT RAISE(ABORT, 'Audit unavailable'); END");
        try {
            $this->save();
            $this->fail('An audit write failure must roll back the amendment.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('Audit unavailable', $exception->getMessage());
            $this->assertSame('2026-11-08', $this->reservation()['check_out']);
            $this->assertEquals(40000, $this->reservation()['total_amount']);
            $this->assertEquals(40000, $db->table('invoices')->find($id)->total_amount);
            $this->assertEquals(40000, $db->table('invoice_items')->first()->line_total);
            $this->assertEquals(40000, $db->table('booking_financials')->first()->guest_total);
            $this->assertSame(0, $db->table('booking_amendments')->count());
            $this->assertEquals(10000, $db->table('guest_payments')->sum('amount'));
            $this->assertEquals(10000, $db->table('invoice_payment_allocations')->sum('amount'));
        }
    }

    public function test_amended_invoice_and_pdf_show_the_revision_and_escaped_history(): void
    {
        $schema = DB::connection('tenant')->getSchemaBuilder();
        $schema->create('invoice_settings', function (Blueprint $table): void {
            $table->id();
        });
        $schema->create('customers', function (Blueprint $table): void {
            $table->id();
            $table->string('full_name');
            $table->string('primary_phone');
            $table->string('email')->nullable();
        });
        $id = $this->invoice();
        $this->payment($id);
        $reason = '<b>Earlier departure</b>';
        $this->save(reason: $reason);

        $this->get('/t/amendments/invoices/'.$id)->assertOk()->assertSee('Revision 1')
            ->assertSee('/t/amendments/reservations/1/edit#invoice-amendment', false)
            ->assertSee('Stay and invoice amendment history')->assertSee($reason)
            ->assertDontSee($reason, false)->assertSee('KES 30,000.00')->assertSee('KES 20,000.00');
        $this->get('/t/amendments/invoices/'.$id.'/pdf')->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    }
}
