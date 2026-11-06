<?php

require __DIR__.'/kyc_short.php';
require __DIR__.'/public_stays.php';

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ExternalCalendarController;
use App\Http\Controllers\ImpersonationController;
use Illuminate\Support\Facades\Route;

Route::get('/calendars/{token}.ics', [ExternalCalendarController::class, 'export'])
    ->where('token', '[a-f0-9]{64}')->middleware('throttle:120,1')->name('calendar.export');

Route::get('/login', [AuthController::class, 'form'])->name('login')->middleware('guest');
Route::post('/login', [AuthController::class, 'login'])->middleware(['guest', 'throttle:login']);
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth');
Route::get('/', [AuthController::class, 'home'])->middleware('auth');
require __DIR__.'/saas.php';
Route::get('/impersonation', [ImpersonationController::class, 'index'])->middleware(['auth', 'saas.verified']);
Route::post('/impersonation', [ImpersonationController::class, 'start'])->middleware(['auth', 'saas.verified', 'throttle:6,1']);
Route::post('/impersonation/stop', [ImpersonationController::class, 'stop'])->middleware('auth');
Route::prefix('t/{tenant}')->middleware(['saas.verified', 'tenant', 'tenant.write'])->group(base_path('routes/haven.php'));
