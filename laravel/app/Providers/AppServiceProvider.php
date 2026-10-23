<?php
namespace App\Providers;
use Illuminate\Support\ServiceProvider;
class AppServiceProvider extends ServiceProvider
{
    public function register(): void { $this->app->scoped(\App\Support\Migration\Connection::class); }
    public function boot(): void { date_default_timezone_set(config('app.timezone')); }
}
