<?php
use App\Http\Controllers\AuthController;
use Illuminate\Support\Facades\Route;
Route::get('/login', [AuthController::class,'form'])->name('login')->middleware('guest');
Route::post('/login', [AuthController::class,'login'])->middleware(['guest','throttle:login']);
Route::post('/logout', [AuthController::class,'logout'])->middleware('auth');
Route::get('/', [AuthController::class,'home'])->middleware('auth');
Route::prefix('t/{tenant}')->middleware('tenant')->group(base_path('routes/haven.php'));
