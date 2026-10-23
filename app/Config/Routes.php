<?php

namespace Config;

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */

$routes = Services::routes();

if (file_exists(SYSTEMPATH . 'Config/Routes.php')) {
    require SYSTEMPATH . 'Config/Routes.php';
}

// Step 8 — Receipt route.
// Add INSIDE your existing authenticated routes group in app/Config/Routes.php.
// Do not add a second copy if this route already exists.
$routes->get('receipts/(:num)', 'Receipts::show/$1', ['filter' => 'permission:finance.view']);

// Link an existing customer to a draft or issued invoice.
// This modifies only invoices.customer_id, not billing details or allocations.
$routes->post('invoices/(:num)/customer', 'Invoices::linkCustomer/$1', [
    'filter' => ['session', 'permission:finance.manage'],
]);

// Haven Suites — Invoice Payment Allocation
// Add INSIDE your existing authenticated routes group.
// These actions handle financial records and require finance.manage.
$routes->get('invoices/(:num)/payments', 'InvoicePayments::index/$1', ['filter' => 'permission:finance.manage']);
$routes->post('invoices/(:num)/payments', 'InvoicePayments::allocate/$1', ['filter' => 'permission:finance.manage']);
// Record a NEW payment directly against an issued invoice (reservation or standalone).
// This endpoint will be implemented in InvoicePayments::record in the next step.
$routes->post('invoices/(:num)/payments/record', 'InvoicePayments::record/$1', [
    'filter' => ['session', 'permission:finance.manage'],
]);

// HAVEN SUITES — CREATE OR OPEN INVOICE FROM SUBMITTED KYC
// Add inside your existing authenticated ('session') route group.
// Keep the route protected: this action creates financial records.
// Use POST, not GET, because it can create an invoice draft.
$routes->post('staff/kyc/(:num)/invoice', 'Invoices::fromKyc/$1', [
    'filter' => 'permission:finance.manage',
]);

// HAVEN SUITES — STAFF KYC REVIEW ROUTES
// Add inside your EXISTING authenticated ('session') route group.
// Do NOT add to the public guest KYC routes.
// Keep this order: specific routes before the general ID route.

$routes->get('staff/kyc', 'KycReviews::index', [
    'filter' => 'permission:finance.view',
]);

$routes->get('staff/kyc/(:num)/document', 'KycReviews::document/$1', [
    'filter' => 'permission:finance.view',
]);

$routes->post('staff/kyc/(:num)/decide', 'KycReviews::decide/$1', [
    'filter' => 'permission:finance.manage',
]);

$routes->get('staff/kyc/(:num)', 'KycReviews::show/$1', [
    'filter' => 'permission:finance.view',
]);

// HAVEN SUITES — STAFF KYC INVITATION ROUTES
// Paste these INSIDE your existing $routes->group('', ['filter' => 'session'], ...)
// and BEFORE that group's closing });
// Do not add these to the public guest KYC routes.

$routes->get('reservations/(:num)/kyc-link', 'KycLinks::show/$1', [
    'filter' => 'permission:finance.view',
]);

$routes->post('reservations/(:num)/kyc-link', 'KycLinks::generate/$1', [
    'filter' => 'permission:finance.manage',
]);
// HAVEN SUITES — PUBLIC GUEST KYC ROUTES
// Add these to app/Config/Routes.php OUTSIDE all authenticated
// route groups (including groups protected by 'session').
// Access to a booking form is controlled by its random one-time token.

$routes->get('kyc/thank-you', 'Kyc::thankYou');
$routes->get('kyc/invalid', 'Kyc::invalid');
$routes->get('kyc/(:segment)', 'Kyc::form/$1');
$routes->post('kyc/(:segment)/submit', 'Kyc::submit/$1');

// Add inside your existing authenticated routes group, if you have one.
// Shield permission filter syntax uses a colon, not a comma.
$routes->get('customers', 'Customers::index', ['filter' => 'permission:finance.view']);
$routes->get('customers/create', 'Customers::create', ['filter' => 'permission:finance.manage']);
$routes->post('customers', 'Customers::store', ['filter' => 'permission:finance.manage']);
$routes->get('customers/(:num)', 'Customers::show/$1', ['filter' => 'permission:finance.view']);


