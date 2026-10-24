<?php

use App\Http\Controllers\BillingController;
use App\Http\Controllers\CommunicationController;
use App\Http\Controllers\CompanyInvitationController;
use App\Http\Controllers\CompanySettingsController;
use App\Http\Controllers\InvestorController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\PasswordRecoveryController;
use App\Http\Controllers\PlatformController;
use App\Http\Controllers\SaasAccountController;
use App\Http\Controllers\SalesController;
use App\Http\Controllers\SubscriptionController;
use App\Http\Controllers\UnitAccountingController;
use Illuminate\Support\Facades\Route;

Route::view('pricing', 'saas.pricing')->name('pricing');

Route::middleware('guest')->group(function (): void {
    Route::get('register', [SaasAccountController::class, 'create'])->name('register');
    Route::post('register', [SaasAccountController::class, 'store'])->middleware('throttle:5,1');
    Route::get('forgot-password', [PasswordRecoveryController::class, 'request'])->name('password.request');
    Route::post('forgot-password', [PasswordRecoveryController::class, 'email'])->name('password.email')->middleware('throttle:5,1');
    Route::get('reset-password/{token}', [PasswordRecoveryController::class, 'reset'])->name('password.reset');
    Route::post('reset-password', [PasswordRecoveryController::class, 'update'])->name('password.update')->middleware('throttle:5,1');
});
Route::middleware('auth')->group(function (): void {
    Route::get('verify-email', [SaasAccountController::class, 'notice'])->name('verification.notice');
    Route::get('verify-email/{id}/{hash}', [SaasAccountController::class, 'verify'])->middleware('signed')->name('verification.verify');
    Route::post('email/verification-notification', [SaasAccountController::class, 'resend'])->middleware('throttle:6,1')->name('verification.send');
    Route::middleware('saas.verified')->group(function (): void {
        Route::get('onboarding', [OnboardingController::class, 'company'])->name('onboarding.company');
        Route::post('onboarding', [OnboardingController::class, 'provision'])->middleware('throttle:3,10')->name('onboarding.provision');
        Route::get('invitations/{token}', [CompanyInvitationController::class, 'show'])->name('invitations.show');
        Route::post('invitations/{token}', [CompanyInvitationController::class, 'accept'])->name('invitations.accept');
    });
    Route::prefix('platform')->middleware(['saas.verified', 'platform.admin'])->group(function (): void {
        Route::get('/', [PlatformController::class, 'index'])->name('platform.index');
        Route::post('companies/{id}/modules', [PlatformController::class, 'modules'])->whereNumber('id')->name('platform.company.modules');
        Route::post('companies/{id}', [PlatformController::class, 'update'])->whereNumber('id')->name('platform.company.update');
        Route::post('payments/{id}/approve', [BillingController::class, 'approve'])->whereNumber('id')->name('platform.payment.approve');
    });
});
Route::prefix('t/{tenant}')->middleware(['auth', 'saas.verified', 'tenant', 'tenant.member', 'tenant.write'])->group(function (): void {
    Route::get('onboarding', [OnboardingController::class, 'property'])->name('onboarding.property');
    Route::post('onboarding', [OnboardingController::class, 'finish'])->name('onboarding.finish');
    Route::get('company/settings', [CompanySettingsController::class, 'edit'])->name('company.settings');
    Route::post('company/settings', [CompanySettingsController::class, 'update'])->name('company.settings.update');
    Route::get('company/team', [CompanyInvitationController::class, 'index'])->name('company.team');
    Route::post('company/team/members/{id}', [CompanyInvitationController::class, 'updateMember'])->whereNumber('id')->name('company.member.update');
    Route::post('company/team/members/{id}/remove', [CompanyInvitationController::class, 'removeMember'])->whereNumber('id')->name('company.member.remove');
    Route::post('company/team/invitations', [CompanyInvitationController::class, 'store'])->name('company.invite');
    Route::post('company/team/invitations/{id}/revoke', [CompanyInvitationController::class, 'revoke'])->whereNumber('id')->name('company.invite.revoke');
    Route::get('company/subscription', [SubscriptionController::class, 'show'])->name('company.subscription');
    Route::post('company/subscription/request', [SubscriptionController::class, 'request'])->name('company.plan.request');
    Route::post('company/subscription/checkout', [BillingController::class, 'start'])->middleware('throttle:6,1')->name('company.billing.start');
    Route::post('company/subscription/payments/{reference}', [BillingController::class, 'evidence'])->name('company.billing.evidence');
    Route::get('company/subscription/payments/{reference}/invoice', [BillingController::class, 'invoice'])->name('company.billing.invoice');
});
Route::post('billing/webhooks/stripe', [BillingController::class, 'stripe'])->middleware('throttle:60,1')->name('billing.stripe');
Route::post('billing/webhooks/mpesa/{token}', [BillingController::class, 'mpesa'])->middleware('throttle:60,1')->name('billing.mpesa');

