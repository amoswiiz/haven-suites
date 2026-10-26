<?php

use App\Http\Controllers\Haven\Availability;
use App\Http\Controllers\Haven\CleanerBalances;
use App\Http\Controllers\Haven\CleanerEarnings;
use App\Http\Controllers\Haven\CleanerPaymentDetails;
use App\Http\Controllers\Haven\CleanerPayments;
use App\Http\Controllers\Haven\CleanerStatements;
use App\Http\Controllers\Haven\CleaningRates;
use App\Http\Controllers\Haven\Customers;
use App\Http\Controllers\Haven\Dashboard;
use App\Http\Controllers\Haven\ExpensePayments;
use App\Http\Controllers\Haven\ExpenseRegister;
use App\Http\Controllers\Haven\GuestPayments;
use App\Http\Controllers\Haven\HostAgreements;
use App\Http\Controllers\Haven\HostPayments;
use App\Http\Controllers\Haven\Housekeeping;
use App\Http\Controllers\Haven\HousekeepingReports;
use App\Http\Controllers\Haven\InvestorReports;
use App\Http\Controllers\Haven\InvoicePayments;
use App\Http\Controllers\Haven\Invoices;
use App\Http\Controllers\Haven\Kyc;
use App\Http\Controllers\Haven\KycLinks;
use App\Http\Controllers\Haven\KycReviews;
use App\Http\Controllers\Haven\Maintenance;
use App\Http\Controllers\Haven\MonthlyCompanyIncome;
use App\Http\Controllers\Haven\MonthlyHostCommissions;
use App\Http\Controllers\Haven\MonthlyUnitExpenses;
use App\Http\Controllers\Haven\OwnerAccountingReopening;
use App\Http\Controllers\Haven\OwnerRemittances;
use App\Http\Controllers\Haven\OwnerStatementClosings;
use App\Http\Controllers\Haven\PartnerOwnerPayments;
use App\Http\Controllers\Haven\Properties;
use App\Http\Controllers\Haven\Quotations;
use App\Http\Controllers\Haven\Receipts;
use App\Http\Controllers\Haven\Reservations;
use App\Http\Controllers\Haven\Staff;
use App\Http\Controllers\Haven\StaffAccounts;
use App\Http\Controllers\Haven\UnitExpenses;
use App\Http\Controllers\Haven\Units;
use App\Http\Controllers\InvoiceSettingsController;
use App\Http\Controllers\StandaloneQuotationController;
use Illuminate\Support\Facades\Route;

