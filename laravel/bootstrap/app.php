<?php

use App\Http\Middleware\EnsurePlatformAdmin;
use App\Http\Middleware\EnsureSaasVerified;
use App\Http\Middleware\EnsureTenantMember;
use App\Http\Middleware\RequirePermission;
use App\Http\Middleware\ResolveTenant;
use App\Http\Middleware\TenantWriteAccess;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(web: __DIR__.'/../routes/web.php', commands: __DIR__.'/../routes/console.php', health: '/up')
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'tenant' => ResolveTenant::class,
            'tenant.member' => EnsureTenantMember::class,
            'permission' => RequirePermission::class,
            'saas.verified' => EnsureSaasVerified::class,
            'platform.admin' => EnsurePlatformAdmin::class,
            'tenant.write' => TenantWriteAccess::class,
        ]);
        $middleware->redirectGuestsTo('/login');
        $middleware->web(append: [\App\Http\Middleware\ImpersonateUser::class]);
        $middleware->preventRequestForgery(except: ['billing/webhooks/stripe', 'billing/webhooks/mpesa/*']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {})
    ->create();
