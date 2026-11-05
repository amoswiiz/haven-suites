<?php

namespace Tests\Feature;

use App\Http\Middleware\ResolveTenant;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StaffProfilesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $company;

    private User $cleaner;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
        $this->company = Tenant::create(['name' => 'Profiles company', 'slug' => 'profiles', 'database_name' => 'unused_profiles', 'active' => true]);
        $this->cleaner = $this->member('cleaner');
        $this->admin = $this->member('admin');
        app()->instance('currentTenant', $this->company);
        $this->withoutMiddleware(ResolveTenant::class)->actingAs($this->cleaner);
    }

    private function member(string $role, ?Tenant $company = null): User
    {
        $user = User::factory()->create(['active' => true]);
        $user->tenants()->attach($company ?? $this->company, ['roles' => json_encode([$role]), 'legacy_user_id' => $user->id + 10]);

        return $user;
    }

    private function payload(array $overrides = []): array
    {
        return array_replace([
            'version' => 0, 'legal_name' => 'Official Staff Name', 'phone' => '+254720602805',
            'email' => 'staff-contact@example.test', 'date_of_birth' => '1995-01-10',
            'address' => 'Nairobi', 'emergency_contact_name' => 'Emergency Contact',
            'emergency_contact_phone' => '+254700000001', 'document_type' => 'national_id',
            'identity_document' => UploadedFile::fake()->image('identity.png', 10, 10),
        ], $overrides);
    }

    private function saveProfile(): object
    {
        $this->post('/t/profiles/my-profile', $this->payload())->assertSessionHasNoErrors()->assertRedirect('/t/profiles/my-profile');

        return DB::table('staff_profiles')->where('tenant_id', $this->company->id)->where('user_id', $this->cleaner->id)->first();
    }

    public static function staffRoles(): array
    {
        return [['superadmin'], ['admin'], ['manager'], ['operations'], ['cleaner'], ['sales'], ['user']];
    }

    #[DataProvider('staffRoles')]
    public function test_all_staff_roles_can_open_their_own_profile_even_without_team_module(string $role): void
    {
        $this->company->modules = ['team' => false];
        $this->company->save();
        $this->actingAs($this->member($role))->get('/t/profiles/my-profile')->assertOk()->assertSee('My staff profile')
            ->assertSee('Upload identification document');
    }

    public function test_self_service_upload_is_private_and_does_not_change_login_details(): void
    {
        $loginName = $this->cleaner->name;
        $loginEmail = $this->cleaner->email;
        $this->post('/t/profiles/my-profile', $this->payload([
            'user_id' => $this->admin->id, 'tenant_id' => 999, 'roles' => 'superadmin',
        ]))->assertSessionHasNoErrors();
        $profile = DB::table('staff_profiles')->first();
        $this->assertSame($this->cleaner->id, $profile->user_id);
        $this->assertSame($this->company->id, $profile->tenant_id);
        $this->assertSame('Official Staff Name', $profile->legal_name);
        $this->assertSame($loginName, $this->cleaner->fresh()->name);
        $this->assertSame($loginEmail, $this->cleaner->fresh()->email);
        $this->assertSame(['cleaner'], $this->cleaner->roles());
        Storage::disk('local')->assertExists($profile->document_path);
        Storage::disk('public')->assertMissing($profile->document_path);
        $this->assertStringStartsWith('staff-profiles/'.$this->company->id.'/'.$this->cleaner->id.'/', $profile->document_path);
        $this->get('/t/profiles/my-profile/document')->assertOk()->assertDownload('staff-identification-'.$profile->id.'.png')
            ->assertHeader('x-content-type-options', 'nosniff');
        $this->get('/storage/'.$profile->document_path)->assertForbidden();
    }

    public function test_profile_edits_keep_or_replace_the_document_with_safe_cleanup(): void
    {
        $profile = $this->saveProfile();
        $this->post('/t/profiles/my-profile', $this->payload(['version' => 1, 'phone' => '+254700000002', 'identity_document' => null]))
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('staff_profiles', ['id' => $profile->id, 'version' => 2, 'document_path' => $profile->document_path]);
        $this->post('/t/profiles/my-profile', $this->payload(['version' => 2, 'identity_document' => UploadedFile::fake()->image('replacement.png', 10, 10)]))
            ->assertSessionHasNoErrors();
        $replacement = DB::table('staff_profiles')->first();
        $this->assertNotSame($profile->document_path, $replacement->document_path);
        Storage::disk('local')->assertMissing($profile->document_path);
        Storage::disk('local')->assertExists($replacement->document_path);
        $this->assertCount(1, Storage::disk('local')->allFiles());
        $this->assertSame(3, $replacement->version);
    }

    public function test_stale_updates_and_changed_document_types_do_not_overwrite_or_leave_uploads(): void
    {
        $profile = $this->saveProfile();
        $this->post('/t/profiles/my-profile', $this->payload(['version' => 0, 'legal_name' => 'Stale overwrite']))
            ->assertSessionHasErrors('profile');
        $this->assertDatabaseHas('staff_profiles', ['id' => $profile->id, 'legal_name' => 'Official Staff Name', 'version' => 1]);
        $this->assertCount(1, Storage::disk('local')->allFiles());
        $this->post('/t/profiles/my-profile', $this->payload(['version' => 1, 'document_type' => 'passport', 'identity_document' => null]))
            ->assertSessionHasErrors('identity_document');
        $this->assertDatabaseHas('staff_profiles', ['id' => $profile->id, 'document_type' => 'national_id']);
    }

    public function test_cleanup_failure_does_not_make_a_saved_replacement_look_unsuccessful(): void
    {
        $profile = $this->saveProfile();
        $disk = Storage::disk('local');
        $failingCleanup = Mockery::mock($disk);
        $failingCleanup->shouldReceive('delete')->with($profile->document_path)->once()
            ->andThrow(new \RuntimeException('Simulated private document cleanup failure'));
        Storage::set('local', $failingCleanup);

        $this->post('/t/profiles/my-profile', $this->payload(['version' => 1]))
            ->assertRedirect('/t/profiles/my-profile')->assertSessionHasNoErrors()->assertSessionHas('success');
        $updated = DB::table('staff_profiles')->first();
        $this->assertSame(2, $updated->version);
        $this->assertNotSame($profile->document_path, $updated->document_path);
        $this->assertTrue($disk->exists($updated->document_path));
    }

    public function test_first_submission_requires_a_valid_document_and_biodata(): void
    {
        $this->postJson('/t/profiles/my-profile', $this->payload(['identity_document' => null, 'phone' => '', 'date_of_birth' => '2099-01-01']))
            ->assertUnprocessable()->assertJsonValidationErrors(['identity_document', 'phone', 'date_of_birth']);
        $this->post('/t/profiles/my-profile', $this->payload(['identity_document' => UploadedFile::fake()->create('bad.svg', 10, 'image/svg+xml')]))
            ->assertSessionHasErrors('identity_document');
        $this->post('/t/profiles/my-profile', $this->payload(['identity_document' => UploadedFile::fake()->image('large.png')->size(5121)]))
            ->assertSessionHasErrors('identity_document');
        $this->assertDatabaseCount('staff_profiles', 0);
        $this->assertCount(0, Storage::disk('local')->allFiles());
    }

    public function test_phone_validation_requires_digits_and_preserves_valid_display_formats(): void
    {
        foreach (['not-a-phone', '123456', '1234567890123456', '+254700000001 ext 4', '254+700000001'] as $invalid) {
            $this->post('/t/profiles/my-profile', $this->payload(['phone' => $invalid, 'emergency_contact_phone' => $invalid]))
                ->assertSessionHasErrors(['phone', 'emergency_contact_phone']);
        }
        $this->assertDatabaseCount('staff_profiles', 0);
        $this->assertCount(0, Storage::disk('local')->allFiles());

        $this->post('/t/profiles/my-profile', $this->payload([
            'phone' => '0720 602-805', 'emergency_contact_phone' => '+44 (20) 7946-0958',
        ]))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('staff_profiles', [
            'phone' => '0720 602-805', 'emergency_contact_phone' => '+44 (20) 7946-0958',
        ]);
    }

    public function test_only_company_administrators_can_review_other_staff_profiles(): void
    {
        $profile = $this->saveProfile();
        $this->get('/t/profiles/staff-profiles')->assertForbidden();
        $this->get('/t/profiles/staff-profiles/'.$profile->id)->assertForbidden();
        $this->get('/t/profiles/staff-profiles/'.$profile->id.'/document')->assertForbidden();
        $colleague = $this->member('manager');
        $this->actingAs($colleague)->get('/t/profiles/my-profile/document')->assertNotFound();
        $this->get('/t/profiles/staff-profiles/'.$profile->id.'/document')->assertForbidden();
        $this->actingAs($this->admin)->get('/t/profiles/staff-profiles')->assertOk()->assertSee('Official Staff Name');
        $this->get('/t/profiles/staff-profiles/'.$profile->id)->assertOk()->assertSee('Emergency Contact');
        $response = $this->get('/t/profiles/staff-profiles/'.$profile->id.'/document')->assertOk();
        $this->assertStringContainsString('no-store', $response->headers->get('cache-control'));
    }

    public function test_profiles_and_documents_are_isolated_between_companies(): void
    {
        $profile = $this->saveProfile();
        $other = Tenant::create(['name' => 'Other profile company', 'slug' => 'other-profiles', 'database_name' => 'unused_other_profiles', 'active' => true]);
        $otherAdmin = $this->member('admin', $other);
        $this->cleaner->tenants()->attach($other, ['roles' => json_encode(['cleaner']), 'legacy_user_id' => 99]);
        app()->instance('currentTenant', $other);
        $this->actingAs($this->cleaner)->get('/t/other-profiles/my-profile/document')->assertNotFound();
        $this->actingAs($otherAdmin)->get('/t/other-profiles/staff-profiles')->assertOk()->assertDontSee('Official Staff Name');
        $this->get('/t/other-profiles/staff-profiles/'.$profile->id)->assertNotFound();
        $this->get('/t/other-profiles/staff-profiles/'.$profile->id.'/document')->assertNotFound();
    }

    public function test_owner_nonmember_and_inactive_accounts_cannot_access_staff_data(): void
    {
        $this->actingAs($this->member('owner'))->get('/t/profiles/my-profile')->assertForbidden();
        $this->post('/t/profiles/my-profile', $this->payload())->assertForbidden();
        $this->actingAs(User::factory()->create(['active' => true]))->get('/t/profiles/my-profile')->assertForbidden();
        $this->cleaner->active = false;
        $this->cleaner->save();
        $this->actingAs($this->cleaner)->get('/t/profiles/my-profile')->assertForbidden();
        $this->post('/t/profiles/my-profile', $this->payload())->assertForbidden();

        $this->assertDatabaseCount('staff_profiles', 0);
    }
}
