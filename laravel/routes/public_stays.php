<?php

use App\Http\Controllers\PublicEnquiryController;
use App\Http\Controllers\PublicStayController;
use Illuminate\Support\Facades\Route;

Route::prefix('stays/{company}')->where(['company' => '[a-z0-9]+(?:-[a-z0-9]+)*'])->name('public-stays.')->group(function (): void {
    Route::get('/', [PublicStayController::class, 'index'])->name('index');
    Route::get('sitemap.xml', [PublicStayController::class, 'sitemap'])->name('sitemap');
    Route::get('enquiries/{receipt}', [PublicEnquiryController::class, 'received'])->where('receipt', '[a-f0-9]{64}')->name('received');
    Route::get('logo', [PublicStayController::class, 'logo'])->name('logo');
    Route::get('track', [PublicEnquiryController::class, 'lookupForm'])->name('track');
    Route::post('track', [PublicEnquiryController::class, 'lookup'])->middleware('throttle:5,1')->name('track.submit');
    Route::get('{slug}/photos/{photo}', [PublicStayController::class, 'photo'])->whereNumber('photo')->name('photo');
    Route::get('{slug}/calendar', [PublicStayController::class, 'calendar'])->middleware('throttle:120,1')->name('calendar');
    Route::post('{listing}/enquire', [PublicEnquiryController::class, 'store'])->middleware('throttle:10,1')->name('enquire');
    Route::get('{slug}', [PublicStayController::class, 'show'])->name('show');
});
