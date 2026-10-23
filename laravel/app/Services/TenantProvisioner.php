<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDO;
use RuntimeException;

class TenantProvisioner
{
    public function provision(User $user, string $name, string $slug): Tenant
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

            return DB::transaction(function () use ($user, $name, $slug, $database, $server): Tenant {
                User::whereKey($user->id)->lockForUpdate()->firstOrFail();
                if ($user->tenants()->exists()) {
                    throw new RuntimeException('This account already has a company. Use an invitation to join another company.');
                }
                $tenant = Tenant::create([
                    'name' => $name, 'slug' => $slug, 'database_name' => $database, 'active' => true,
                    'contact_email' => $user->email, 'plan' => 'starter', 'subscription_status' => 'trial',
                    'trial_ends_at' => now()->addDays(config('saas.trial_days')),
                ]);
                $insert = $server->prepare('INSERT INTO users (username,active,created_at,updated_at) VALUES (?,1,NOW(),NOW())');
                $insert->execute([$user->username]);
                $legacyId = (int) $server->lastInsertId();
                $user->tenants()->attach($tenant, ['roles' => json_encode(['superadmin']), 'legacy_user_id' => $legacyId]);
                $insert = $server->prepare('INSERT INTO staff (user_id,full_name,phone,email,role,location,status,created_at,updated_at) VALUES (?,?,?,?,?,?,?,NOW(),NOW())');
                $insert->execute([$legacyId, $user->name, '', $user->email, 'superadmin', '', 'active']);
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
