<?php

namespace App\Services;

use App\Models\CompanyInvitation;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDO;
use RuntimeException;

class TenantProvisioner
{
    public function provision(?User $user, string $name, string $slug, array $options = []): Tenant
    {
        if (! preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $slug)) {
            throw new RuntimeException('Invalid company address.');
        }
        $database = 'haven_tenant_'.strtolower(Str::random(20));
        $schema = file_get_contents(database_path('schema/tenant.mysql.sql'));
        $config = config('database.connections.tenant');
        $server = new PDO('mysql:host='.$config['host'].';port='.$config['port'].';charset=utf8mb4', $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $server->exec('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        try {
            $server->exec('USE `'.$database.'`');
            $server->exec('SET FOREIGN_KEY_CHECKS=0');
            $server->exec($schema);
            $server->exec('SET FOREIGN_KEY_CHECKS=1');

            return DB::transaction(function () use ($user, $name, $slug, $database, $server, $options): Tenant {
                if ($user) {
                    User::whereKey($user->id)->lockForUpdate()->firstOrFail();
                }
                if ($user && $user->tenants()->exists()) {
                    throw new RuntimeException('This account already has a company. Use an invitation to join another company.');
                }
                $tenant = Tenant::create([
                    'name' => $name, 'slug' => $slug, 'database_name' => $database, 'active' => true,
                    'contact_email' => $options['contact_email'] ?? $user?->email, 'contact_phone' => $options['contact_phone'] ?? null, 'plan' => $options['plan'] ?? 'starter', 'subscription_status' => 'trial',
                    'trial_ends_at' => now()->addDays(config('saas.trial_days')),
                ]);
                if ($user) {
                    $insert = $server->prepare('INSERT INTO users (username,active,created_at,updated_at) VALUES (?,1,NOW(),NOW())');
                    $insert->execute([$user->username]);
                    $legacyId = (int) $server->lastInsertId();
                    $user->tenants()->attach($tenant, ['roles' => json_encode(['superadmin']), 'legacy_user_id' => $legacyId]);
                    $insert = $server->prepare('INSERT INTO staff (user_id,full_name,phone,email,role,location,status,created_at,updated_at) VALUES (?,?,?,?,?,?,?,NOW(),NOW())');
                    $insert->execute([$legacyId, $user->name, '', $user->email, 'superadmin', '', 'active']);
                }
                $insert = $server->prepare('INSERT INTO invoice_settings (id,invoice_prefix,next_sequence,business_name,business_email,updated_at) VALUES (1,?,1,?,?,NOW())');
                $insert->execute(['INV-'.substr(strtoupper($slug), 0, 20), $name, $tenant->contact_email]);
                if (! $user) {
                    $tenant->forceFill(['modules' => $options['modules']])->save();
                    CompanyInvitation::create(['tenant_id' => $tenant->id, 'email' => $options['admin_email'], 'role' => 'admin', 'invited_by' => $options['invited_by'], 'token_hash' => $options['token_hash'], 'expires_at' => now()->addDays(7)]);
                    DB::table('platform_audit_events')->insert(['tenant_id' => $tenant->id, 'actor_id' => $options['invited_by'], 'action' => 'company.created', 'details' => json_encode(['plan' => $tenant->plan, 'modules' => $tenant->modules, 'admin_email' => $options['admin_email']]), 'created_at' => now(), 'updated_at' => now()]);
                }
                foreach (['Cleaning', 'Maintenance', 'Utilities', 'Rent', 'Other'] as $category) {
                    $insert = $server->prepare('INSERT INTO expense_categories (name,created_at,updated_at) VALUES (?,NOW(),NOW())');
                    $insert->execute([$category]);
                }

                return $tenant;
            });
        } catch (\Throwable $exception) {
            // This random database was created by this call and never published on failure.
            $server->exec('DROP DATABASE `'.$database.'`');
            throw $exception;
        }
    }
}
