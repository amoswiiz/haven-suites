<?php

use App\Http\Controllers\PartnerRequestController;
use App\Services\PartnerAvailabilityRequests;
use Illuminate\Support\Facades\Route;

Route::get('/p/{code}', [PartnerRequestController::class, 'show'])
    ->where('code', PartnerAvailabilityRequests::CODE_PATTERN)->middleware('throttle:30,1')->name('partner-request.show');
Route::get('/p/{code}/featured', [PartnerRequestController::class, 'featured'])
    ->where('code', PartnerAvailabilityRequests::CODE_PATTERN)->middleware('throttle:120,1')->name('partner-request.featured');
Route::get('/p/{code}/photos/{photo}', [PartnerRequestController::class, 'photo'])
    ->where('code', PartnerAvailabilityRequests::CODE_PATTERN)->whereNumber('photo')->middleware('throttle:120,1')->name('partner-request.photo');
Route::post('/p/{code}', [PartnerRequestController::class, 'respond'])
    ->where('code', PartnerAvailabilityRequests::CODE_PATTERN)->middleware('throttle:10,1')->name('partner-request.respond');
