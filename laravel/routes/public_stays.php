<?php

use App\Http\Controllers\PublicEnquiryController;
use App\Http\Controllers\PublicReviewController;
use App\Http\Controllers\PublicStayController;
use Illuminate\Support\Facades\Route;

Route::get('/l/{code}', [PublicStayController::class, 'shortLink'])->where('code', '[0-9a-z]{1,8}')->middleware('throttle:60,1')->name('public-stays.short');

Route::prefix('stays/{company}')->where(['company' => '[a-z0-9]+(?:-[a-z0-9]+)*'])->name('public-stays.')->group(function (): void {
    Route::get('/', [PublicStayController::class, 'index'])->name('index');
    Route::get('sitemap.xml', [PublicStayController::class, 'sitemap'])->name('sitemap');
    Route::get('enquiries/{receipt}', [PublicEnquiryController::class, 'received'])->where('receipt', '[a-f0-9]{64}')->name('received');
    Route::get('logo', [PublicStayController::class, 'logo'])->name('logo');
    Route::get('about', [PublicStayController::class, 'about'])->name('about');
    Route::get('faq', [PublicStayController::class, 'faq'])->name('faq');
    Route::get('blog', [PublicStayController::class, 'blog'])->name('blog');
    Route::get('blog/{slug}', [PublicStayController::class, 'post'])->where('slug', '[a-z0-9]+(?:-[a-z0-9]+)*')->name('blog.show');
    Route::get('blog/{slug}/cover', [PublicStayController::class, 'blogCover'])->where('slug', '[a-z0-9]+(?:-[a-z0-9]+)*')->name('blog.cover');
    Route::get('in/{city}', [PublicStayController::class, 'area'])->where('city', '[a-z0-9]+(?:-[a-z0-9]+)*')->name('city');
    Route::get('in/{city}/{area}', [PublicStayController::class, 'area'])->where(['city' => '[a-z0-9]+(?:-[a-z0-9]+)*', 'area' => '[a-z0-9]+(?:-[a-z0-9]+)*'])->name('area');
    Route::get('review/{token}', [PublicReviewController::class, 'show'])->where('token', '[A-Za-z0-9]{40}')->middleware('throttle:30,1')->name('review');
    Route::post('review/{token}', [PublicReviewController::class, 'submit'])->where('token', '[A-Za-z0-9]{40}')->middleware('throttle:5,1')->name('review.submit');
    Route::get('track', [PublicEnquiryController::class, 'lookupForm'])->name('track');
    Route::post('track', [PublicEnquiryController::class, 'lookup'])->middleware('throttle:5,1')->name('track.submit');
    Route::get('{slug}/photos/{photo}', [PublicStayController::class, 'photo'])->whereNumber('photo')->name('photo');
    Route::get('{slug}/calendar', [PublicStayController::class, 'calendar'])->middleware('throttle:120,1')->name('calendar');
    Route::post('{listing}/enquire', [PublicEnquiryController::class, 'store'])->middleware('throttle:10,1')->name('enquire');
    Route::get('{slug}', [PublicStayController::class, 'show'])->name('show');
});
