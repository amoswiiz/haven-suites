<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class IndependentQuotationTest extends TestCase
{
    use RefreshDatabase;

    private function member(string $role = 'admin', string $slug = 'quotes-test'): User
    {
        $tenant = Tenant::create(['name' => 'Quotation test company', 'slug' => $slug, 'database_name' => $slug === 'quotes-test' ? 'haven_suites_laravel13' : 'not_connected_'.$slug, 'active' => true, 'currency' => 'KES']);
        $user = User::factory()->create();
        $user->tenants()->attach($tenant, ['roles' => json_encode([$role]), 'legacy_user_id' => 1]);

        return $user;
    }

    private function payload(): array
    {
        return ['customer_name' => 'Corporate proposal customer', 'customer_email' => 'proposal@example.test', 'customer_address' => 'Nairobi', 'issue_date' => date('Y-m-d'), 'valid_until' => date('Y-m-d', strtotime('+7 days')), 'terms' => 'Subject to availability', 'items' => [['description' => 'Two nights accommodation', 'quantity' => '2', 'price' => '5000.00'], ['description' => 'Transport', 'quantity' => '1', 'price' => '1500.00']]];
    }

    public function test_independent_quotation_can_be_created_listed_and_printed_without_financial_side_effects(): void
    {
        $this->actingAs($this->member());
        $this->get('/t/quotes-test/quotations')->assertOk()->assertSee('New quotation');
        $this->get('/t/quotes-test/quotations/create')->assertOk()->assertSee('New independent quotation');
        $before = [];
        foreach (['reservations', 'invoices', 'guest_payments'] as $table) {
            $before[$table] = DB::connection('tenant')->table($table)->count();
        }
        $response = $this->post('/t/quotes-test/quotations', $this->payload())->assertSessionHasNoErrors()->assertSessionHas('success');
        $quotation = DB::table('independent_quotations')->first();
        $this->assertEquals(1150000, $quotation->total_minor);
        $this->assertStringStartsWith('QT-', $quotation->number);
        $response->assertRedirect('/t/quotes-test/quotations/independent/'.$quotation->id);
        $this->get($response->headers->get('Location'))->assertOk()->assertSee('Corporate proposal customer')->assertSee('11,500.00')->assertSee('Print / Save PDF')->assertSee('Subject to availability');
        $this->get('/t/quotes-test/quotations')->assertOk()->assertSee($quotation->number);
        foreach ($before as $table => $count) {
            $this->assertSame($count, DB::connection('tenant')->table($table)->count());
        }
        $this->actingAs($this->member('admin', 'other-company'));
        $this->get('/t/other-company/quotations/independent/'.$quotation->id)->assertNotFound();
    }

    public function test_invalid_quotations_and_unauthorised_creation_are_rejected(): void
    {
        $this->actingAs($this->member());
        $this->post('/t/quotes-test/quotations', [...$this->payload(), 'valid_until' => '2000-01-01'])->assertSessionHasErrors('valid_until');
        $this->post('/t/quotes-test/quotations', [...$this->payload(), 'items' => [['description' => 'Invalid', 'quantity' => '1', 'price' => '-1']]])->assertSessionHasErrors('items.0.price');
        $this->post('/t/quotes-test/quotations', [...$this->payload(), 'items' => [['description' => 'No charge', 'quantity' => '1', 'price' => '0']]])->assertSessionHasErrors('items');
        $this->assertDatabaseCount('independent_quotations', 0);
        $this->actingAs($this->member('cleaner', 'restricted'));
        $this->get('/t/restricted/quotations/create')->assertForbidden();
        $this->post('/t/restricted/quotations', $this->payload())->assertForbidden();
    }
}
