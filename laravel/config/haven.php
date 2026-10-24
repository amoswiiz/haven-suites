<?php

return ['roles' => [

    // Complete system access.
    'superadmin' => [
        'investors.*', 'notifications.*',
        'sales.*', 'agents.*', 'messaging.*',
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
        'investors.*', 'notifications.*',
        'sales.*', 'agents.*', 'messaging.*',
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
        'investors.view', 'notifications.view',
        'sales.*', 'agents.*', 'messaging.*',
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
        'sales.*', 'agents.*', 'messaging.*',
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
        'investor.portal',
        'reports.view',
    ],

    'sales' => ['sales.*', 'agents.view', 'messaging.*', 'availability.view'],

    // No management permissions.
    'user' => [],

]];
