<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class PlatformAdminCommand extends Command
{
    protected $signature = 'haven:platform-admin {email} {--revoke} {--create-local}';

    protected $description = 'Grant or revoke platform access on an existing account; company roles are unchanged.';

    public function handle(): int
    {
        $email = strtolower($this->argument('email'));
        Validator::make(['email' => $email], ['email' => 'required|email'])->validate();
        $user = User::where('email', $email)->first();
        if (! $user && $this->option('create-local')) {
            if (! app()->environment('local', 'testing') || $this->option('revoke')) {
                throw new \RuntimeException('Local account creation is unavailable for this operation.');
            }
            $password = 'Hs!'.Str::random(20);
            $user = User::create(['name' => 'Platform Administrator', 'username' => 'platform_'.strtolower(Str::random(12)), 'email' => $email, 'password' => $password, 'active' => true]);
            $user->forceFill(['email_verified_at' => now()])->save();
            $this->line('Local login password: '.$password);
        }
        if (! $user) {
            $this->error('Account not found. Register it first, or use --create-local for local testing.');

            return self::FAILURE;
        }
        $user->forceFill(['is_platform_admin' => ! $this->option('revoke')])->save();
        $this->info('Platform access '.($user->is_platform_admin ? 'granted' : 'revoked').' for '.$user->email.'.');

        return self::SUCCESS;
    }
}