Route::get('receipts/{id}', [Receipts::class, 'show'])->middleware([
    0 => 'auth',
    1 => 'tenant.member',
    2 => 'permission:finance.view',
])->whereNumber('id');
Route::post('invoices/{id}/customer', [Invoices::class, 'linkCustomer'])->middleware([
    0 => 'auth',
    1 => 'tenant.member',
    2 => 'permission:finance.manage',
])->whereNumber('id');
Route::get('invoices/{id}/payments', [InvoicePayments::class, 'index'])->middleware([
    0 => 'auth',
    1 => 'tenant.member',
    2 => 'permission:finance.manage',
])->whereNumber('id');
Route::post('invoices/{id}/payments', [InvoicePayments::class, 'allocate'])->middleware([
    0 => 'auth',
    1 => 'tenant.member',
    2 => 'permission:finance.manage',
])->whereNumber('id');
Route::post('invoices/{id}/payments/record', [InvoicePayments::class, 'record'])->middleware([
    0 => 'auth',
    1 => 'tenant.member',
    2 => 'permission:finance.manage',
])->whereNumber('id');
Route::post('staff/kyc/{id}/invoice', [Invoices::class, 'fromKyc'])->middleware([
    0 => 'auth',
    1 => 'tenant.member',
    2 => 'permission:finance.manage',
])->whereNumber('id');
Route::get('staff/kyc', [KycReviews::class, 'index'])->middleware([
    0 => 'auth',
    1 => 'tenant.member',
    2 => 'permission:finance.view',
]);
Route::get('staff/kyc/{id}/document', [KycReviews::class, 'document'])->middleware([
    0 => 'auth',
    1 => 'tenant.member',
    2 => 'permission:finance.view',
])->whereNumber('id');
Route::post('staff/kyc/{id}/decide', [KycReviews::class, 'decide'])->middleware([
    0 => 'auth',
    1 => 'tenant.member',
    2 => 'permission:finance.manage',
])->whereNumber('id');
Route::get('staff/kyc/{id}', [KycReviews::class, 'show'])->middleware([
    0 => 'auth',
    1 => 'tenant.member',
    2 => 'permission:finance.view',
])->whereNumber('id');
Route::get('reservations/{id}/kyc-link', [KycLinks::class, 'show'])->middleware([
    0 => 'auth',
    1 => 'tenant.member',
    2 => 'permission:finance.view',
])->whereNumber('id');
Route::post('reservations/{id}/kyc-link', [KycLinks::class, 'generate'])->middleware([
    0 => 'auth',
    1 => 'tenant.member',
    2 => 'permission:finance.manage',
])->whereNumber('id');
Route::get('kyc/thank-you', [Kyc::class, 'thankYou']);
Route::get('kyc/invalid', [Kyc::class, 'invalid']);
Route::get('kyc/{token}', [Kyc::class, 'form']);
Route::post('kyc/{token}/submit', [Kyc::class, 'submit'])->middleware([
    0 => 'throttle:10,1',
]);
Route::get('customers', [Customers::class, 'index'])->middleware([
    0 => 'auth',
    1 => 'tenant.member',
    2 => 'permission:finance.view',
]);
Route::get('customers/create', [Customers::class, 'create'])->middleware([
    0 => 'auth',
    1 => 'tenant.member',
    2 => 'permission:finance.manage',
]);
Route::post('customers', [Customers::class, 'store'])->middleware([
    0 => 'auth',
    1 => 'tenant.member',
    2 => 'permission:finance.manage',
]);
Route::get('customers/{id}', [Customers::class, 'show'])->middleware([
    0 => 'auth',
    1 => 'tenant.member',
    2 => 'permission:finance.view',
])->whereNumber('id');
Route::get('invoices/{id}', [Invoices::class, 'show'])->middleware([
    0 => 'auth',
    1 => 'tenant.member',
    2 => 'permission:finance.view',
])->whereNumber('id');
Route::get('invoices/settings', [InvoiceSettingsController::class, 'edit'])->middleware(['auth', 'tenant.member', 'permission:finance.manage']);
Route::post('invoices/settings', [InvoiceSettingsController::class, 'update'])->middleware(['auth', 'tenant.member', 'permission:finance.manage']);
Route::get('invoices/{id}/pdf', [Invoices::class, 'pdf'])->middleware(['auth', 'tenant.member', 'permission:finance.view'])->whereNumber('id');
Route::post('invoices/{id}/issue', [Invoices::class, 'issue'])->middleware([
    0 => 'auth',
    1 => 'tenant.member',
    2 => 'permission:finance.manage',
])->whereNumber('id');
Route::get('invoices/create', [Invoices::class, 'create'])->middleware([
    0 => 'auth',
    1 => 'tenant.member',
    2 => 'permission:finance.manage',
]);
Route::post('invoices', [Invoices::class, 'store'])->middleware([
    0 => 'auth',
    1 => 'tenant.member',
    2 => 'permission:finance.manage',
]);
Route::get('invoices', [Invoices::class, 'index'])->middleware([
    0 => 'auth',
    1 => 'tenant.member',
    2 => 'permission:finance.view',
]);
Route::get('reports/investor', [InvestorReports::class, 'index'])->middleware([
    0 => 'auth',
    1 => 'tenant.member',
    2 => 'permission:finance.view',
]);
Route::post('payments/partners/payments/{id}/update', [PartnerOwnerPayments::class, 'update'])->middleware([
    0 => 'auth',
    1 => 'tenant.member',
    2 => 'permission:finance.manage',
])->whereNumber('id');
Route::get('payments/partners', [PartnerOwnerPayments::class, 'index'])->middleware([
    0 => 'auth',
    1 => 'tenant.member',
    2 => 'permission:finance.view',
]);
Route::get('payments/partners/owners', [PartnerOwnerPayments::class, 'ownerBalances'])->middleware([
    0 => 'auth',
    1 => 'tenant.member',
    2 => 'permission:finance.view',
]);
Route::get('payments/partners/owners/{id}', [PartnerOwnerPayments::class, 'ownerStatement'])->middleware([
    0 => 'auth',
    1 => 'tenant.member',
    2 => 'permission:finance.view',
])->whereNumber('id');
Route::get('payments/partners/{id}', [PartnerOwnerPayments::class, 'show'])->middleware([
    0 => 'auth',
    1 => 'tenant.member',
    2 => 'permission:finance.view',
])->whereNumber('id');
Route::post('payments/partners/{id}', [PartnerOwnerPayments::class, 'store'])->middleware([
    0 => 'auth',
    1 => 'tenant.member',
    2 => 'permission:finance.manage',
])->whereNumber('id');
Route::get('reports/monthly-company-income', [MonthlyCompanyIncome::class, 'index'])->middleware([
    0 => 'auth',
    1 => 'tenant.member',
    2 => 'permission:finance.view',
]);
Route::post('owner-statement-closing/reopen', [OwnerAccountingReopening::class, 'reopen'])->middleware([
    0 => 'auth',
    1 => 'tenant.member',
    2 => 'permission:finance.manage',
]);
Route::get('owner-statement-closing', [OwnerStatementClosings::class, 'index'])->middleware([
    0 => 'auth',
    1 => 'tenant.member',
    2 => 'permission:finance.manage',
]);
Route::post('owner-statement-closing/opening', [OwnerStatementClosings::class, 'opening'])->middleware([
    0 => 'auth',
    1 => 'tenant.member',
    2 => 'permission:finance.manage',
]);
Route::post('owner-statement-closing/approve', [OwnerStatementClosings::class, 'approve'])->middleware([
    0 => 'auth',
    1 => 'tenant.member',
    2 => 'permission:finance.manage',
]);
Route::get('owner-remittances', [OwnerRemittances::class, 'index'])->middleware([
    0 => 'auth',
    1 => 'tenant.member',
    2 => 'permission:finance.view',
]);
Route::post('owner-remittances', [OwnerRemittances::class, 'store'])->middleware([
    0 => 'auth',
    1 => 'tenant.member',
    2 => 'permission:finance.manage',
]);
Route::get('payments/hosts', [HostPayments::class, 'index'])->middleware([
    0 => 'auth',
    1 => 'tenant.member',
    2 => 'permission:finance.view',
]);
Route::get('payments/hosts/{id}', [HostPayments::class, 'show'])->middleware([
    0 => 'auth',
    1 => 'tenant.member',
    2 => 'permission:finance.view',
])->whereNumber('id');
Route::post('payments/hosts/{id}', [HostPayments::class, 'store'])->middleware([
    0 => 'auth',
    1 => 'tenant.member',
    2 => 'permission:finance.manage',
])->whereNumber('id');
Route::get('reports/monthly-host-commissions', [MonthlyHostCommissions::class, 'index'])->middleware([
    0 => 'auth',
    1 => 'tenant.member',
    2 => 'permission:finance.view',
]);
Route::get('host-agreements', [HostAgreements::class, 'index'])->middleware([
    0 => 'auth',
    1 => 'tenant.member',
    2 => 'permission:finance.view',
]);
Route::get('host-agreements/create', [HostAgreements::class, 'create'])->middleware([
    0 => 'auth',
    1 => 'tenant.member',
    2 => 'permission:finance.manage',
]);
Route::post('host-agreements', [HostAgreements::class, 'store'])->middleware([
    0 => 'auth',
    1 => 'tenant.member',
    2 => 'permission:finance.manage',
]);
Route::get('host-agreements/{id}/edit', [HostAgreements::class, 'edit'])->middleware([
    0 => 'auth',
    1 => 'tenant.member',
    2 => 'permission:finance.manage',
])->whereNumber('id');
Route::post('host-agreements/{id}', [HostAgreements::class, 'update'])->middleware([
    0 => 'auth',
    1 => 'tenant.member',
    2 => 'permission:finance.manage',
])->whereNumber('id');
Route::get('payments/guests', [GuestPayments::class, 'index'])->middleware([
    0 => 'auth',
    1 => 'tenant.member',
    2 => 'permission:finance.view',
]);
Route::get('payments/guests/{id}', [GuestPayments::class, 'show'])->middleware([
    0 => 'auth',
    1 => 'tenant.member',
    2 => 'permission:finance.view',
])->whereNumber('id');
Route::post('payments/guests/{id}', [GuestPayments::class, 'store'])->middleware([
    0 => 'auth',
    1 => 'tenant.member',
    2 => 'permission:finance.manage',
])->whereNumber('id');
Route::post('payments/guests/{id}/total', [GuestPayments::class, 'saveTotal'])->middleware([
    0 => 'auth',
    1 => 'tenant.member',
    2 => 'permission:finance.manage',
])->whereNumber('id');
Route::get('reports/monthly-unit-expenses', [MonthlyUnitExpenses::class, 'index'])->middleware([
    0 => 'auth',
    1 => 'tenant.member',
    2 => 'permission:finance.view',
]);
Route::get('expenses/{id}/payments', [ExpensePayments::class, 'show'])->middleware([
    0 => 'auth',
    1 => 'tenant.member',
    2 => 'permission:finance.view',
])->whereNumber('id');
Route::post('expenses/{id}/payments', [ExpensePayments::class, 'store'])->middleware([
    0 => 'auth',
    1 => 'tenant.member',
    2 => 'permission:finance.manage',
])->whereNumber('id');
Route::get('expenses', [ExpenseRegister::class, 'index'])->middleware([
    0 => 'auth',
    1 => 'tenant.member',
    2 => 'permission:finance.view',
]);
Route::get('expenses/create', [UnitExpenses::class, 'create'])->middleware([
    0 => 'auth',
    1 => 'tenant.member',
    2 => 'permission:finance.manage',
]);
Route::post('expenses', [UnitExpenses::class, 'store'])->middleware([
    0 => 'auth',
    1 => 'tenant.member',
    2 => 'permission:finance.manage',
]);
Route::get('payments/cleaners/statements', [CleanerStatements::class, 'index'])->middleware([
    0 => 'auth',
    1 => 'tenant.member',
    2 => 'permission:finance.view',
]);
Route::get('payments/cleaners/balances', [CleanerBalances::class, 'index'])->middleware([
    0 => 'auth',
    1 => 'tenant.member',
    2 => 'permission:finance.view',
]);
Route::get('payments/cleaners', [CleanerPayments::class, 'index'])->middleware([
    0 => 'auth',
    1 => 'tenant.member',
    2 => 'permission:finance.view',
]);
Route::get('payments/cleaners/create', [CleanerPayments::class, 'create'])->middleware([
    0 => 'auth',
    1 => 'tenant.member',
    2 => 'permission:finance.view',
]);
Route::post('payments/cleaners', [CleanerPayments::class, 'store'])->middleware([
    0 => 'auth',
    1 => 'tenant.member',
    2 => 'permission:finance.view',
]);
Route::get('reports/housekeeping', [HousekeepingReports::class, 'index'])->middleware([
    0 => 'auth',
    1 => 'tenant.member',
    2 => 'permission:finance.view',
]);
Route::get('reports/housekeeping/export', [HousekeepingReports::class, 'export'])->middleware([
    0 => 'auth',
    1 => 'tenant.member',
    2 => 'permission:finance.view',
]);
Route::get('settings/cleaning-rates', [CleaningRates::class, 'index'])->middleware([
    0 => 'auth',
    1 => 'tenant.member',
    2 => 'permission:settings.manage',
]);
Route::post('settings/cleaning-rates/update', [CleaningRates::class, 'update'])->middleware([
    0 => 'auth',
    1 => 'tenant.member',
    2 => 'permission:settings.manage',
]);
Route::get('q/{token}', [Quotations::class, 'publicView']);
Route::post('q/{token}/payment', [Quotations::class, 'submitPayment'])->middleware([
    0 => 'throttle:10,1',
]);
Route::get('/', [Dashboard::class, 'index'])->middleware([
    0 => 'auth',
    1 => 'tenant.member',
    2 => 'permission:dashboard.view',
]);
Route::get('dashboard', [Dashboard::class, 'index'])->middleware([
    0 => 'auth',
    1 => 'tenant.member',
    2 => 'permission:dashboard.view',
]);
Route::get('availability', [Availability::class, 'index'])->middleware([
    0 => 'auth',
    1 => 'tenant.member',
    2 => 'permission:availability.view',
]);
Route::get('quotations', [Quotations::class, 'index'])->middleware([
    0 => 'auth',
    1 => 'tenant.member',
    2 => 'permission:reservations.view',
]);
Route::get('quotations/create', [StandaloneQuotationController::class, 'create'])->middleware(['auth', 'tenant.member', 'permission:reservations.manage']);
Route::post('quotations', [StandaloneQuotationController::class, 'store'])->middleware(['auth', 'tenant.member', 'permission:reservations.manage']);
Route::get('quotations/independent/{id}', [StandaloneQuotationController::class, 'show'])->whereNumber('id')->middleware(['auth', 'tenant.member', 'permission:reservations.view']);
Route::get('properties', [Properties::class, 'index'])->middleware([
    0 => 'auth',
    1 => 'tenant.member',
    2 => 'permission:properties.view',
]);
Route::get('properties/create', [Properties::class, 'create'])->middleware([
    0 => 'auth',
    1 => 'tenant.member',
    2 => 'permission:properties.manage',
]);
Route::post('properties/store', [Properties::class, 'store'])->middleware([
    0 => 'auth',
    1 => 'tenant.member',
    2 => 'permission:properties.manage',
]);
Route::get('properties/edit/{id}', [Properties::class, 'edit'])->middleware([
    0 => 'auth',
    1 => 'tenant.member',
    2 => 'permission:properties.manage',
])->whereNumber('id');
Route::post('properties/update/{id}', [Properties::class, 'update'])->middleware([
    0 => 'auth',
    1 => 'tenant.member',
    2 => 'permission:properties.manage',
])->whereNumber('id');
Route::get('units', [Units::class, 'index'])->middleware([
    0 => 'auth',
    1 => 'tenant.member',
    2 => 'permission:units.view',
]);
Route::get('units/create', [Units::class, 'create'])->middleware([
    0 => 'auth',
    1 => 'tenant.member',
    2 => 'permission:units.manage',
]);
Route::post('units/store', [Units::class, 'store'])->middleware([
    0 => 'auth',
    1 => 'tenant.member',
    2 => 'permission:units.manage',
]);
Route::get('units/edit/{id}', [Units::class, 'edit'])->middleware([
    0 => 'auth',
    1 => 'tenant.member',
    2 => 'permission:units.manage',
])->whereNumber('id');
Route::post('units/update/{id}', [Units::class, 'update'])->middleware([
    0 => 'auth',
    1 => 'tenant.member',
    2 => 'permission:units.manage',
])->whereNumber('id');
Route::get('reservations', [Reservations::class, 'index'])->middleware([
    0 => 'auth',
    1 => 'tenant.member',
    2 => 'permission:reservations.view',
]);
Route::get('reservations/create', [Reservations::class, 'create'])->middleware([
    0 => 'auth',
    1 => 'tenant.member',
    2 => 'permission:reservations.manage',
]);
Route::post('reservations/store', [Reservations::class, 'store'])->middleware([
    0 => 'auth',
    1 => 'tenant.member',
    2 => 'permission:reservations.manage',
]);
Route::get('staff', [Staff::class, 'index'])->middleware([
    0 => 'auth',
    1 => 'tenant.member',
    2 => 'permission:staff.view',
]);
Route::get('staff/create', [Staff::class, 'create'])->middleware([
    0 => 'auth',
    1 => 'tenant.member',
    2 => 'permission:staff.manage',
]);
Route::post('staff/store', [Staff::class, 'store'])->middleware([
    0 => 'auth',
    1 => 'tenant.member',
    2 => 'permission:staff.manage',
]);
Route::get('staff/edit/{id}', [Staff::class, 'edit'])->middleware([
    0 => 'auth',
    1 => 'tenant.member',
    2 => 'permission:staff.manage',
])->whereNumber('id');
Route::post('staff/update/{id}', [Staff::class, 'update'])->middleware([
    0 => 'auth',
    1 => 'tenant.member',
    2 => 'permission:staff.manage',
])->whereNumber('id');
Route::get('staff/{id}/account/create', [StaffAccounts::class, 'create'])->middleware([
    0 => 'auth',
    1 => 'tenant.member',
    2 => 'permission:users.manage',
])->whereNumber('id');
Route::post('staff/{id}/account/store', [StaffAccounts::class, 'store'])->middleware([
    0 => 'auth',
    1 => 'tenant.member',
    2 => 'permission:users.manage',
])->whereNumber('id');
Route::get('maintenance', [Maintenance::class, 'index'])->middleware([
    0 => 'auth',
    1 => 'tenant.member',
    2 => 'permission:maintenance.view',
]);
Route::get('maintenance/create', [Maintenance::class, 'create'])->middleware([
    0 => 'auth',
    1 => 'tenant.member',
    2 => 'permission:maintenance.manage',
]);
Route::post('maintenance/store', [Maintenance::class, 'store'])->middleware([
    0 => 'auth',
    1 => 'tenant.member',
    2 => 'permission:maintenance.manage',
]);
Route::get('maintenance/edit/{id}', [Maintenance::class, 'edit'])->middleware([
    0 => 'auth',
    1 => 'tenant.member',
    2 => 'permission:maintenance.manage',
])->whereNumber('id');
Route::post('maintenance/update/{id}', [Maintenance::class, 'update'])->middleware([
    0 => 'auth',
    1 => 'tenant.member',
    2 => 'permission:maintenance.manage',
])->whereNumber('id');
Route::get('payments/cleaners/earnings', [CleanerEarnings::class, 'index'])->middleware([
    0 => 'auth',
    1 => 'tenant.member',
]);
Route::get('housekeeping/my-cleanings/new', [Housekeeping::class, 'cleanerCreate'])->middleware([
    0 => 'auth',
    1 => 'tenant.member',
]);
Route::post('housekeeping/my-cleanings', [Housekeeping::class, 'cleanerStore'])->middleware([
    0 => 'auth',
    1 => 'tenant.member',
]);
Route::get('housekeeping', [Housekeeping::class, 'index'])->middleware([
    0 => 'auth',
    1 => 'tenant.member',
    2 => 'permission:housekeeping.view',
]);
Route::get('housekeeping/create', [Housekeeping::class, 'create'])->middleware([
    0 => 'auth',
    1 => 'tenant.member',
    2 => 'permission:housekeeping.manage',
]);
Route::post('housekeeping/store', [Housekeeping::class, 'store'])->middleware([
    0 => 'auth',
    1 => 'tenant.member',
    2 => 'permission:housekeeping.manage',
]);
Route::get('housekeeping/edit/{id}', [Housekeeping::class, 'edit'])->middleware([
    0 => 'auth',
    1 => 'tenant.member',
    2 => 'permission:housekeeping.manage',
])->whereNumber('id');
Route::post('housekeeping/update/{id}', [Housekeeping::class, 'update'])->middleware([
    0 => 'auth',
    1 => 'tenant.member',
    2 => 'permission:housekeeping.manage',
])->whereNumber('id');
Route::get('reservations/{id}/edit', [Reservations::class, 'edit'])->middleware([
    0 => 'auth',
    1 => 'tenant.member',
    2 => 'permission:reservations.manage',
])->whereNumber('id');
Route::post('reservations/update/{id}', [Reservations::class, 'update'])->middleware([
    0 => 'auth',
    1 => 'tenant.member',
    2 => 'permission:reservations.manage',
])->whereNumber('id');
Route::get('reservations/{id}', [Reservations::class, 'show'])->middleware([
    0 => 'auth',
    1 => 'tenant.member',
    2 => 'permission:reservations.view',
])->whereNumber('id');
Route::post('housekeeping/verify-from-earnings', [Housekeeping::class, 'verifyFromEarnings'])->middleware([
    0 => 'auth',
    1 => 'tenant.member',
    2 => 'permission:housekeeping.verify',
]);
Route::get('reservations/{id}/quotation', [Quotations::class, 'preview'])->middleware([
    0 => 'auth',
    1 => 'tenant.member',
    2 => 'permission:reservations.view',
])->whereNumber('id');
Route::post('reservations/{id}/quotation/issue', [Quotations::class, 'issue'])->middleware([
    0 => 'auth',
    1 => 'tenant.member',
    2 => 'permission:reservations.manage',
])->whereNumber('id');
Route::get('quotations/payment-claims', [Quotations::class, 'claims'])->middleware([
    0 => 'auth',
    1 => 'tenant.member',
    2 => 'permission:finance.manage',
]);
Route::post('quotations/payment-claims/{id}/review', [Quotations::class, 'review'])->middleware([
    0 => 'auth',
    1 => 'tenant.member',
    2 => 'permission:finance.manage',
])->whereNumber('id');
Route::post('housekeeping/progress/{id}', [Housekeeping::class, 'updateProgress'])->middleware([
    0 => 'auth',
    1 => 'tenant.member',
    2 => 'permission:housekeeping.view',
])->whereNumber('id');
Route::get('owner-remittances/export', [OwnerRemittances::class, 'export'])->middleware(['auth', 'tenant.member', 'permission:finance.view']);
Route::get('home', function () {
    return haven_landing() === site_url('home') ? view('auth.company-home') : redirect()->to(haven_landing());
})->middleware(['auth', 'tenant.member']);
Route::get('payments/cleaners/{id}', [CleanerPaymentDetails::class, 'show'])->whereNumber('id')->middleware(['auth', 'tenant.member', 'permission:finance.view']);
