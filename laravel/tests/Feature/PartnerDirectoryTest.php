<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class PartnerDirectoryTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $company;

    private User $admin;

    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();
        $path = tempnam(sys_get_temp_dir(), 'haven_partners_');
        $this->files[] = $path;
        config(['database.connections.tenant' => ['driver' => 'sqlite', 'database' => $path, 'prefix' => '', 'foreign_key_constraints' => true]]);
        DB::purge('tenant');
        $schema = DB::connection('tenant')->getSchemaBuilder();
        $schema->create('partner_apartments', function (Blueprint $table): void {
            $table->id();
            $table->string('owner_name');
            $table->string('owner_phone')->nullable();
            $table->string('owner_email')->nullable();
            $table->string('apartment_name');
            $table->string('building_name')->nullable();
            $table->string('unit_number')->nullable();
            $table->string('location');
            $table->integer('bedrooms')->default(1);
            $table->integer('bathrooms')->nullable();
            $table->text('notes')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();
        });
        $schema->create('reservations', function (Blueprint $table): void {
            $table->id();
            $table->integer('unit_id')->nullable();
            $table->integer('partner_apartment_id')->nullable();
            $table->string('status')->default('confirmed');
            $table->date('check_in');
            $table->date('check_out');
        });
        $db = DB::connection('tenant');
        $db->table('partner_apartments')->insert([
            ['id' => 1, 'owner_name' => 'Jane Wanjiku', 'owner_phone' => '+254712000001', 'owner_email' => 'jane@example.test', 'apartment_name' => 'Baharini Cottages', 'building_name' => null, 'unit_number' => 'B09', 'location' => 'Diani', 'bedrooms' => 2, 'bathrooms' => 2, 'status' => 'active'],
            ['id' => 2, 'owner_name' => 'Peter Otieno', 'owner_phone' => '+254712000002', 'owner_email' => null, 'apartment_name' => 'Galu Beach Villa', 'building_name' => null, 'unit_number' => null, 'location' => 'Diani, Galu', 'bedrooms' => 2, 'bathrooms' => 1, 'status' => 'active'],
            ['id' => 3, 'owner_name' => 'Ann Mwangi', 'owner_phone' => '020 123 4567', 'owner_email' => null, 'apartment_name' => 'Diani Studio', 'building_name' => null, 'unit_number' => null, 'location' => 'Diani', 'bedrooms' => 0, 'bathrooms' => 1, 'status' => 'active'],
            ['id' => 4, 'owner_name' => 'Nursan Homes', 'owner_phone' => '+254712000004', 'owner_email' => null, 'apartment_name' => 'Muthaiga PH56', 'building_name' => null, 'unit_number' => 'PH56', 'location' => 'Nanyuki', 'bedrooms' => 2, 'bathrooms' => 2, 'status' => 'active'],
            ['id' => 5, 'owner_name' => 'Old Partner', 'owner_phone' => '+254712000005', 'owner_email' => null, 'apartment_name' => 'Retired Flat', 'building_name' => null, 'unit_number' => null, 'location' => 'Diani', 'bedrooms' => 2, 'bathrooms' => 1, 'status' => 'inactive'],
        ]);
        $db->table('reservations')->insert(['partner_apartment_id' => 2, 'status' => 'confirmed', 'check_in' => '2026-12-11', 'check_out' => '2026-12-14']);
        $this->company = Tenant::factory()->create(['name' => 'Haven Suites', 'slug' => 'haven', 'database_name' => $path, 'active' => true, 'plan' => 'legacy', 'subscription_status' => 'legacy']);
        $this->admin = $this->member('admin');
        DB::table('sales_agents')->insert(['tenant_id' => $this->company->id, 'name' => 'Coast Agent', 'phone' => '0722000111', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    protected function tearDown(): void
    {
        DB::purge('tenant');
        foreach ($this->files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        parent::tearDown();
    }

    private function member(string $role): User
    {
        $user = User::factory()->create(['active' => true]);
        $user->tenants()->attach($this->company, ['roles' => json_encode([$role]), 'legacy_user_id' => $user->id]);

        return $user;
    }

    private function search(array $query = []): TestResponse
    {
        return $this->actingAs($this->admin)->get(route('partners.index', $this->company->slug).($query ? '?'.http_build_query($query) : ''));
    }

    public function test_area_and_bedroom_search_finds_matching_active_partners_with_whatsapp_requests(): void
    {
        $response = $this->search(['area' => 'diani', 'bedrooms' => '2', 'check_in' => '2026-12-12', 'check_out' => '2026-12-15', 'guests' => 3])->assertOk();
        $rows = $response->viewData('rows');
        $this->assertSame([1, 2], $rows->pluck('partner.id')->map(fn ($id) => (int) $id)->all());
        $this->assertTrue($rows->firstWhere('partner.id', 2)['booked']);
        $this->assertFalse($rows->firstWhere('partner.id', 1)['booked']);

        parse_str(parse_url($rows->firstWhere('partner.id', 1)['whatsapp_url'], PHP_URL_QUERY), $query);
        $this->assertStringStartsWith('https://wa.me/254712000001', $rows->firstWhere('partner.id', 1)['whatsapp_url']);
        $this->assertSame("Hello Jane, this is Haven Suites. Is Baharini Cottages, B09 (2BR) available?\nDates: Sat 12 Dec 2026 – Tue 15 Dec 2026 (3 nights)\nGuests: 3", $query['text']);
        $response->assertSee('Booked by us for these dates')->assertSee('jane@example.test')->assertDontSee('Retired Flat')->assertDontSee('Muthaiga PH56');

        $agent = $response->viewData('agents')->first();
        parse_str(parse_url($agent['whatsapp_url'], PHP_URL_QUERY), $agentQuery);
        $this->assertStringStartsWith('Hello Coast, this is Haven Suites. Do you have a 2BR in diani available?', $agentQuery['text']);
    }

    public function test_studios_landlines_text_search_and_inactive_partners(): void
    {
        $studio = $this->search(['bedrooms' => '0'])->assertOk()->viewData('rows');
        $this->assertSame([3], $studio->pluck('partner.id')->map(fn ($id) => (int) $id)->all());
        $this->assertNull($studio->first()['whatsapp_url'], 'A landline cannot receive WhatsApp messages.');
        $this->assertSame('+254201234567', $studio->first()['tel']);
        $this->search(['bedrooms' => '0'])->assertSee('No WhatsApp number');

        $this->assertSame([4], $this->search(['q' => 'ph56'])->viewData('rows')->pluck('partner.id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame([5], $this->search(['status' => 'inactive'])->viewData('rows')->pluck('partner.id')->map(fn ($id) => (int) $id)->all());
        $this->search(['bedrooms' => '9'])->assertSessionHasErrors('bedrooms');
    }

    public function test_sales_can_search_but_only_managers_edit_and_cleaners_cannot_open_it(): void
    {
        $sales = $this->member('sales');
        $this->actingAs($sales)->get(route('partners.index', $this->company->slug))->assertOk()->assertDontSee('Add partner apartment');
        $this->actingAs($sales)->get(route('partners.create', $this->company->slug))->assertForbidden();
        $this->actingAs($sales)->post(route('partners.update', [$this->company->slug, 1]), ['owner_name' => 'X'])->assertForbidden();
        $this->actingAs($this->member('cleaner'))->get(route('partners.index', $this->company->slug))->assertForbidden();
    }

    public function test_managers_add_and_correct_partner_apartments_with_validated_phones(): void
    {
        $base = ['owner_name' => 'Mary Coast', 'owner_phone' => '0712 999 888', 'owner_email' => 'Mary@Example.TEST', 'apartment_name' => 'Kinondo Villas',
            'location' => 'Diani', 'bedrooms' => 3, 'bathrooms' => 2, 'status' => 'active'];
        $this->actingAs($this->admin)->post(route('partners.store', $this->company->slug), [...$base, 'owner_phone' => '0712 99'])->assertSessionHasErrors('owner_phone');
        $this->actingAs($this->admin)->post(route('partners.store', $this->company->slug), $base)->assertRedirect(route('partners.index', $this->company->slug));
        $saved = DB::connection('tenant')->table('partner_apartments')->where('apartment_name', 'Kinondo Villas')->first();
        $this->assertSame('+254712999888', $saved->owner_phone);
        $this->assertSame('mary@example.test', $saved->owner_email);

        $this->actingAs($this->admin)->get(route('partners.edit', [$this->company->slug, $saved->id]))->assertOk()->assertSee('value="+254712999888"', false);
        $this->actingAs($this->admin)->post(route('partners.update', [$this->company->slug, $saved->id]), [...$base, 'owner_phone' => '+254 ', 'bedrooms' => 4])->assertSessionHasNoErrors();
        $updated = DB::connection('tenant')->table('partner_apartments')->find($saved->id);
        $this->assertNull($updated->owner_phone);
        $this->assertSame(4, (int) $updated->bedrooms);
        $this->actingAs($this->admin)->post(route('partners.update', [$this->company->slug, 999]), $base)->assertNotFound();
    }

    public function test_requests_include_the_listing_link_and_flag_missing_dates(): void
    {
        config(['app.url' => 'https://app.havensuitesbnb.com']);
        DB::table('public_sites')->insert(['tenant_id' => $this->company->id, 'enabled' => true, 'enquiry_assignee_id' => $this->admin->id, 'response_hours' => 24]);
        DB::table('public_listings')->insert(['tenant_id' => $this->company->id, 'partner_apartment_id' => 1, 'slug' => 'baharini-b09', 'title' => 'Baharini B09',
            'description' => 'x', 'city' => 'Diani', 'max_guests' => 4, 'min_nights' => 1, 'status' => 'published', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('public_listings')->insert(['tenant_id' => $this->company->id, 'partner_apartment_id' => 2, 'slug' => 'galu-draft', 'title' => 'Galu draft',
            'description' => 'x', 'city' => 'Diani', 'max_guests' => 4, 'min_nights' => 1, 'status' => 'draft', 'created_at' => now(), 'updated_at' => now()]);

        $response = $this->search(['area' => 'Diani', 'bedrooms' => '2'])->assertOk()->assertSee("Add the client's dates above", false)->assertSee('partner-directory.js', false);
        $rows = $response->viewData('rows');
        parse_str(parse_url($rows->firstWhere('partner.id', 1)['whatsapp_url'], PHP_URL_QUERY), $published);
        $this->assertSame("Hello Jane, this is Haven Suites. Is Baharini Cottages, B09 (2BR) available?\nDates: to be confirmed\nListing: https://app.havensuitesbnb.com/stays/haven/baharini-b09", $published['text']);
        parse_str(parse_url($rows->firstWhere('partner.id', 2)['whatsapp_url'], PHP_URL_QUERY), $draft);
        $this->assertStringNotContainsString('Listing:', $draft['text'], 'Unpublished listings are not linked.');
        $response->assertSee('data-owner-request-form', false);
        $location = $this->actingAs($this->admin)->post(route('partners.ask', [$this->company->slug, 1]))->headers->get('Location');
        parse_str(parse_url($location, PHP_URL_QUERY), $asked);
        $this->assertStringContainsString("Dates: to be confirmed\nListing: https://app.havensuitesbnb.com/stays/haven/baharini-b09\nReply with one tap: https://app.havensuitesbnb.com/p/", $asked['text']);
    }

    public function test_asking_creates_a_private_request_the_owner_answers_with_one_tap(): void
    {
        config(['app.url' => 'https://app.havensuitesbnb.com']);
        $ask = fn () => $this->actingAs($this->admin)->post(route('partners.ask', [$this->company->slug, 1]), ['check_in' => '2026-12-12', 'check_out' => '2026-12-15', 'guests' => 2]);

        $location = $ask()->assertRedirect()->headers->get('Location');
        $this->assertStringStartsWith('https://wa.me/254712000001?text=', $location);
        parse_str(parse_url($location, PHP_URL_QUERY), $query);
        $this->assertMatchesRegularExpression('#^Hello Jane, this is Haven Suites\. Is Baharini Cottages, B09 \(2BR\) available\?\nDates: Sat 12 Dec 2026 – Tue 15 Dec 2026 \(3 nights\)\nGuests: 2\nReply with one tap: https://app\.havensuitesbnb\.com/p/[A-Za-z0-9_-]{12}$#u', $query['text']);
        $this->assertSame($location, $ask()->headers->get('Location'), 'Asking again for the same request reuses the link.');
        $this->assertDatabaseCount('partner_availability_requests', 1);

        $path = parse_url(substr($query['text'], strrpos($query['text'], 'https://')), PHP_URL_PATH);
        $this->get($path)->assertOk()->assertSee('Baharini Cottages, B09 (2BR)')->assertSee('Sat 12 Dec 2026')->assertSee("Yes, it's available", false)
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')->assertDontSee('+254712000001')->assertDontSee('jane@example.test');
        $this->post($path, ['response' => 'maybe'])->assertSessionHasErrors('response');
        $this->post($path, ['response' => 'available', 'nightly_price' => 9000, 'note' => 'Check-in from 2 pm'])->assertRedirect($path);
        $this->get($path)->assertOk()->assertSee('Your reply: Available')->assertSee('9,000');

        $this->search(['area' => 'Diani', 'bedrooms' => '2', 'check_in' => '2026-12-12', 'check_out' => '2026-12-15'])->assertOk()
            ->assertSee('Owner says available')->assertSee('KES 9,000/night')->assertSee('Check-in from 2 pm')->assertSee('data-owner-request-form', false);

        DB::table('partner_availability_requests')->update(['expires_at' => now()->subMinute()]);
        $this->get($path)->assertNotFound();
        $this->get('/p/AAAAAAAAAAAA')->assertNotFound();
    }

    public function test_owners_without_a_mobile_number_cannot_be_asked_and_labels_skip_repeated_names(): void
    {
        $this->actingAs($this->admin)->from(route('partners.index', $this->company->slug))->post(route('partners.ask', [$this->company->slug, 3]))
            ->assertRedirect(route('partners.index', $this->company->slug))->assertSessionHasErrors('partner');
        $this->assertDatabaseCount('partner_availability_requests', 0);

        DB::connection('tenant')->table('partner_apartments')->where('id', 4)->update(['apartment_name' => 'Golden Sand Resort', 'building_name' => 'golden sand resort', 'unit_number' => 'C017', 'bedrooms' => 1]);
        $this->assertSame('Golden Sand Resort, C017 (1BR)', $this->search(['q' => 'C017'])->viewData('rows')->first()['label']);
    }
}
