<?php

use App\Http\Controllers\AuthController;
use Illuminate\Support\Facades\Route;

Route::get('/login', [AuthController::class, 'form'])->name('login')->middleware('guest');
Route::post('/login', [AuthController::class, 'login'])->middleware(['guest', 'throttle:login']);
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth');
Route::get('/', [AuthController::class, 'home'])->middleware('auth');
require __DIR__.'/saas.php';
Route::get('/impersonation', [\App\Http\Controllers\ImpersonationController::class, 'index'])->middleware(['auth', 'saas.verified']);
Route::post('/impersonation', [\App\Http\Controllers\ImpersonationController::class, 'start'])->middleware(['auth', 'saas.verified', 'throttle:6,1']);
Route::post('/impersonation/stop', [\App\Http\Controllers\ImpersonationController::class, 'stop'])->middleware('auth');
Route::prefix('t/{tenant}')->middleware(['saas.verified', 'tenant', 'tenant.write'])->group(base_path('routes/haven.php'));
