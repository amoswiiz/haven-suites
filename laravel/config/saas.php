<?php

return [
    'trial_days' => 14,
    'plans' => [
        'starter' => ['name' => 'Starter', 'units' => 3, 'members' => 3],
        'team' => ['name' => 'Team', 'units' => 15, 'members' => 10],
        'scale' => ['name' => 'Scale', 'units' => 100, 'members' => 50],
    ],
    'invitable_roles' => ['admin', 'manager', 'operations', 'cleaner', 'owner', 'user'],
];