$routes->get(
    'invoices/(:num)',
    'Invoices::show/$1',
    ['filter' => ['session', 'permission:finance.view']]
);

$routes->post(
    'invoices/(:num)/issue',
    'Invoices::issue/$1',
    ['filter' => ['session', 'permission:finance.manage']]
);


$routes->get(
    'invoices/create',
    'Invoices::create',
    ['filter' => ['session', 'permission:finance.manage']]
);

$routes->post(
    'invoices',
    'Invoices::store',
    ['filter' => ['session', 'permission:finance.manage']]
);


// Invoice directory (read-only first stage).
$routes->get(
    'invoices',
    'Invoices::index',
['filter' => ['session', 'permission:finance.view']]);

// Investor reports: stay-based revenue and expense-date accounting.
$routes->get(
    'reports/investor',
    'InvestorReports::index',
    ['filter' => 'permission:finance.view']
);

// Edit existing partner owner payment details (amount remains locked).
// The controller update() method verifies finance.manage again.
$routes->post(
    'payments/partners/payments/(:num)/update',
    'PartnerOwnerPayments::update/$1',
    ['filter' => ['session', 'permission:finance.manage']]
);

// Partner apartment owner payments: view and recording permissions are separate.
$routes->get(
    'payments/partners',
    'PartnerOwnerPayments::index',
    ['filter' => 'permission:finance.view']
);

$routes->get(
    'payments/partners/owners',
    'PartnerOwnerPayments::ownerBalances',
    ['filter' => 'permission:finance.view']
);

$routes->get(
    'payments/partners/owners/(:num)',
    'PartnerOwnerPayments::ownerStatement/$1',
    ['filter' => 'permission:finance.view']
);

$routes->get(
    'payments/partners/(:num)',
    'PartnerOwnerPayments::show/$1',
    ['filter' => 'permission:finance.view']
);

$routes->post(
    'payments/partners/(:num)',
    'PartnerOwnerPayments::store/$1',
    ['filter' => 'permission:finance.manage']
);

$routes->get(
    'reports/monthly-company-income',
    'MonthlyCompanyIncome::index',
    ['filter' => 'permission:finance.view']
);

$routes->post(
    'owner-statement-closing/reopen',
    'OwnerAccountingReopening::reopen',
    ['filter' => 'permission:finance.manage']
);

$routes->get(
    'owner-statement-closing',
    'OwnerStatementClosings::index',
    ['filter' => 'permission:finance.manage']
);

$routes->post(
    'owner-statement-closing/opening',
    'OwnerStatementClosings::opening',
    ['filter' => 'permission:finance.manage']
);

$routes->post(
    'owner-statement-closing/approve',
    'OwnerStatementClosings::approve',
    ['filter' => 'permission:finance.manage']
);

$routes->get(
    'owner-remittances',
    'OwnerRemittances::index',
    ['filter' => 'permission:finance.view']
);

$routes->post(
    'owner-remittances',
    'OwnerRemittances::store',
    ['filter' => 'permission:finance.manage']
);

$routes->get(
    'payments/hosts',
    'HostPayments::index',
    ['filter' => 'permission:finance.view']
);

$routes->get(
    'payments/hosts/(:num)',
    'HostPayments::show/$1',
    ['filter' => 'permission:finance.view']
);

$routes->post(
    'payments/hosts/(:num)',
    'HostPayments::store/$1',
    ['filter' => 'permission:finance.manage']
);

$routes->get(
    'reports/monthly-host-commissions',
    'MonthlyHostCommissions::index',
    ['filter' => 'permission:finance.view']
);

$routes->get(
    'host-agreements',
    'HostAgreements::index',
    ['filter' => 'permission:finance.view']
);

$routes->get(
    'host-agreements/create',
    'HostAgreements::create',
    ['filter' => 'permission:finance.manage']
);

