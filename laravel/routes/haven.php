<?php
use Illuminate\Support\Facades\Route;
Route::get('receipts/{id}', [\App\Http\Controllers\Haven\Receipts::class, 'show'])->middleware(array (
  0 => 'auth',
  1 => 'tenant.member',
  2 => 'permission:finance.view',
))->whereNumber('id');
Route::post('invoices/{id}/customer', [\App\Http\Controllers\Haven\Invoices::class, 'linkCustomer'])->middleware(array (
  0 => 'auth',
  1 => 'tenant.member',
  2 => 'permission:finance.manage',
))->whereNumber('id');
Route::get('invoices/{id}/payments', [\App\Http\Controllers\Haven\InvoicePayments::class, 'index'])->middleware(array (
  0 => 'auth',
  1 => 'tenant.member',
  2 => 'permission:finance.manage',
))->whereNumber('id');
Route::post('invoices/{id}/payments', [\App\Http\Controllers\Haven\InvoicePayments::class, 'allocate'])->middleware(array (
  0 => 'auth',
  1 => 'tenant.member',
  2 => 'permission:finance.manage',
))->whereNumber('id');
Route::post('invoices/{id}/payments/record', [\App\Http\Controllers\Haven\InvoicePayments::class, 'record'])->middleware(array (
  0 => 'auth',
  1 => 'tenant.member',
  2 => 'permission:finance.manage',
))->whereNumber('id');
Route::post('staff/kyc/{id}/invoice', [\App\Http\Controllers\Haven\Invoices::class, 'fromKyc'])->middleware(array (
  0 => 'auth',
  1 => 'tenant.member',
  2 => 'permission:finance.manage',
))->whereNumber('id');
Route::get('staff/kyc', [\App\Http\Controllers\Haven\KycReviews::class, 'index'])->middleware(array (
  0 => 'auth',
  1 => 'tenant.member',
  2 => 'permission:finance.view',
));
Route::get('staff/kyc/{id}/document', [\App\Http\Controllers\Haven\KycReviews::class, 'document'])->middleware(array (
  0 => 'auth',
  1 => 'tenant.member',
  2 => 'permission:finance.view',
))->whereNumber('id');
Route::post('staff/kyc/{id}/decide', [\App\Http\Controllers\Haven\KycReviews::class, 'decide'])->middleware(array (
  0 => 'auth',
  1 => 'tenant.member',
  2 => 'permission:finance.manage',
))->whereNumber('id');
Route::get('staff/kyc/{id}', [\App\Http\Controllers\Haven\KycReviews::class, 'show'])->middleware(array (
  0 => 'auth',
  1 => 'tenant.member',
  2 => 'permission:finance.view',
))->whereNumber('id');
Route::get('reservations/{id}/kyc-link', [\App\Http\Controllers\Haven\KycLinks::class, 'show'])->middleware(array (
  0 => 'auth',
  1 => 'tenant.member',
  2 => 'permission:finance.view',
))->whereNumber('id');
Route::post('reservations/{id}/kyc-link', [\App\Http\Controllers\Haven\KycLinks::class, 'generate'])->middleware(array (
  0 => 'auth',
  1 => 'tenant.member',
  2 => 'permission:finance.manage',
))->whereNumber('id');
Route::get('kyc/thank-you', [\App\Http\Controllers\Haven\Kyc::class, 'thankYou']);
Route::get('kyc/invalid', [\App\Http\Controllers\Haven\Kyc::class, 'invalid']);
Route::get('kyc/{token}', [\App\Http\Controllers\Haven\Kyc::class, 'form']);
Route::post('kyc/{token}/submit', [\App\Http\Controllers\Haven\Kyc::class, 'submit'])->middleware(array (
  0 => 'throttle:10,1',
));
Route::get('customers', [\App\Http\Controllers\Haven\Customers::class, 'index'])->middleware(array (
  0 => 'auth',
  1 => 'tenant.member',
  2 => 'permission:finance.view',
));
Route::get('customers/create', [\App\Http\Controllers\Haven\Customers::class, 'create'])->middleware(array (
  0 => 'auth',
  1 => 'tenant.member',
  2 => 'permission:finance.manage',
));
Route::post('customers', [\App\Http\Controllers\Haven\Customers::class, 'store'])->middleware(array (
  0 => 'auth',
  1 => 'tenant.member',
  2 => 'permission:finance.manage',
));
Route::get('customers/{id}', [\App\Http\Controllers\Haven\Customers::class, 'show'])->middleware(array (
  0 => 'auth',
  1 => 'tenant.member',
  2 => 'permission:finance.view',
))->whereNumber('id');
Route::get('invoices/{id}', [\App\Http\Controllers\Haven\Invoices::class, 'show'])->middleware(array (
  0 => 'auth',
  1 => 'tenant.member',
  2 => 'permission:finance.view',
))->whereNumber('id');
Route::post('invoices/{id}/issue', [\App\Http\Controllers\Haven\Invoices::class, 'issue'])->middleware(array (
  0 => 'auth',
  1 => 'tenant.member',
  2 => 'permission:finance.manage',
))->whereNumber('id');
Route::get('invoices/create', [\App\Http\Controllers\Haven\Invoices::class, 'create'])->middleware(array (
  0 => 'auth',
  1 => 'tenant.member',
  2 => 'permission:finance.manage',
));
Route::post('invoices', [\App\Http\Controllers\Haven\Invoices::class, 'store'])->middleware(array (
  0 => 'auth',
  1 => 'tenant.member',
  2 => 'permission:finance.manage',
));
Route::get('invoices', [\App\Http\Controllers\Haven\Invoices::class, 'index'])->middleware(array (
  0 => 'auth',
  1 => 'tenant.member',
  2 => 'permission:finance.view',
));
Route::get('reports/investor', [\App\Http\Controllers\Haven\InvestorReports::class, 'index'])->middleware(array (
  0 => 'auth',
  1 => 'tenant.member',
  2 => 'permission:finance.view',
));
Route::post('payments/partners/payments/{id}/update', [\App\Http\Controllers\Haven\PartnerOwnerPayments::class, 'update'])->middleware(array (
  0 => 'auth',
  1 => 'tenant.member',
  2 => 'permission:finance.manage',
))->whereNumber('id');
Route::get('payments/partners', [\App\Http\Controllers\Haven\PartnerOwnerPayments::class, 'index'])->middleware(array (
  0 => 'auth',
  1 => 'tenant.member',
  2 => 'permission:finance.view',
));
Route::get('payments/partners/owners', [\App\Http\Controllers\Haven\PartnerOwnerPayments::class, 'ownerBalances'])->middleware(array (
  0 => 'auth',
  1 => 'tenant.member',
  2 => 'permission:finance.view',
));
Route::get('payments/partners/owners/{id}', [\App\Http\Controllers\Haven\PartnerOwnerPayments::class, 'ownerStatement'])->middleware(array (
  0 => 'auth',
  1 => 'tenant.member',
  2 => 'permission:finance.view',
))->whereNumber('id');
Route::get('payments/partners/{id}', [\App\Http\Controllers\Haven\PartnerOwnerPayments::class, 'show'])->middleware(array (
  0 => 'auth',
  1 => 'tenant.member',
  2 => 'permission:finance.view',
))->whereNumber('id');
Route::post('payments/partners/{id}', [\App\Http\Controllers\Haven\PartnerOwnerPayments::class, 'store'])->middleware(array (
  0 => 'auth',
  1 => 'tenant.member',
  2 => 'permission:finance.manage',
))->whereNumber('id');
Route::get('reports/monthly-company-income', [\App\Http\Controllers\Haven\MonthlyCompanyIncome::class, 'index'])->middleware(array (
  0 => 'auth',
  1 => 'tenant.member',
  2 => 'permission:finance.view',
));
Route::post('owner-statement-closing/reopen', [\App\Http\Controllers\Haven\OwnerAccountingReopening::class, 'reopen'])->middleware(array (
  0 => 'auth',
  1 => 'tenant.member',
  2 => 'permission:finance.manage',
));
Route::get('owner-statement-closing', [\App\Http\Controllers\Haven\OwnerStatementClosings::class, 'index'])->middleware(array (
  0 => 'auth',
  1 => 'tenant.member',
  2 => 'permission:finance.manage',
));
Route::post('owner-statement-closing/opening', [\App\Http\Controllers\Haven\OwnerStatementClosings::class, 'opening'])->middleware(array (
  0 => 'auth',
  1 => 'tenant.member',
  2 => 'permission:finance.manage',
));
Route::post('owner-statement-closing/approve', [\App\Http\Controllers\Haven\OwnerStatementClosings::class, 'approve'])->middleware(array (
  0 => 'auth',
  1 => 'tenant.member',
  2 => 'permission:finance.manage',
));
Route::get('owner-remittances', [\App\Http\Controllers\Haven\OwnerRemittances::class, 'index'])->middleware(array (
  0 => 'auth',
  1 => 'tenant.member',
  2 => 'permission:finance.view',
));
Route::post('owner-remittances', [\App\Http\Controllers\Haven\OwnerRemittances::class, 'store'])->middleware(array (
  0 => 'auth',
  1 => 'tenant.member',
  2 => 'permission:finance.manage',
));
Route::get('payments/hosts', [\App\Http\Controllers\Haven\HostPayments::class, 'index'])->middleware(array (
  0 => 'auth',
  1 => 'tenant.member',
  2 => 'permission:finance.view',
));
Route::get('payments/hosts/{id}', [\App\Http\Controllers\Haven\HostPayments::class, 'show'])->middleware(array (
  0 => 'auth',
  1 => 'tenant.member',
  2 => 'permission:finance.view',
))->whereNumber('id');
Route::post('payments/hosts/{id}', [\App\Http\Controllers\Haven\HostPayments::class, 'store'])->middleware(array (
  0 => 'auth',
  1 => 'tenant.member',
  2 => 'permission:finance.manage',
))->whereNumber('id');
Route::get('reports/monthly-host-commissions', [\App\Http\Controllers\Haven\MonthlyHostCommissions::class, 'index'])->middleware(array (
  0 => 'auth',
  1 => 'tenant.member',
  2 => 'permission:finance.view',
));
Route::get('host-agreements', [\App\Http\Controllers\Haven\HostAgreements::class, 'index'])->middleware(array (
  0 => 'auth',
  1 => 'tenant.member',
  2 => 'permission:finance.view',
));
Route::get('host-agreements/create', [\App\Http\Controllers\Haven\HostAgreements::class, 'create'])->middleware(array (
  0 => 'auth',
  1 => 'tenant.member',
  2 => 'permission:finance.manage',
));
Route::post('host-agreements', [\App\Http\Controllers\Haven\HostAgreements::class, 'store'])->middleware(array (
  0 => 'auth',
  1 => 'tenant.member',
  2 => 'permission:finance.manage',
));
Route::get('host-agreements/{id}/edit', [\App\Http\Controllers\Haven\HostAgreements::class, 'edit'])->middleware(array (
  0 => 'auth',
  1 => 'tenant.member',
  2 => 'permission:finance.manage',
))->whereNumber('id');
Route::post('host-agreements/{id}', [\App\Http\Controllers\Haven\HostAgreements::class, 'update'])->middleware(array (
  0 => 'auth',
  1 => 'tenant.member',
  2 => 'permission:finance.manage',
))->whereNumber('id');
Route::get('payments/guests', [\App\Http\Controllers\Haven\GuestPayments::class, 'index'])->middleware(array (
  0 => 'auth',
  1 => 'tenant.member',
  2 => 'permission:finance.view',
));
Route::get('payments/guests/{id}', [\App\Http\Controllers\Haven\GuestPayments::class, 'show'])->middleware(array (
  0 => 'auth',
  1 => 'tenant.member',
  2 => 'permission:finance.view',
))->whereNumber('id');
Route::post('payments/guests/{id}', [\App\Http\Controllers\Haven\GuestPayments::class, 'store'])->middleware(array (
  0 => 'auth',
  1 => 'tenant.member',
  2 => 'permission:finance.manage',
))->whereNumber('id');
Route::post('payments/guests/{id}/total', [\App\Http\Controllers\Haven\GuestPayments::class, 'saveTotal'])->middleware(array (
  0 => 'auth',
  1 => 'tenant.member',
  2 => 'permission:finance.manage',
))->whereNumber('id');
Route::get('reports/monthly-unit-expenses', [\App\Http\Controllers\Haven\MonthlyUnitExpenses::class, 'index'])->middleware(array (
  0 => 'auth',
  1 => 'tenant.member',
  2 => 'permission:finance.view',
));
Route::get('expenses/{id}/payments', [\App\Http\Controllers\Haven\ExpensePayments::class, 'show'])->middleware(array (
  0 => 'auth',
  1 => 'tenant.member',
  2 => 'permission:finance.view',
))->whereNumber('id');
Route::post('expenses/{id}/payments', [\App\Http\Controllers\Haven\ExpensePayments::class, 'store'])->middleware(array (
  0 => 'auth',
  1 => 'tenant.member',
  2 => 'permission:finance.manage',
))->whereNumber('id');
Route::get('expenses', [\App\Http\Controllers\Haven\ExpenseRegister::class, 'index'])->middleware(array (
  0 => 'auth',
  1 => 'tenant.member',
  2 => 'permission:finance.view',
));
Route::get('expenses/create', [\App\Http\Controllers\Haven\UnitExpenses::class, 'create'])->middleware(array (
  0 => 'auth',
  1 => 'tenant.member',
  2 => 'permission:finance.manage',
));
Route::post('expenses', [\App\Http\Controllers\Haven\UnitExpenses::class, 'store'])->middleware(array (
  0 => 'auth',
  1 => 'tenant.member',
  2 => 'permission:finance.manage',
));
Route::get('payments/cleaners/statements', [\App\Http\Controllers\Haven\CleanerStatements::class, 'index'])->middleware(array (
  0 => 'auth',
  1 => 'tenant.member',
  2 => 'permission:finance.view',
));
Route::get('payments/cleaners/balances', [\App\Http\Controllers\Haven\CleanerBalances::class, 'index'])->middleware(array (
  0 => 'auth',
  1 => 'tenant.member',
  2 => 'permission:finance.view',
));
Route::get('payments/cleaners', [\App\Http\Controllers\Haven\CleanerPayments::class, 'index'])->middleware(array (
  0 => 'auth',
  1 => 'tenant.member',
  2 => 'permission:finance.view',
));
Route::get('payments/cleaners/create', [\App\Http\Controllers\Haven\CleanerPayments::class, 'create'])->middleware(array (
  0 => 'auth',
  1 => 'tenant.member',
  2 => 'permission:finance.view',
));
Route::post('payments/cleaners', [\App\Http\Controllers\Haven\CleanerPayments::class, 'store'])->middleware(array (
  0 => 'auth',
  1 => 'tenant.member',
  2 => 'permission:finance.view',
));
Route::get('reports/housekeeping', [\App\Http\Controllers\Haven\HousekeepingReports::class, 'index'])->middleware(array (
  0 => 'auth',
  1 => 'tenant.member',
  2 => 'permission:finance.view',
));
Route::get('reports/housekeeping/export', [\App\Http\Controllers\Haven\HousekeepingReports::class, 'export'])->middleware(array (
  0 => 'auth',
  1 => 'tenant.member',
  2 => 'permission:finance.view',
));
Route::get('settings/cleaning-rates', [\App\Http\Controllers\Haven\CleaningRates::class, 'index'])->middleware(array (
  0 => 'auth',
  1 => 'tenant.member',
  2 => 'permission:settings.manage',
));
Route::post('settings/cleaning-rates/update', [\App\Http\Controllers\Haven\CleaningRates::class, 'update'])->middleware(array (
  0 => 'auth',
  1 => 'tenant.member',
  2 => 'permission:settings.manage',
));
Route::get('q/{token}', [\App\Http\Controllers\Haven\Quotations::class, 'publicView']);
Route::post('q/{token}/payment', [\App\Http\Controllers\Haven\Quotations::class, 'submitPayment'])->middleware(array (
  0 => 'throttle:10,1',
));
Route::get('/', [\App\Http\Controllers\Haven\Dashboard::class, 'index'])->middleware(array (
  0 => 'auth',
  1 => 'tenant.member',
  2 => 'permission:dashboard.view',
));
Route::get('dashboard', [\App\Http\Controllers\Haven\Dashboard::class, 'index'])->middleware(array (
  0 => 'auth',
  1 => 'tenant.member',
  2 => 'permission:dashboard.view',
));
Route::get('availability', [\App\Http\Controllers\Haven\Availability::class, 'index'])->middleware(array (
  0 => 'auth',
  1 => 'tenant.member',
  2 => 'permission:availability.view',
));
Route::get('quotations', [\App\Http\Controllers\Haven\Quotations::class, 'index'])->middleware(array (
  0 => 'auth',
  1 => 'tenant.member',
  2 => 'permission:reservations.view',
));
Route::get('properties', [\App\Http\Controllers\Haven\Properties::class, 'index'])->middleware(array (
  0 => 'auth',
  1 => 'tenant.member',
  2 => 'permission:properties.view',
));
Route::get('properties/create', [\App\Http\Controllers\Haven\Properties::class, 'create'])->middleware(array (
  0 => 'auth',
  1 => 'tenant.member',
  2 => 'permission:properties.manage',
));
Route::post('properties/store', [\App\Http\Controllers\Haven\Properties::class, 'store'])->middleware(array (
  0 => 'auth',
  1 => 'tenant.member',
  2 => 'permission:properties.manage',
));
Route::get('properties/edit/{id}', [\App\Http\Controllers\Haven\Properties::class, 'edit'])->middleware(array (
  0 => 'auth',
  1 => 'tenant.member',
  2 => 'permission:properties.manage',
))->whereNumber('id');
Route::post('properties/update/{id}', [\App\Http\Controllers\Haven\Properties::class, 'update'])->middleware(array (
  0 => 'auth',
  1 => 'tenant.member',
  2 => 'permission:properties.manage',
))->whereNumber('id');
Route::get('units', [\App\Http\Controllers\Haven\Units::class, 'index'])->middleware(array (
  0 => 'auth',
  1 => 'tenant.member',
  2 => 'permission:units.view',
));
Route::get('units/create', [\App\Http\Controllers\Haven\Units::class, 'create'])->middleware(array (
  0 => 'auth',
  1 => 'tenant.member',
  2 => 'permission:units.manage',
));
Route::post('units/store', [\App\Http\Controllers\Haven\Units::class, 'store'])->middleware(array (
  0 => 'auth',
  1 => 'tenant.member',
  2 => 'permission:units.manage',
));
Route::get('units/edit/{id}', [\App\Http\Controllers\Haven\Units::class, 'edit'])->middleware(array (
  0 => 'auth',
  1 => 'tenant.member',
  2 => 'permission:units.manage',
))->whereNumber('id');
Route::post('units/update/{id}', [\App\Http\Controllers\Haven\Units::class, 'update'])->middleware(array (
  0 => 'auth',
  1 => 'tenant.member',
  2 => 'permission:units.manage',
))->whereNumber('id');
Route::get('reservations', [\App\Http\Controllers\Haven\Reservations::class, 'index'])->middleware(array (
  0 => 'auth',
  1 => 'tenant.member',
  2 => 'permission:reservations.view',
));
Route::get('reservations/create', [\App\Http\Controllers\Haven\Reservations::class, 'create'])->middleware(array (
  0 => 'auth',
  1 => 'tenant.member',
  2 => 'permission:reservations.manage',
));
Route::post('reservations/store', [\App\Http\Controllers\Haven\Reservations::class, 'store'])->middleware(array (
  0 => 'auth',
  1 => 'tenant.member',
  2 => 'permission:reservations.manage',
));
Route::get('staff', [\App\Http\Controllers\Haven\Staff::class, 'index'])->middleware(array (
  0 => 'auth',
  1 => 'tenant.member',
  2 => 'permission:staff.view',
));
Route::get('staff/create', [\App\Http\Controllers\Haven\Staff::class, 'create'])->middleware(array (
  0 => 'auth',
  1 => 'tenant.member',
  2 => 'permission:staff.manage',
));
Route::post('staff/store', [\App\Http\Controllers\Haven\Staff::class, 'store'])->middleware(array (
  0 => 'auth',
  1 => 'tenant.member',
  2 => 'permission:staff.manage',
));
Route::get('staff/edit/{id}', [\App\Http\Controllers\Haven\Staff::class, 'edit'])->middleware(array (
  0 => 'auth',
  1 => 'tenant.member',
  2 => 'permission:staff.manage',
))->whereNumber('id');
Route::post('staff/update/{id}', [\App\Http\Controllers\Haven\Staff::class, 'update'])->middleware(array (
  0 => 'auth',
  1 => 'tenant.member',
  2 => 'permission:staff.manage',
))->whereNumber('id');
Route::get('staff/{id}/account/create', [\App\Http\Controllers\Haven\StaffAccounts::class, 'create'])->middleware(array (
  0 => 'auth',
  1 => 'tenant.member',
  2 => 'permission:users.manage',
))->whereNumber('id');
Route::post('staff/{id}/account/store', [\App\Http\Controllers\Haven\StaffAccounts::class, 'store'])->middleware(array (
  0 => 'auth',
  1 => 'tenant.member',
  2 => 'permission:users.manage',
))->whereNumber('id');
Route::get('maintenance', [\App\Http\Controllers\Haven\Maintenance::class, 'index'])->middleware(array (
  0 => 'auth',
  1 => 'tenant.member',
  2 => 'permission:maintenance.view',
));
Route::get('maintenance/create', [\App\Http\Controllers\Haven\Maintenance::class, 'create'])->middleware(array (
  0 => 'auth',
  1 => 'tenant.member',
  2 => 'permission:maintenance.manage',
));
Route::post('maintenance/store', [\App\Http\Controllers\Haven\Maintenance::class, 'store'])->middleware(array (
  0 => 'auth',
  1 => 'tenant.member',
  2 => 'permission:maintenance.manage',
));
Route::get('maintenance/edit/{id}', [\App\Http\Controllers\Haven\Maintenance::class, 'edit'])->middleware(array (
  0 => 'auth',
  1 => 'tenant.member',
  2 => 'permission:maintenance.manage',
))->whereNumber('id');
Route::post('maintenance/update/{id}', [\App\Http\Controllers\Haven\Maintenance::class, 'update'])->middleware(array (
  0 => 'auth',
  1 => 'tenant.member',
  2 => 'permission:maintenance.manage',
))->whereNumber('id');
Route::get('payments/cleaners/earnings', [\App\Http\Controllers\Haven\CleanerEarnings::class, 'index'])->middleware(array (
  0 => 'auth',
  1 => 'tenant.member',
));
Route::get('housekeeping/my-cleanings/new', [\App\Http\Controllers\Haven\Housekeeping::class, 'cleanerCreate'])->middleware(array (
  0 => 'auth',
  1 => 'tenant.member',
));
Route::post('housekeeping/my-cleanings', [\App\Http\Controllers\Haven\Housekeeping::class, 'cleanerStore'])->middleware(array (
  0 => 'auth',
  1 => 'tenant.member',
));
Route::get('housekeeping', [\App\Http\Controllers\Haven\Housekeeping::class, 'index'])->middleware(array (
  0 => 'auth',
  1 => 'tenant.member',
  2 => 'permission:housekeeping.view',
));
Route::get('housekeeping/create', [\App\Http\Controllers\Haven\Housekeeping::class, 'create'])->middleware(array (
  0 => 'auth',
  1 => 'tenant.member',
  2 => 'permission:housekeeping.manage',
));
Route::post('housekeeping/store', [\App\Http\Controllers\Haven\Housekeeping::class, 'store'])->middleware(array (
  0 => 'auth',
  1 => 'tenant.member',
  2 => 'permission:housekeeping.manage',
));
Route::get('housekeeping/edit/{id}', [\App\Http\Controllers\Haven\Housekeeping::class, 'edit'])->middleware(array (
  0 => 'auth',
  1 => 'tenant.member',
  2 => 'permission:housekeeping.manage',
))->whereNumber('id');
Route::post('housekeeping/update/{id}', [\App\Http\Controllers\Haven\Housekeeping::class, 'update'])->middleware(array (
  0 => 'auth',
  1 => 'tenant.member',
  2 => 'permission:housekeeping.manage',
))->whereNumber('id');
Route::get('reservations/{id}/edit', [\App\Http\Controllers\Haven\Reservations::class, 'edit'])->middleware(array (
  0 => 'auth',
  1 => 'tenant.member',
  2 => 'permission:reservations.manage',
))->whereNumber('id');
Route::post('reservations/update/{id}', [\App\Http\Controllers\Haven\Reservations::class, 'update'])->middleware(array (
  0 => 'auth',
  1 => 'tenant.member',
  2 => 'permission:reservations.manage',
))->whereNumber('id');
Route::get('reservations/{id}', [\App\Http\Controllers\Haven\Reservations::class, 'show'])->middleware(array (
  0 => 'auth',
  1 => 'tenant.member',
  2 => 'permission:reservations.view',
))->whereNumber('id');
Route::post('housekeeping/verify-from-earnings', [\App\Http\Controllers\Haven\Housekeeping::class, 'verifyFromEarnings'])->middleware(array (
  0 => 'auth',
  1 => 'tenant.member',
  2 => 'permission:housekeeping.verify',
));
Route::get('reservations/{id}/quotation', [\App\Http\Controllers\Haven\Quotations::class, 'preview'])->middleware(array (
  0 => 'auth',
  1 => 'tenant.member',
  2 => 'permission:reservations.view',
))->whereNumber('id');
Route::post('reservations/{id}/quotation/issue', [\App\Http\Controllers\Haven\Quotations::class, 'issue'])->middleware(array (
  0 => 'auth',
  1 => 'tenant.member',
  2 => 'permission:reservations.manage',
))->whereNumber('id');
Route::get('quotations/payment-claims', [\App\Http\Controllers\Haven\Quotations::class, 'claims'])->middleware(array (
  0 => 'auth',
  1 => 'tenant.member',
  2 => 'permission:finance.manage',
));
Route::post('quotations/payment-claims/{id}/review', [\App\Http\Controllers\Haven\Quotations::class, 'review'])->middleware(array (
  0 => 'auth',
  1 => 'tenant.member',
  2 => 'permission:finance.manage',
))->whereNumber('id');
Route::post('housekeeping/progress/{id}', [\App\Http\Controllers\Haven\Housekeeping::class, 'updateProgress'])->middleware(array (
  0 => 'auth',
  1 => 'tenant.member',
  2 => 'permission:housekeeping.view',
))->whereNumber('id');
Route::get('owner-remittances/export', [\App\Http\Controllers\Haven\OwnerRemittances::class, 'export'])->middleware(['auth','tenant.member','permission:finance.view']);
Route::get('home', function () { return haven_landing() === site_url('home') ? view('auth.company-home') : redirect()->to(haven_landing()); })->middleware(['auth','tenant.member']);
Route::get('payments/cleaners/{id}', [\App\Http\Controllers\Haven\CleanerPaymentDetails::class, 'show'])->whereNumber('id')->middleware(['auth','tenant.member','permission:finance.view']);
