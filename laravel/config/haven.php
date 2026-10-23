<?php
return ['roles' => [

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

    ]];
