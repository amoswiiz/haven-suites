<?php

use App\Http\Controllers\PublicEnquiryController;
use App\Services\EnquiryTracking;
use Illuminate\Support\Facades\Route;

Route::get('/e/{code}', [PublicEnquiryController::class, 'track'])
    ->where('code', EnquiryTracking::CODE_PATTERN)->middleware('throttle:30,1')->name('enquiry.track');
