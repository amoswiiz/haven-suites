<?php
namespace App\Providers;
use Illuminate\Support\ServiceProvider;
class AppServiceProvider extends ServiceProvider
{
    public function register(): void { $this->app->scoped(\App\Support\Migration\Connection::class); }
    public function boot(): void {
        date_default_timezone_set(config('app.timezone'));
        \Illuminate\Support\Facades\RateLimiter::for('login',fn($request)=>[
            \Illuminate\Cache\RateLimiting\Limit::perMinute(6)->by(strtolower((string)$request->input('email')).'|'.$request->ip()),
            \Illuminate\Cache\RateLimiting\Limit::perMinute(60)->by('login-ip|'.$request->ip()),
        ]);
    }
}
