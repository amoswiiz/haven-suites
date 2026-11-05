<?php

namespace Tests\Feature;

use App\Http\Middleware\ResolveTenant;
use App\Models\Tenant;
use App\Models\User;
use App\Services\MaintenanceReportSchema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CleanerMaintenanceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $company;

    private User $cleaner;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.tenant' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]);
        DB::purge('tenant');
        $this->company = Tenant::create(['name' => 'Maintenance test', 'slug' => 'maintenance-test', 'database_name' => ':memory:', 'active' => true]);
        $this->cleaner = User::factory()->create(['name' => 'Ground Team Cleaner', 'active' => true]);
        $this->manager = User::factory()->create(['name' => 'Maintenance Manager', 'active' => true]);
        $this->cleaner->tenants()->attach($this->company, ['roles' => json_encode(['cleaner']), 'legacy_user_id' => 11]);
        $this->manager->tenants()->attach($this->company, ['roles' => json_encode(['admin']), 'legacy_user_id' => 22]);
        app()->instance('currentTenant', $this->company);
        $this->withoutMiddleware(ResolveTenant::class)->actingAs($this->cleaner);

        $schema = DB::connection('tenant')->getSchemaBuilder();
        $schema->create('properties', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
        $schema->create('units', function (Blueprint $table): void {
            $table->id();
            $table->integer('property_id');
            $table->string('unit_number');
        });
        $schema->create('staff', function (Blueprint $table): void {
            $table->id();
            $table->integer('user_id');
            $table->string('full_name');
            $table->string('status');
        });
        $schema->create('maintenance', function (Blueprint $table): void {
            $table->id();
            $table->integer('unit_id');
            $table->integer('assigned_staff_id')->nullable();
            $table->string('title', 180);
            $table->string('category', 50);
            $table->text('description');
            $table->string('priority')->default('medium');
            $table->string('status')->default('open');
            $table->string('provider_name')->nullable();
            $table->string('provider_phone')->nullable();
            $table->text('work_notes')->nullable();
            $table->decimal('cost', 12, 2)->default(0);
            $table->dateTime('reported_at');
            $table->dateTime('completed_at')->nullable();
            $table->timestamps();
        });
        DB::connection('tenant')->table('properties')->insert(['id' => 1, 'name' => 'Beach apartments']);
        DB::connection('tenant')->table('units')->insert([
            ['id' => 1, 'property_id' => 1, 'unit_number' => 'A1'],
            ['id' => 2, 'property_id' => 1, 'unit_number' => 'B2'],
        ]);
        DB::connection('tenant')->table('staff')->insert([
            ['id' => 1, 'user_id' => 11, 'full_name' => 'Ground Team Cleaner', 'status' => 'active'],
            ['id' => 2, 'user_id' => 22, 'full_name' => 'Maintenance Manager', 'status' => 'active'],
        ]);
        DB::connection('tenant')->table('maintenance')->insert($this->issue());
        app(MaintenanceReportSchema::class)->install(DB::connection('tenant'));
    }

    protected function tearDown(): void
    {
        DB::purge('tenant');
        parent::tearDown();
    }

    private function url(string $path = ''): string
    {
        return '/t/'.$this->company->slug.'/maintenance'.($path !== '' ? '/'.$path : '');
    }

    private function issue(array $overrides = []): array
    {
        return array_replace([
            'unit_id' => 1, 'assigned_staff_id' => 2, 'title' => 'Kitchen tap leaking',
            'category' => 'plumbing', 'description' => 'Water drips from the kitchen tap.',
            'priority' => 'high', 'status' => 'assigned', 'provider_name' => 'Private Repair Supplier',
            'provider_phone' => '+254700999888', 'work_notes' => 'Private management work notes',
            'cost' => '54321.00', 'reported_at' => now()->toDateTimeString(), 'completed_at' => null,
        ], $overrides);
    }

    private function payload(array $overrides = []): array
    {
        return array_replace([
            'unit_id' => 2, 'title' => 'Bedroom light is flickering', 'category' => 'electrical',
            'description' => 'The bedside light flickers when switched on.', 'priority' => 'medium',
        ], $overrides);
    }

    public function test_cleaner_can_view_issues_and_report_form_without_management_or_cost_controls(): void
    {
        $this->get($this->url())->assertOk()->assertSee('Kitchen tap leaking')->assertSee('A1')
            ->assertDontSee('Private Repair Supplier')->assertDontSee('+254700999888')
            ->assertDontSee('Private management work notes')->assertDontSee('54,321')
            ->assertDontSee($this->url('edit/1'), false);
        $response = $this->get($this->url('report'))->assertOk()->assertSee('B2')
            ->assertSee('My work')->assertSee('My cleanings')->assertSee('My earnings')
            ->assertDontSee('data-group="Finance"', false)
            ->assertDontSee('name="status"', false)->assertDontSee('name="cost"', false)
            ->assertDontSee('name="assigned_staff_id"', false)->assertDontSee('name="provider_name"', false);
        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $activeLinks = (new \DOMXPath($document))->query('//aside//a[@aria-current="page"]');
        $this->assertSame(1, $activeLinks->length);
        $this->assertStringEndsWith($this->url('report'), $activeLinks->item(0)->getAttribute('href'));
    }

    public function test_cleaner_can_filter_by_apartment_and_unresolved_issues(): void
    {
        DB::connection('tenant')->table('maintenance')->insert($this->issue([
            'unit_id' => 2, 'title' => 'Bedroom light repaired', 'status' => 'completed',
        ]));
        $this->get($this->url().'?unit_id=2')->assertOk()
            ->assertSee('Bedroom light repaired')->assertDontSee('Kitchen tap leaking');
        $this->get($this->url().'?activity=open')->assertOk()
            ->assertSee('Kitchen tap leaking')->assertDontSee('Bedroom light repaired');
    }

    public function test_cleaner_can_report_any_company_apartment_and_cannot_set_management_fields(): void
    {
        $this->post($this->url('report'), $this->payload([
            'status' => 'completed', 'cost' => '9000.00', 'assigned_staff_id' => 2,
            'provider_name' => 'Injected provider', 'provider_phone' => '+254700000000',
            'work_notes' => 'Injected work note', 'completed_at' => now()->toDateTimeString(),
            'reported_by_user_id' => $this->manager->id, 'reported_by_name' => 'Spoofed manager',
        ]))->assertRedirect($this->url())->assertSessionHas('success');

        $issue = DB::connection('tenant')->table('maintenance')->orderByDesc('id')->first();
        $this->assertSame(2, $issue->unit_id);
        $this->assertSame('open', $issue->status);
        $this->assertEquals(0, $issue->cost);
        $this->assertNull($issue->assigned_staff_id);
        $this->assertEmpty($issue->provider_name);
        $this->assertEmpty($issue->provider_phone);
        $this->assertEmpty($issue->work_notes);
        $this->assertNull($issue->completed_at);
        $this->assertEquals($this->cleaner->id, $issue->reported_by_user_id);
        $this->assertSame($this->cleaner->name, $issue->reported_by_name);
        $this->actingAs($this->manager)->get($this->url())->assertOk()->assertSee($this->cleaner->name)->assertSee($issue->title);
    }

    public function test_report_validation_rejects_unknown_apartments_and_invalid_details(): void
    {
        $this->postJson($this->url('report'), $this->payload(['unit_id' => 999]))
            ->assertUnprocessable()->assertJsonValidationErrors('unit_id');
        $this->postJson($this->url('report'), $this->payload(['title' => '', 'category' => 'unrecognized', 'description' => '', 'priority' => 'critical']))
            ->assertUnprocessable()->assertJsonValidationErrors(['title', 'category', 'description', 'priority']);
        $this->postJson($this->url('report'), $this->payload(['title' => str_repeat('x', 181), 'description' => str_repeat('x', 5001)]))
            ->assertUnprocessable()->assertJsonValidationErrors(['title', 'description']);
        $this->assertSame(1, DB::connection('tenant')->table('maintenance')->count());
    }

    public function test_cleaner_cannot_use_management_routes_or_change_an_existing_issue(): void
    {
        $this->get($this->url('create'))->assertForbidden();
        $this->post($this->url('store'), $this->payload(['status' => 'completed', 'cost' => '10.00']))->assertForbidden();
        $this->get($this->url('edit/1'))->assertForbidden();
        $this->post($this->url('update/1'), $this->payload(['status' => 'completed', 'cost' => '10.00']))->assertForbidden();
        $this->assertSame('assigned', DB::connection('tenant')->table('maintenance')->where('id', 1)->value('status'));
        $this->assertSame(1, DB::connection('tenant')->table('maintenance')->count());
    }

    public function test_non_members_inactive_accounts_and_disabled_module_cannot_report(): void
    {
        $outsider = User::factory()->create(['active' => true]);
        $this->actingAs($outsider)->get($this->url())->assertForbidden();
        $this->post($this->url('report'), $this->payload())->assertForbidden();

        $this->cleaner->active = false;
        $this->cleaner->save();
        $this->actingAs($this->cleaner)->get($this->url('report'))->assertForbidden();
        $this->post($this->url('report'), $this->payload())->assertForbidden();

        $this->cleaner->active = true;
        $this->cleaner->save();
        $this->company->modules = ['maintenance' => false];
        $this->company->save();
        $this->actingAs($this->cleaner)->get($this->url())->assertForbidden();
        $this->get($this->url('report'))->assertForbidden();
        $this->post($this->url('report'), $this->payload())->assertForbidden();
        $this->assertSame(1, DB::connection('tenant')->table('maintenance')->count());
    }

    public function test_manager_workflow_preserves_reporter_when_updating_an_issue(): void
    {
        $this->actingAs($this->manager)->get($this->url('create'))->assertOk()->assertSee('name="assigned_staff_id"', false);
        $this->post($this->url('store'), $this->payload(['status' => 'open', 'cost' => '0']))
            ->assertRedirect($this->url())->assertSessionHas('success');
        $issue = DB::connection('tenant')->table('maintenance')->orderByDesc('id')->first();
        $this->assertEquals($this->manager->id, $issue->reported_by_user_id);
        $this->assertSame($this->manager->name, $issue->reported_by_name);
        $this->post($this->url('update/'.$issue->id), $this->payload([
            'status' => 'completed', 'cost' => '200.00', 'assigned_staff_id' => 2,
            'reported_by_user_id' => $this->cleaner->id, 'reported_by_name' => 'Spoofed author',
        ]))->assertSessionHas('success');
        $updated = DB::connection('tenant')->table('maintenance')->find($issue->id);
        $this->assertSame('completed', $updated->status);
        $this->assertNotNull($updated->completed_at);
        $this->assertEquals($this->manager->id, $updated->reported_by_user_id);
        $this->assertSame($this->manager->name, $updated->reported_by_name);
    }

    public function test_reporter_schema_upgrade_is_repeatable_and_preserves_legacy_issues(): void
    {
        app(MaintenanceReportSchema::class)->install(DB::connection('tenant'));
        $issue = DB::connection('tenant')->table('maintenance')->find(1);
        $this->assertSame('Kitchen tap leaking', $issue->title);
        $this->assertNull($issue->reported_by_user_id);
        $this->assertNull($issue->reported_by_name);
        $this->assertEquals('54321.00', $issue->cost);
        $this->assertSame(1, DB::connection('tenant')->table('maintenance')->count());
    }
}
