<?php

namespace App\Providers;

use App\Support\Migration\Connection;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(Connection::class);
    }

    public function boot(): void
    {
        date_default_timezone_set(config('app.timezone'));
        RateLimiter::for('login', fn ($request) => [
            Limit::perMinute(6)->by(strtolower((string) $request->input('email')).'|'.$request->ip()),
            Limit::perMinute(60)->by('login-ip|'.$request->ip()),
        ]);
    }
}