Route::prefix('t/{tenant}/sales')->middleware(['auth', 'saas.verified', 'tenant', 'tenant.member', 'tenant.write'])->group(function (): void {
    Route::get('/', [SalesController::class, 'index'])->middleware('permission:sales.view')->name('sales.index');
    Route::get('new', [SalesController::class, 'create'])->middleware('permission:sales.manage')->name('sales.create');
    Route::post('/', [SalesController::class, 'store'])->middleware('permission:sales.manage')->name('sales.store');
    Route::get('agents', [SalesController::class, 'agents'])->middleware('permission:agents.view')->name('sales.agents');
    Route::post('agents', [SalesController::class, 'agent'])->middleware('permission:agents.manage')->name('sales.agent.store');
    Route::get('connections', [SalesController::class, 'connections'])->middleware('permission:messaging.view')->name('sales.connections');
    Route::get('{id}', [SalesController::class, 'show'])->whereNumber('id')->middleware('permission:sales.view')->name('sales.show');
    Route::post('{id}', [SalesController::class, 'update'])->whereNumber('id')->middleware('permission:sales.manage')->name('sales.update');
    Route::post('{id}/notes', [SalesController::class, 'note'])->whereNumber('id')->middleware('permission:sales.manage')->name('sales.note');
    Route::post('{id}/details', [SalesController::class, 'details'])->whereNumber('id')->middleware('permission:sales.manage')->name('sales.details');
    Route::post('{id}/messages', [SalesController::class, 'message'])->whereNumber('id')->middleware(['permission:sales.manage', 'permission:messaging.manage'])->name('sales.message');
    Route::post('{id}/quotes', [SalesController::class, 'quote'])->whereNumber('id')->middleware('permission:sales.manage')->name('sales.quote');
    Route::get('{id}/quotes/{quote}', [SalesController::class, 'quotation'])->whereNumber(['id', 'quote'])->middleware('permission:sales.view')->name('sales.quotation');
    Route::post('{id}/win', [SalesController::class, 'win'])->whereNumber('id')->middleware('permission:sales.manage')->name('sales.win');
});

Route::get('investor-login', fn () => redirect('/login'))->name('investor.login');
Route::prefix('t/{tenant}/communications')->middleware(['auth', 'saas.verified', 'tenant', 'tenant.member', 'tenant.write'])->group(function (): void {
    Route::get('sms', [CommunicationController::class, 'index'])->middleware('permission:messaging.view')->name('communications.sms');
    Route::post('sms', [CommunicationController::class, 'send'])->middleware(['permission:messaging.manage', 'throttle:10,1'])->name('communications.sms.send');
});
Route::prefix('t/{tenant}')->middleware(['auth', 'saas.verified', 'tenant', 'tenant.member', 'tenant.write'])->group(function (): void {
    Route::get('investors', [InvestorController::class, 'index'])->middleware('permission:investors.view')->name('investors.index');
    Route::post('investors', [InvestorController::class, 'store'])->middleware('permission:investors.manage')->name('investors.store');
    Route::get('investors/{id}', [InvestorController::class, 'show'])->whereNumber('id')->middleware('permission:investors.view')->name('investors.show');
    Route::post('investors/{id}', [InvestorController::class, 'update'])->whereNumber('id')->middleware('permission:investors.manage')->name('investors.update');
    Route::post('investors/{id}/invite', [InvestorController::class, 'invite'])->whereNumber('id')->middleware(['permission:investors.manage', 'throttle:6,1'])->name('investors.invite');
    Route::post('investors/{id}/assignments', [InvestorController::class, 'assignment'])->whereNumber('id')->middleware('permission:investors.manage')->name('investors.assignment');
    Route::post('investors/{id}/assignments/{assignment}/end', [InvestorController::class, 'endAssignment'])->whereNumber(['id', 'assignment'])->middleware('permission:investors.manage')->name('investors.assignment.end');
    Route::post('investors/{id}/statements', [InvestorController::class, 'publish'])->whereNumber('id')->middleware(['permission:investors.manage', 'permission:finance.manage'])->name('investors.publish');
    Route::post('investors/{id}/payouts', [InvestorController::class, 'payout'])->whereNumber('id')->middleware(['permission:investors.manage', 'permission:finance.manage'])->name('investors.payout');
    Route::get('investor-portal', [InvestorController::class, 'portal'])->middleware('permission:investor.portal')->name('investor.portal');
    Route::get('investor-portal/reports', [InvestorController::class, 'reports'])->middleware('permission:investor.portal')->name('investor.reports');
    Route::get('investor-portal/{section}', [InvestorController::class, 'portal'])->where('section', 'properties|statements|payouts|agreements|settings')->middleware('permission:investor.portal')->name('investor.section');
    Route::post('investor-portal/preferences', [InvestorController::class, 'preferences'])->middleware('permission:investor.portal')->name('investor.preferences');
    Route::post('investor-portal/notifications/{id}/read', [InvestorController::class, 'read'])->whereNumber('id')->middleware('permission:investor.portal')->name('investor.read');
    Route::get('investor-portal/statements/{id}', [InvestorController::class, 'statement'])->whereNumber('id')->name('investor.statement');
    Route::get('investor-notifications', [InvestorController::class, 'notifications'])->middleware('permission:notifications.view')->name('investor.notifications');
    Route::post('investor-notifications/settings', [InvestorController::class, 'notificationSettings'])->middleware('permission:notifications.manage')->name('investor.notification.settings');
    Route::post('investor-notifications/{id}/retry', [InvestorController::class, 'retry'])->whereNumber('id')->middleware('permission:notifications.manage')->name('investor.notification.retry');
    Route::post('investors/{id}/notice', [InvestorController::class, 'notice'])->whereNumber('id')->middleware('permission:investors.manage')->name('investors.notice');
});

Route::prefix('t/{tenant}/accounting/units')->middleware(['auth', 'saas.verified', 'tenant', 'tenant.member', 'tenant.write'])->group(function (): void {
    Route::get('/', [UnitAccountingController::class, 'index'])->middleware('permission:finance.view')->name('accounting.units');
    Route::post('/', [UnitAccountingController::class, 'store'])->middleware('permission:finance.manage')->name('accounting.units.store');
    Route::post('rent', [UnitAccountingController::class, 'rent'])->middleware('permission:finance.manage')->name('accounting.rent');
});
