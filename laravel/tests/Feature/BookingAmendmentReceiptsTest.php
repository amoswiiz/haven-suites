<?php

namespace Tests\Feature;

use App\Http\Middleware\ResolveTenant;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BookingAmendmentSchema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BookingAmendmentReceiptsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $company;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.tenant' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]);
        DB::purge('tenant');
        $this->company = Tenant::create(['name' => 'Receipt test', 'slug' => 'receipt-test', 'database_name' => ':memory:', 'active' => true]);
        $user = User::factory()->create(['active' => true]);
        $user->tenants()->attach($this->company, ['roles' => json_encode(['admin']), 'legacy_user_id' => 22]);
        app()->instance('currentTenant', $this->company);
        $this->withoutMiddleware(ResolveTenant::class)->actingAs($user);
        $schema = DB::connection('tenant')->getSchemaBuilder();
        $schema->create('invoices', function (Blueprint $table): void {
            $table->id();
            $table->string('invoice_number');
            $table->unsignedInteger('reservation_id');
            $table->string('customer_name');
            $table->string('customer_email')->nullable();
            $table->string('customer_phone')->nullable();
            $table->string('currency');
            $table->decimal('total_amount', 12, 2);
            $table->string('status');
        });
        $schema->create('guest_payments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('reservation_id');
            $table->date('payment_date');
            $table->decimal('amount', 12, 2);
            $table->string('payment_method');
            $table->string('reference')->nullable();
        });
        $schema->create('invoice_payment_allocations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('invoice_id');
            $table->unsignedInteger('guest_payment_id');
            $table->decimal('amount', 12, 2);
            $table->timestamp('allocated_at');
        });
        $schema->create('invoice_settings', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
        });
        $db = DB::connection('tenant');
        $db->table('invoices')->insert([
            'id' => 1, 'invoice_number' => 'INV-TEST-1', 'reservation_id' => 826,
            'customer_name' => 'Guest', 'currency' => 'KES', 'total_amount' => '20000.00', 'status' => 'issued',
        ]);
        $this->payment(1, '10000.00');
        app(BookingAmendmentSchema::class)->install($db);
    }

    protected function tearDown(): void
    {
        DB::purge('tenant');
        parent::tearDown();
    }

    private function payment(int $id, string $amount): void
    {
        DB::connection('tenant')->table('guest_payments')->insert([
            'id' => $id, 'reservation_id' => 826, 'payment_date' => '2026-11-04', 'amount' => $amount, 'payment_method' => 'Mpesa',
        ]);
        DB::connection('tenant')->table('invoice_payment_allocations')->insert([
            'id' => $id, 'invoice_id' => 1, 'guest_payment_id' => $id, 'amount' => $amount, 'allocated_at' => '2026-11-04 10:00:00',
        ]);
    }

    private function amendment(string $invoiceTotal, array $allocationIds): void
    {
        $db = DB::connection('tenant');
        $invoice = (array) $db->table('invoices')->find(1);
        $invoice['total_amount'] = $invoiceTotal;
        $snapshot = [
            'reservation' => ['id' => 826],
            'invoices' => [[
                'invoice' => $invoice,
                'items' => [],
                'allocations' => $db->table('invoice_payment_allocations')->whereIn('id', $allocationIds)->get()->map(fn ($row) => (array) $row)->all(),
            ]],
            'financials' => null,
        ];
        $db->table('booking_amendments')->insert([
            'reservation_id' => 826, 'invoice_id' => 1, 'actor_id' => auth()->id(), 'actor_name' => 'Administrator',
            'reason' => 'Shorten stay', 'before_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
            'after_snapshot' => '{}', 'created_at' => now(),
        ]);
    }

    public function test_receipt_preserves_its_invoice_total_before_the_first_later_amendment(): void
    {
        $this->amendment('40000.00', [1]);
        $this->amendment('30000.00', [1]);

        $this->get('/t/receipt-test/receipts/1')->assertOk()
            ->assertSee('Invoice total at this payment</span><strong>KES 40,000.00', false)
            ->assertSee('Invoice balance after this payment</span><strong>KES 30,000.00', false)
            ->assertSee('This invoice was amended after this payment.')
            ->assertSee('Current invoice total: KES 20,000.00. Current balance: KES 10,000.00.', false);

        $db = DB::connection('tenant');
        $this->assertEquals(20000, $db->table('invoices')->value('total_amount'));
        $this->assertSame(1, $db->table('guest_payments')->count());
        $this->assertSame(1, $db->table('invoice_payment_allocations')->count());
    }

    public function test_receipt_created_between_amendments_uses_the_next_snapshot_containing_it(): void
    {
        $this->amendment('40000.00', [1]);
        $this->payment(2, '5000.00');
        $this->amendment('30000.00', [1, 2]);
        DB::connection('tenant')->table('invoices')->where('id', 1)->update(['total_amount' => '25000.00']);

        $this->get('/t/receipt-test/receipts/2')->assertOk()
            ->assertSee('Invoice total at this payment</span><strong>KES 30,000.00', false)
            ->assertSee('Invoice balance after this payment</span><strong>KES 15,000.00', false)
            ->assertSee('Current invoice total: KES 25,000.00. Current balance: KES 10,000.00.', false);
    }

    public function test_receipt_created_after_the_last_amendment_uses_the_current_invoice(): void
    {
        $this->amendment('40000.00', [1]);
        $this->payment(2, '5000.00');

        $this->get('/t/receipt-test/receipts/2')->assertOk()
            ->assertSee('Invoice total at this payment</span><strong>KES 20,000.00', false)
            ->assertSee('Invoice balance after this payment</span><strong>KES 5,000.00', false)
            ->assertDontSee('This invoice was amended after this payment.');
    }

    public function test_receipts_remain_available_before_the_amendment_schema_is_installed(): void
    {
        DB::connection('tenant')->getSchemaBuilder()->drop('booking_amendments');

        $this->get('/t/receipt-test/receipts/1')->assertOk()
            ->assertSee('Invoice total at this payment</span><strong>KES 20,000.00', false)
            ->assertDontSee('This invoice was amended after this payment.');
    }

    public function test_installing_the_schema_again_preserves_existing_history(): void
    {
        $this->amendment('40000.00', [1]);
        $db = DB::connection('tenant');
        $before = $db->table('booking_amendments')->first();

        app(BookingAmendmentSchema::class)->install($db);

        $this->assertSame(1, $db->table('booking_amendments')->count());
        $this->assertEquals($before, $db->table('booking_amendments')->first());
    }
}