$routes->post(
    'host-agreements',
    'HostAgreements::store',
    ['filter' => 'permission:finance.manage']
);

$routes->get(
    'host-agreements/(:num)/edit',
    'HostAgreements::edit/$1',
    ['filter' => 'permission:finance.manage']
);

$routes->post(
    'host-agreements/(:num)',
    'HostAgreements::update/$1',
    ['filter' => 'permission:finance.manage']
);

$routes->get(
    'payments/guests',
    'GuestPayments::index',
    ['filter' => 'permission:finance.view']
);

$routes->get(
    'payments/guests/(:num)',
    'GuestPayments::show/$1',
    ['filter' => 'permission:finance.view']
);

$routes->post(
    'payments/guests/(:num)',
    'GuestPayments::store/$1',
    ['filter' => 'permission:finance.manage']
);

$routes->post(
    'payments/guests/(:num)/total',
    'GuestPayments::saveTotal/$1',
    ['filter' => 'permission:finance.manage']
);

$routes->get(
    'reports/monthly-unit-expenses',
    'MonthlyUnitExpenses::index',
    ['filter' => 'permission:finance.view']
);

$routes->get(
    'expenses/(:num)/payments',
    'ExpensePayments::show/$1',
    ['filter' => 'permission:finance.view']
);

$routes->post(
    'expenses/(:num)/payments',
    'ExpensePayments::store/$1',
    ['filter' => 'permission:finance.manage']
);

$routes->get(
    'expenses',
    'ExpenseRegister::index',
    ['filter' => 'permission:finance.view']
);

$routes->get(
    'expenses/create',
    'UnitExpenses::create',
    ['filter' => 'permission:finance.manage']
);

$routes->post(
    'expenses',
    'UnitExpenses::store',
    ['filter' => 'permission:finance.manage']
);

$routes->get(
    'payments/cleaners/(:num)',
    'CleanerPayments::show/$1',
    ['filter' => 'permission:finance.view']
);
$routes->get(
    'payments/cleaners/statements',
    'CleanerStatements::index',
    ['filter' => 'permission:finance.view']
);

$routes->get(
    'payments/cleaners/balances',
    'CleanerBalances::index',
    ['filter' => 'permission:finance.view']
);

$routes->setDefaultNamespace('App\Controllers');
$routes->setDefaultController('Dashboard');
$routes->setDefaultMethod('index');
$routes->setTranslateURIDashes(false);
$routes->set404Override();
$routes->setAutoRoute(false);

$routes->get(
    'payments/cleaners',
    'CleanerPayments::index',
    ['filter' => 'permission:finance.view']
);

$routes->get(
    'payments/cleaners/create',
    'CleanerPayments::create',
    ['filter' => 'permission:finance.view']
);

$routes->post(
    'payments/cleaners',
    'CleanerPayments::store',
    ['filter' => 'permission:finance.view']
);

$routes->get(
    'reports/housekeeping',
    'HousekeepingReports::index',
    ['filter' => 'permission:finance.view']
);

$routes->get(
    'reports/housekeeping/export',
    'HousekeepingReports::export',
    ['filter' => 'permission:finance.view']
);


$routes->get(
    'settings/cleaning-rates',
    'CleaningRates::index',
    ['filter' => 'permission:settings.manage']
);

$routes->post(
    'settings/cleaning-rates/update',
    'CleaningRates::update',
    ['filter' => 'permission:settings.manage']
);
/*
|--------------------------------------------------------------------------
| AUTHENTICATION
|--------------------------------------------------------------------------
*/

// HAVEN SUITES — PUBLIC QUOTATION LINKS (no staff login required)
$routes->get('q/(:segment)', 'Quotations::publicView/$1');
$routes->post('q/(:segment)/payment', 'Quotations::submitPayment/$1');

service('auth')->routes($routes, [
    'except' => ['register'],
]);

/*
|--------------------------------------------------------------------------
| PROTECTED HAVEN SUITES ROUTES
|--------------------------------------------------------------------------
*/

