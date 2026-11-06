<?php

namespace Tests\Feature;

use App\Http\Middleware\ImpersonateUser;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PasswordChangeTest extends TestCase
{
    use RefreshDatabase;

    private const CURRENT = 'Current!Password123';

    private const NEW_PASSWORD = 'MyNew!Password456';

    private function account(array $overrides = []): User
    {
        return User::factory()->create(array_replace(['password' => self::CURRENT, 'active' => true, 'remember_token' => 'previous-remember-token'], $overrides));
    }

    private function payload(array $overrides = []): array
    {
        return array_replace(['current_password' => self::CURRENT, 'password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD], $overrides);
    }

    public function test_guests_must_sign_in_before_viewing_or_changing_a_password(): void
    {
        $this->get(route('account.password.edit'))->assertRedirect('/login');
        $this->put(route('account.password.update'), $this->payload())->assertRedirect('/login');
    }

    public function test_an_account_without_company_membership_can_access_its_password_settings(): void
    {
        $user = $this->account(['email_verified_at' => null, 'requires_email_verification' => true]);
        $this->actingAs($user)->get(route('account.password.edit'))->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertSee('Change your password')->assertSee('autocomplete="current-password"', false)
            ->assertSee('autocomplete="new-password"', false)->assertSee('at least 12 characters');
        $this->put(route('account.password.update'), $this->payload())->assertRedirect(route('account.password.edit'))->assertSessionHas('success');
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $user->fresh()->password));
    }

    public function test_password_change_hashes_the_new_password_and_keeps_the_current_account_signed_in(): void
    {
        $user = $this->account();
        $other = $this->account();
        $otherHash = $other->password;
        $this->actingAs($user)->withSession(['password-test-marker' => 'kept']);
        $sessionId = session()->getId();

        $this->put(route('account.password.update'), $this->payload(['user_id' => $other->id]))
            ->assertRedirect(route('account.password.edit'))->assertSessionHas('success')
            ->assertSessionHas('password-test-marker', 'kept');

        $user->refresh();
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $user->password));
        $this->assertFalse(Hash::check(self::CURRENT, $user->password));
        $this->assertNotSame(self::NEW_PASSWORD, $user->password);
        $this->assertNotSame('previous-remember-token', $user->remember_token);
        $this->assertNotSame($sessionId, session()->getId());
        $this->assertAuthenticatedAs($user);
        $this->assertSame($otherHash, $other->fresh()->password);
        $this->get(route('account.password.edit'))->assertOk();
    }

    public function test_wrong_current_password_does_not_change_the_account_or_flash_passwords(): void
    {
        $user = $this->account();
        $oldHash = $user->password;
        $this->actingAs($user)->from(route('account.password.edit'))->put(route('account.password.update'), $this->payload(['current_password' => 'Incorrect!Password123']))
            ->assertRedirect(route('account.password.edit'))->assertSessionHasErrors('current_password');
        $this->assertSame($oldHash, $user->fresh()->password);
        $this->assertSame('previous-remember-token', $user->fresh()->remember_token);
        foreach (['current_password', 'password', 'password_confirmation'] as $field) {
            $this->assertArrayNotHasKey($field, session()->getOldInput());
        }
    }

    #[DataProvider('invalidNewPasswords')]
    public function test_new_password_policy_and_confirmation_are_enforced(string $password, string $confirmation): void
    {
        $user = $this->account();
        $oldHash = $user->password;
        $this->actingAs($user)->put(route('account.password.update'), $this->payload(['password' => $password, 'password_confirmation' => $confirmation]))
            ->assertSessionHasErrors('password');
        $this->assertSame($oldHash, $user->fresh()->password);
        $this->assertAuthenticatedAs($user);
    }

    public static function invalidNewPasswords(): array
    {
        return [
            'too short' => ['Short!123', 'Short!123'],
            'no uppercase' => ['lowercaseonly123', 'lowercaseonly123'],
            'no lowercase' => ['UPPERCASEONLY123', 'UPPERCASEONLY123'],
            'no number' => ['LettersOnlyPassword', 'LettersOnlyPassword'],
            'mismatch' => [self::NEW_PASSWORD, 'Different!Password789'],
            'same as current' => [self::CURRENT, self::CURRENT],
            'too long' => [str_repeat('a', 120).'Long!Pass123', str_repeat('a', 120).'Long!Pass123'],
            'bcrypt long ASCII' => [str_repeat('a', 65).'UpperPass1234', str_repeat('a', 65).'UpperPass1234'],
            'bcrypt multibyte length' => ['UnicodePass123'.str_repeat('🙂', 16), 'UnicodePass123'.str_repeat('🙂', 16)],
        ];
    }

    #[DataProvider('accountRoles')]
    public function test_company_roles_can_reach_password_settings_without_team_management_permissions(string $role): void
    {
        $user = $this->account();
        $company = Tenant::create(['name' => 'Account test', 'slug' => 'account-test', 'database_name' => 'unused', 'active' => true]);
        $user->tenants()->attach($company, ['roles' => json_encode([$role]), 'legacy_user_id' => 22]);
        app()->instance('currentTenant', $company);
        $this->actingAs($user)->get(route('account.password.edit'))->assertOk()->assertSee(route('account.password.edit'), false);
        $this->put(route('account.password.update'), $this->payload())->assertRedirect(route('account.password.edit'))->assertSessionHas('success');
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $user->fresh()->password));
    }

    public static function accountRoles(): array
    {
        return array_map(static fn (string $role): array => [$role], ['cleaner', 'owner', 'user', 'sales', 'manager']);
    }

    public function test_inactive_accounts_cannot_use_password_settings(): void
    {
        $user = $this->account(['active' => false]);
        $oldHash = $user->password;
        $this->actingAs($user)->get(route('account.password.edit'))->assertForbidden();
        $this->put(route('account.password.update'), $this->payload())->assertForbidden();
        $this->assertSame($oldHash, $user->fresh()->password);
    }

    public function test_impersonating_another_user_blocks_password_endpoints(): void
    {
        $actor = $this->account();
        $actor->forceFill(['is_platform_admin' => true])->save();
        $target = $this->account();
        $company = Tenant::create(['name' => 'Account test', 'slug' => 'account-test', 'database_name' => 'unused', 'active' => true]);
        $target->tenants()->attach($company, ['roles' => json_encode(['cleaner']), 'legacy_user_id' => 22]);
        $state = ['actor_id' => $actor->id, 'target_id' => $target->id, 'tenant_id' => $company->id, 'started_at' => now()->toDateTimeString()];
        $oldHash = $actor->password;
        $this->actingAs($actor)->withSession(['haven.impersonation' => $state]);
        $this->get(route('account.password.edit'))->assertForbidden();
        $this->put(route('account.password.update'), $this->payload())->assertForbidden();
        $this->assertSame($oldHash, $actor->fresh()->password);
        $this->assertTrue(Hash::check(self::CURRENT, $target->fresh()->password));
    }

    public function test_controller_also_rejects_an_impersonation_session_if_middleware_is_bypassed(): void
    {
        $user = $this->account();
        $oldHash = $user->password;
        $this->withoutMiddleware(ImpersonateUser::class)->actingAs($user)->withSession(['haven.impersonation' => ['actor_id' => $user->id]]);
        $this->get(route('account.password.edit'))->assertForbidden();
        $this->put(route('account.password.update'), $this->payload())->assertForbidden();
        $this->assertSame($oldHash, $user->fresh()->password);
    }

    public function test_database_sessions_for_other_devices_are_revoked_without_affecting_another_user(): void
    {
        config(['session.driver' => 'database']);
        app('session')->forgetDrivers();
        $user = $this->account();
        $other = $this->account();
        DB::table('sessions')->insert([
            ['id' => 'old-device-session', 'user_id' => $user->id, 'payload' => base64_encode(serialize([])), 'last_activity' => now()->timestamp],
            ['id' => 'other-user-session', 'user_id' => $other->id, 'payload' => base64_encode(serialize([])), 'last_activity' => now()->timestamp],
        ]);

        $this->actingAs($user)->put(route('account.password.update'), $this->payload())->assertRedirect(route('account.password.edit'));

        $this->assertDatabaseMissing('sessions', ['id' => 'old-device-session']);
        $this->assertDatabaseHas('sessions', ['id' => 'other-user-session']);
        $this->assertAuthenticatedAs($user);
        $this->get(route('account.password.edit'))->assertOk();
    }

    public function test_a_password_change_invalidates_outstanding_reset_links_for_only_this_account(): void
    {
        $user = $this->account();
        $other = $this->account();
        $table = config('auth.passwords.'.config('auth.defaults.passwords').'.table');
        DB::table($table)->insert([
            ['email' => $user->email, 'token' => 'old-reset-token', 'created_at' => now()],
            ['email' => $other->email, 'token' => 'other-reset-token', 'created_at' => now()],
        ]);

        $this->actingAs($user)->put(route('account.password.update'), $this->payload())->assertSessionHas('success');

        $this->assertDatabaseMissing($table, ['email' => $user->email]);
        $this->assertDatabaseHas($table, ['email' => $other->email, 'token' => 'other-reset-token']);
    }
}
