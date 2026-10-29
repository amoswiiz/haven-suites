<?php

namespace App\Support;

class CompanyModules
{
    public const LABELS = ['tasks' => 'Task management', 'investors' => 'Investor portal', 'notifications' => 'Email and SMS notifications', 'sales' => 'Sales CRM', 'messaging' => 'Messaging', 'agents' => 'Agent management', 'reservations' => 'Reservations and quotations', 'properties' => 'Properties and availability', 'housekeeping' => 'Housekeeping', 'maintenance' => 'Maintenance', 'finance' => 'Finance', 'reports' => 'Reports', 'team' => 'Team management'];

    public static function forPath(string $path): ?string
    {
        $path = preg_replace('#^/?t/[^/]+/?#', '', parse_url($path, PHP_URL_PATH) ?? '');

        return match (explode('/', trim($path, '/'))[0]) {
            'tasks' => 'tasks', 'communications' => 'messaging',
            'sales' => str_starts_with($path, 'sales/agents') ? 'agents' : (str_starts_with($path, 'sales/connections') ? 'messaging' : 'sales'),
            'reservations', 'quotations', 'q', 'kyc' => 'reservations',
            'units', 'properties', 'availability', 'host-agreements' => 'properties',
            'investors', 'investor-portal' => 'investors',
            'investor-notifications' => 'notifications',
            'housekeeping' => 'housekeeping',
            'maintenance' => 'maintenance',
            'reports', 'earnings-dashboard' => 'reports',
            'accounting', 'invoices', 'receipts', 'payments', 'expenses', 'owner-remittances', 'owner-statement-closing', 'cleaner-payments', 'settings' => 'finance',
            'staff' => 'team',
            'company' => str_starts_with($path, 'company/team') ? 'team' : null,
            default => null,
        };
    }

    public static function enabled(string $module): bool
    {
        return app()->bound('currentTenant') && (app('currentTenant')->modules[$module] ?? true);
    }

    public static function allowsPath(string $path): bool
    {
        $module = self::forPath($path);
        if (str_contains($path, '/sales/') && ! self::enabled('sales')) {
            return false;
        }

        return $module === null || self::enabled($module);
    }
}
