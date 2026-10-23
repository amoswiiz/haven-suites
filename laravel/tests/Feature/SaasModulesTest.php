<?php

namespace Tests\Feature;

use App\Models\CompanyInvitation;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\CompanyInvitationNotification;
use App\Services\TenantProvisioner;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class SaasModulesTest extends TestCase
{
    use RefreshDatabase;

    private array $databases = [];

    protected function tearDown(): void
    {
        DB::purge('tenant');
        $config = config('database.connections.tenant');
        $pdo = new \PDO('mysql:host='.$config['host'].';port='.$config['port'], $config['username'], $config['password']);
        foreach ($this->databases as $database) {
            if (preg_match('/^haven_tenant_[a-z0-9]{20}$/D', $database)) {
                $pdo->exec('DROP DATABASE `'.$database.'`');
            }
        }
        parent::tearDown();
    }

    private function company(User $user): Tenant
    {
        $tenant = app(TenantProvisioner::class)->provision($user, 'Test host business', 'host-'.bin2hex(random_bytes(5)));
        $this->databases[] = $tenant->database_name;

        return $tenant;
    }

    private function operational(Tenant $tenant): Connection
    {
        config(['database.connections.tenant.database' => $tenant->database_name]);
        DB::purge('tenant');

        return DB::connection('tenant');
    }

    public function test_registration_requires_verification_and_cannot_grant_platform_access(): void
    {
        Notification::fake();
        $this->post('/register', ['name' => 'Independent Host', 'email' => 'host@example.test', 'password' => 'Hosting!Password123', 'password_confirmation' => 'Hosting!Password123', 'is_platform_admin' => true])->assertRedirect('/verify-email');
        $user = User::where('email', 'host@example.test')->firstOrFail();
        $this->assertTrue($user->requires_email_verification);
        $this->assertFalse($user->is_platform_admin);
        $this->assertFalse($user->tenants()->exists());
        Notification::assertSentTo($user, VerifyEmail::class);
        $this->get('/onboarding')->assertRedirect('/verify-email');
        $link = URL::temporarySignedRoute('verification.verify', now()->addMinutes(30), ['id' => $user->id, 'hash' => sha1($user->email)]);
        $this->get($link)->assertRedirect('/onboarding');
        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_verified_host_can_create_company_apartment_and_booking(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post('/onboarding', ['name' => 'New host company', 'slug' => 'new-host-company'])->assertRedirect('/t/new-host-company/onboarding');
        $tenant = Tenant::where('slug', 'new-host-company')->firstOrFail();
        $this->databases[] = $tenant->database_name;
        $this->assertSame('trial', $tenant->subscription_status);
        $this->post('/t/'.$tenant->slug.'/onboarding', ['property_name' => 'New Property', 'location' => 'Nairobi', 'unit_number' => 'A1', 'bedrooms' => 1, 'bathrooms' => 1, 'nightly_rate' => '4500'])->assertRedirect('/t/'.$tenant->slug.'/dashboard');
        $db = $this->operational($tenant);
        $unit = $db->table('units')->value('id');
        $this->assertSame(1, $db->table('properties')->count());
        $this->post('/t/'.$tenant->slug.'/reservations/store', ['booking_type' => 'managed', 'unit_id' => $unit, 'guest_name' => 'First Guest', 'guest_phone' => '0700000000', 'guest_email' => '', 'check_in' => '2036-01-10', 'check_out' => '2036-01-12', 'adults' => 1, 'children' => 0, 'nightly_rate' => 4500, 'amount_paid' => 0, 'status' => 'confirmed'])->assertSessionHas('success');
        $this->assertEquals(9000, $this->operational($tenant)->table('reservations')->value('total_amount'));
    }

    public function test_company_settings_are_scoped_and_forbid_managers(): void
    {
        $owner = User::factory()->create();
        $tenant = $this->company($owner);
        $this->actingAs($owner)->post('/t/'.$tenant->slug.'/company/settings', ['name' => 'Renamed host', 'contact_email' => 'office@example.test', 'contact_phone' => '0700000000'])->assertSessionHas('success');
        $this->assertSame('Renamed host', $tenant->fresh()->name);
        $manager = User::factory()->create();
        $manager->tenants()->attach($tenant, ['roles' => json_encode(['manager']), 'legacy_user_id' => 999]);
        $this->actingAs($manager)->get('/t/'.$tenant->slug.'/company/settings')->assertForbidden();
        $this->actingAs(User::factory()->create())->get('/t/'.$tenant->slug.'/company/settings')->assertForbidden();
    }

    public function test_invitation_requires_matching_email_and_creates_local_staff_once(): void
    {
        $owner = User::factory()->create();
        $tenant = $this->company($owner);
        $invitee = User::factory()->create();
        $token = bin2hex(random_bytes(32));
        $invitation = CompanyInvitation::factory()->create(['tenant_id' => $tenant->id, 'invited_by' => $owner->id, 'email' => $invitee->email, 'token_hash' => hash('sha256', $token), 'role' => 'cleaner']);
        $this->actingAs($owner)->post('/invitations/'.$token)->assertForbidden();
        $this->actingAs($invitee)->post('/invitations/'.$token)->assertRedirect('/t/'.$tenant->slug.'/home');
        $this->assertNotNull($invitation->fresh()->accepted_at);
        $this->assertSame(2, $this->operational($tenant)->table('staff')->count());
        $this->post('/invitations/'.$token)->assertStatus(410);
    }

    public function test_expired_and_revoked_invitations_cannot_be_accepted(): void
    {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->create();
        foreach (['expires_at' => now()->subDay(), 'revoked_at' => now()] as $field => $value) {
            $token = bin2hex(random_bytes(32));
            CompanyInvitation::factory()->create(['tenant_id' => $tenant->id, 'email' => $user->email, 'token_hash' => hash('sha256', $token), $field => $value]);
            $this->actingAs($user)->post('/invitations/'.$token)->assertStatus(410);
        }
    }

    public function test_team_invitations_are_emailed_without_exposing_tokens_in_storage(): void
    {
        Notification::fake();
        $owner = User::factory()->create();
        $tenant = $this->company($owner);
        $this->actingAs($owner)->post('/t/'.$tenant->slug.'/company/team/invitations', ['email' => 'cleaner@example.test', 'role' => 'cleaner'])->assertSessionHas('success');
        Notification::assertSentOnDemand(CompanyInvitationNotification::class);
        $this->assertSame(64, strlen(CompanyInvitation::firstOrFail()->token_hash));
        $this->post('/t/'.$tenant->slug.'/company/team/invitations', ['email' => 'bad@example.test', 'role' => 'superadmin'])->assertSessionHasErrors('role');
    }

    public function test_trial_expiry_and_plan_limits_block_writes_not_read_access(): void
    {
        $owner = User::factory()->create();
        $tenant = $this->company($owner);
        $tenant->update(['trial_ends_at' => now()->subDay()]);
        $this->actingAs($owner)->get('/t/'.$tenant->slug.'/reservations')->assertOk();
        $this->post('/t/'.$tenant->slug.'/units/store', [])->assertSessionHasErrors('subscription');
        $this->post('/t/'.$tenant->slug.'/company/subscription/request', ['plan' => 'scale'])->assertSessionHas('success');
        $this->assertSame('starter', $tenant->fresh()->plan);
        $tenant->update(['trial_ends_at' => now()->addDay()]);
        config(['saas.plans.starter.units' => 0]);
        $this->post('/t/'.$tenant->slug.'/units/store', [])->assertSessionHasErrors('plan');
    }

    public function test_company_administrator_is_not_a_platform_administrator(): void
    {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->create();
        $user->tenants()->attach($tenant, ['roles' => json_encode(['superadmin']), 'legacy_user_id' => 1]);
        $this->actingAs($user)->get('/platform')->assertForbidden();
        $user->forceFill(['is_platform_admin' => true])->save();
        $this->get('/platform')->assertOk();
        $this->post('/platform/companies/'.$tenant->id, ['active' => 0, 'plan' => 'starter', 'subscription_status' => 'expired'])->assertSessionHas('success');
        $this->assertFalse($tenant->fresh()->active);
        $this->assertDatabaseHas('platform_audit_events', ['actor_id' => $user->id, 'tenant_id' => $tenant->id]);
    }

    public function test_password_reset_token_changes_password_and_cannot_be_reused(): void
    {
        $user = User::factory()->create();
        $token = Password::createToken($user);
        $body = ['email' => $user->email, 'token' => $token, 'password' => 'NewHosting!Password123', 'password_confirmation' => 'NewHosting!Password123'];
        $this->post('/reset-password', $body)->assertRedirect('/login');
        $this->assertTrue(Hash::check($body['password'], $user->fresh()->password));
        $this->post('/reset-password', $body)->assertSessionHasErrors('email');
    }

    public function test_team_roles_and_revocation_do_not_change_other_company_memberships(): void
    {
        $owner = User::factory()->create();
        $tenant = $this->company($owner);
        $member = User::factory()->create();
        $token = bin2hex(random_bytes(32));
        CompanyInvitation::factory()->create(['tenant_id' => $tenant->id, 'invited_by' => $owner->id, 'email' => $member->email, 'token_hash' => hash('sha256', $token)]);
        $this->actingAs($member)->post('/invitations/'.$token)->assertRedirect();
        $other = Tenant::factory()->create();
        $member->tenants()->attach($other, ['roles' => json_encode(['manager']), 'legacy_user_id' => 1]);
        $this->actingAs($owner)->post('/t/'.$tenant->slug.'/company/team/members/'.$member->id, ['role' => 'cleaner'])->assertSessionHas('success');
        $this->assertSame('cleaner', $this->operational($tenant)->table('staff')->where('email', $member->email)->value('role'));
        $this->post('/t/'.$tenant->slug.'/company/team/members/'.$member->id.'/remove')->assertSessionHas('success');
        $this->assertFalse($member->tenants()->whereKey($tenant->id)->exists());
        $this->assertTrue($member->tenants()->whereKey($other->id)->exists());
        $this->actingAs($member)->get('/t/'.$tenant->slug.'/reservations')->assertForbidden();
    }

    public function test_invitation_revoke_checks_company_and_cannot_cross_tenants(): void
    {
        $owner = User::factory()->create();
        $tenant = $this->company($owner);
        $invitation = CompanyInvitation::factory()->create(['tenant_id' => $tenant->id, 'invited_by' => $owner->id]);
        $this->actingAs($owner)->post('/t/'.$tenant->slug.'/company/team/invitations/'.$invitation->id.'/revoke')->assertSessionHas('success');
        $this->assertNotNull($invitation->fresh()->revoked_at);
        $other = CompanyInvitation::factory()->create();
        $this->post('/t/'.$tenant->slug.'/company/team/invitations/'.$other->id.'/revoke')->assertNotFound();
    }
}