$routes->group('', ['filter' => 'session'], static function ($routes) {

    /*
    |--------------------------------------------------------------------------
    | DASHBOARD
    |--------------------------------------------------------------------------
    */

    $routes->get('/', 'Dashboard::index', [
        'filter' => 'permission:dashboard.view',
    ]);

    $routes->get('dashboard', 'Dashboard::index', [
        'filter' => 'permission:dashboard.view',
    ]);

    /*
    |--------------------------------------------------------------------------
    | AVAILABILITY
    |--------------------------------------------------------------------------
    */

    $routes->get('availability', 'Availability::index', [
        'filter' => 'permission:availability.view',
    ]);


    $routes->get('quotations', 'Quotations::index', [
    'filter' => 'permission:reservations.view',
]);

    /*
    |--------------------------------------------------------------------------
    | PROPERTIES
    |--------------------------------------------------------------------------
    */

    $routes->get('properties', 'Properties::index', [
        'filter' => 'permission:properties.view',
    ]);

    $routes->get('properties/create', 'Properties::create', [
        'filter' => 'permission:properties.manage',
    ]);

    $routes->post('properties/store', 'Properties::store', [
        'filter' => 'permission:properties.manage',
    ]);

    $routes->get('properties/edit/(:num)', 'Properties::edit/$1', [
        'filter' => 'permission:properties.manage',
    ]);

    $routes->post('properties/update/(:num)', 'Properties::update/$1', [
        'filter' => 'permission:properties.manage',
    ]);

    /*
    |--------------------------------------------------------------------------
    | UNITS
    |--------------------------------------------------------------------------
    */

    $routes->get('units', 'Units::index', [
        'filter' => 'permission:units.view',
    ]);

    $routes->get('units/create', 'Units::create', [
        'filter' => 'permission:units.manage',
    ]);

    $routes->post('units/store', 'Units::store', [
        'filter' => 'permission:units.manage',
    ]);

    $routes->get('units/edit/(:num)', 'Units::edit/$1', [
        'filter' => 'permission:units.manage',
    ]);

    $routes->post('units/update/(:num)', 'Units::update/$1', [
        'filter' => 'permission:units.manage',
    ]);

    /*
    |--------------------------------------------------------------------------
    | RESERVATIONS
    |--------------------------------------------------------------------------
    */

    $routes->get('reservations', 'Reservations::index', [
        'filter' => 'permission:reservations.view',
    ]);

    $routes->get('reservations/create', 'Reservations::create', [
        'filter' => 'permission:reservations.manage',
    ]);

    $routes->post('reservations/store', 'Reservations::store', [
        'filter' => 'permission:reservations.manage',
    ]);

    /*
    |--------------------------------------------------------------------------
    | STAFF
    |--------------------------------------------------------------------------
    */

    $routes->get('staff', 'Staff::index', [
        'filter' => 'permission:staff.view',
    ]);

    $routes->get('staff/create', 'Staff::create', [
        'filter' => 'permission:staff.manage',
    ]);

    $routes->post('staff/store', 'Staff::store', [
        'filter' => 'permission:staff.manage',
    ]);

    $routes->get('staff/edit/(:num)', 'Staff::edit/$1', [
        'filter' => 'permission:staff.manage',
    ]);

    $routes->post('staff/update/(:num)', 'Staff::update/$1', [
        'filter' => 'permission:staff.manage',
    ]);

    /*
    |--------------------------------------------------------------------------
    | STAFF LOGIN ACCOUNTS
    |--------------------------------------------------------------------------
    */

    $routes->get(
        'staff/(:num)/account/create',
        'StaffAccounts::create/$1',
        ['filter' => 'permission:users.manage']
    );

    $routes->post(
        'staff/(:num)/account/store',
        'StaffAccounts::store/$1',
        ['filter' => 'permission:users.manage']
    );

    /*
    |--------------------------------------------------------------------------
    | MAINTENANCE
    |--------------------------------------------------------------------------
    */

    $routes->get('maintenance', 'Maintenance::index', [
        'filter' => 'permission:maintenance.view',
    ]);

    $routes->get('maintenance/create', 'Maintenance::create', [
        'filter' => 'permission:maintenance.manage',
    ]);

    $routes->post('maintenance/store', 'Maintenance::store', [
        'filter' => 'permission:maintenance.manage',
    ]);

    $routes->get('maintenance/edit/(:num)', 'Maintenance::edit/$1', [
        'filter' => 'permission:maintenance.manage',
    ]);

    $routes->post('maintenance/update/(:num)', 'Maintenance::update/$1', [
        'filter' => 'permission:maintenance.manage',
    ]);

    /*
    |--------------------------------------------------------------------------
    | HOUSEKEEPING
    |--------------------------------------------------------------------------
    */

    // Cleaner entry is available to signed-in users; the controller authorizes
    // cleaners and managers and checks the staff identity before saving.
    // This is inside the session-protected routes group.
    // Monthly cleaner earnings: authenticated access; controller restricts
    // cleaners to their own staff records and authorizes management users.
    $routes->get('payments/cleaners/earnings', 'CleanerEarnings::index');

    $routes->get('housekeeping/my-cleanings/new', 'Housekeeping::cleanerCreate');
    $routes->post('housekeeping/my-cleanings', 'Housekeeping::cleanerStore');

    $routes->get('housekeeping', 'Housekeeping::index', [
        'filter' => 'permission:housekeeping.view',
    ]);

    $routes->get('housekeeping/create', 'Housekeeping::create', [
        'filter' => 'permission:housekeeping.manage',
    ]);

    $routes->post('housekeeping/store', 'Housekeeping::store', [
        'filter' => 'permission:housekeeping.manage',
    ]);

    $routes->get('housekeeping/edit/(:num)', 'Housekeeping::edit/$1', [
        'filter' => 'permission:housekeeping.manage',
    ]);

    $routes->post('housekeeping/update/(:num)', 'Housekeeping::update/$1', [
        'filter' => 'permission:housekeeping.manage',
    ]);


    // Reservation editing: signed-in staff with management permission only.
    $routes->get('reservations/(:num)/edit', 'Reservations::edit/$1', [
        'filter' => 'permission:reservations.manage',
    ]);

    $routes->post('reservations/update/(:num)', 'Reservations::update/$1', [
        'filter' => 'permission:reservations.manage',
    ]);

    $routes->get('reservations/(:num)', 'Reservations::show/$1', [
        'filter' => 'permission:reservations.view',
    ]);

    $routes->get('dashboard', 'Dashboard::index', [
    'filter' => 'session',
]);


$routes->post(
    'housekeeping/verify-from-earnings',
    'Housekeeping::verifyFromEarnings',
    ['filter' => 'permission:housekeeping.verify']

);

// HAVEN SUITES — QUOTATIONS V2 (staff-only actions)
$routes->get('reservations/(:num)/quotation', 'Quotations::preview/$1', [
    'filter' => 'permission:reservations.view',
]);
$routes->post('reservations/(:num)/quotation/issue', 'Quotations::issue/$1', [
    'filter' => 'permission:reservations.manage',
]);
$routes->get('quotations/payment-claims', 'Quotations::claims', [
    'filter' => 'permission:finance.manage',
]);
$routes->post('quotations/payment-claims/(:num)/review', 'Quotations::review/$1', [
    'filter' => 'permission:finance.manage',
]);

    /*
    |--------------------------------------------------------------------------
    | CLEANER PROGRESS UPDATES
    |--------------------------------------------------------------------------
    */

    $routes->post(
        'housekeeping/progress/(:num)',
        'Housekeeping::updateProgress/$1',
        ['filter' => 'permission:housekeeping.view']
    );

}); // End protected routes group

/*
|--------------------------------------------------------------------------
| ENVIRONMENT ROUTES
|--------------------------------------------------------------------------
*/

if (is_file(APPPATH . 'Config/' . ENVIRONMENT . '/Routes.php')) {
    require APPPATH . 'Config/' . ENVIRONMENT . '/Routes.php';
}
