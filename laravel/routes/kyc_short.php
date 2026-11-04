<?php

use App\Http\Controllers\KycShortLinkController;
use Illuminate\Support\Facades\Route;

Route::get('/k/{code}', [KycShortLinkController::class, 'show'])
    ->where('code', '[A-Za-z0-9_-]{16}')->middleware('throttle:30,1')->name('kyc.short');
