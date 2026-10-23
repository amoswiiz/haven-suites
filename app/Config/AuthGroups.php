<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Shield\Config\AuthGroups as ShieldAuthGroups;

class AuthGroups extends ShieldAuthGroups
{
    /**
     * -------------------------------------------------------------
     * DEFAULT GROUP
     * -------------------------------------------------------------
     *
     * New accounts receive the least privileged role by default.
     * Public registration should remain disabled in Auth.php.
     */
    public string $defaultGroup = 'user';

    /**
     * -------------------------------------------------------------
     * HAVEN SUITES USER GROUPS
     * -------------------------------------------------------------
     */
    public array $groups = [

        'superadmin' => [
            'title'       => 'Super Administrator',
            'description' => 'Full control of Haven Suites, including accounts, permissions and settings.',
        ],

        'admin' => [
            'title'       => 'Administrator',
            'description' => 'Administrative access to daily management and staff operations.',
        ],

        'manager' => [
            'title'       => 'Manager',
            'description' => 'Manages properties, reservations, staff and operational tasks.',
        ],

        'operations' => [
            'title'       => 'Operations Staff',
            'description' => 'Coordinates reservations, maintenance and housekeeping.',
        ],

        'cleaner' => [
            'title'       => 'Cleaner',
            'description' => 'Handles assigned housekeeping tasks.',
        ],

        'owner' => [
            'title'       => 'Property Owner',
            'description' => 'Limited access to their own property reports.',
        ],

        'user' => [
            'title'       => 'Standard User',
            'description' => 'Default account with no management permissions.',
        ],

    ];

    /**
     * -------------------------------------------------------------
     * HAVEN SUITES PERMISSIONS
     * -------------------------------------------------------------
     */
    public array $permissions = [

        // Dashboard
        'dashboard.view' => 'View management dashboard',

        // Properties
        'properties.view'   => 'View properties',
        'properties.manage' => 'Create and update properties',

        // Units
        'units.view'   => 'View apartment units',
        'units.manage' => 'Create and update apartment units',

        // Reservations
        'reservations.view'   => 'View reservations',
        'reservations.manage' => 'Create and update reservations',

        // Availability
        'availability.view' => 'View availability calendar',

        // Staff
        'staff.view'   => 'View staff directory',
        'staff.manage' => 'Create and update staff records',

        // Maintenance
        'maintenance.view'   => 'View maintenance issues',
        'maintenance.manage' => 'Create and update maintenance issues',

        // Housekeeping
        'housekeeping.view'   => 'View housekeeping tasks',
        'housekeeping.manage' => 'Create and update housekeeping tasks',
        'housekeeping.verify' => 'Verify completed cleaning tasks',

        // Financial reports
        'finance.view'   => 'View financial information',
        'finance.manage' => 'Manage financial records',
        'reports.view'   => 'View management reports',

        // Accounts and settings
        'users.manage'    => 'Manage system user accounts',
        'settings.manage' => 'Manage system settings',

    ];

    /**
     * -------------------------------------------------------------
     * PERMISSIONS MATRIX
     * -------------------------------------------------------------
     *
     * Assigns permissions to each Haven Suites group.
     */
    public array $matrix = [

        // Complete system access.
        'superadmin' => [
            'dashboard.*',
            'properties.*',
            'units.*',
            'reservations.*',
            'availability.*',
            'staff.*',
            'maintenance.*',
            'housekeeping.*',
            'finance.*',
            'reports.*',
            'users.*',
            'settings.*',
        ],

        // Administrative operations.
        'admin' => [
            'dashboard.view',
            'properties.*',
            'units.*',
            'reservations.*',
            'availability.view',
            'staff.*',
            'maintenance.*',
            'housekeeping.*',
            'finance.*',
            'reports.view',
        ],

        // Property and operations management.
        'manager' => [
            'dashboard.view',
            'properties.view',
            'units.view',
            'reservations.*',
            'availability.view',
            'staff.view',
            'maintenance.*',
            'housekeeping.*',
            'finance.view',
            'reports.view',
        ],

        // Daily operations.
        'operations' => [
            'dashboard.view',
            'properties.view',
            'units.view',
            'reservations.*',
            'availability.view',
            'maintenance.*',
            'housekeeping.view',
            'housekeeping.manage',
        ],

        // Housekeeping team.
        'cleaner' => [
            'housekeeping.view',
            'housekeeping.manage',
        ],

        // Property investors and owners.
        'owner' => [
            'reports.view',
        ],

        // No management permissions.
        'user' => [],

    ];
}
