<?php

return ['roles' => [

    // Complete system access.
    'superadmin' => ['invoices.*', 'tasks.*',
        'staff-profile.self', 'staff-profile.review',
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
    'admin' => ['invoices.*', 'staff.accounts.manage', 'tasks.*',
        'staff-profile.self', 'staff-profile.review',
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
    'manager' => ['invoices.view', 'invoices.manage', 'tasks.*',
        'staff-profile.self',
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
    'operations' => ['invoices.view', 'invoices.manage', 'tasks.view',
        'staff-profile.self',
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
    'cleaner' => ['bills.submit', 'tasks.view',
        'cleaner.dashboard',
        'staff-profile.self',
        'housekeeping.view',
        'housekeeping.manage',
        'maintenance.view',
        'maintenance.report',
    ],

    // Property investors and owners.
    'owner' => ['tasks.owner',
        'investor.portal',
        'reports.view',
    ],

    'sales' => ['staff-profile.self', 'tasks.view', 'sales.*', 'agents.view', 'messaging.*', 'availability.view'],

    // No management permissions.
    'user' => ['staff-profile.self', 'tasks.view'],

]];
