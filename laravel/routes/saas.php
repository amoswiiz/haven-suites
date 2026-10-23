<?php

use App\Http\Controllers\BillingController;
use App\Http\Controllers\CompanyInvitationController;
use App\Http\Controllers\CompanySettingsController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\PasswordRecoveryController;
use App\Http\Controllers\PlatformController;
use App\Http\Controllers\SaasAccountController;
use App\Http\Controllers\SubscriptionController;
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
