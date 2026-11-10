<?php

use App\Http\Controllers\PartnerRequestController;
use App\Services\PartnerAvailabilityRequests;
use Illuminate\Support\Facades\Route;

Route::get('/p/{code}', [PartnerRequestController::class, 'show'])
    ->where('code', PartnerAvailabilityRequests::CODE_PATTERN)->middleware('throttle:30,1')->name('partner-request.show');
Route::post('/p/{code}', [PartnerRequestController::class, 'respond'])
    ->where('code', PartnerAvailabilityRequests::CODE_PATTERN)->middleware('throttle:10,1')->name('partner-request.respond');
