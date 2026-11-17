<?php

use App\Http\Controllers\PublicEnquiryController;
use App\Http\Controllers\PublicReviewController;
use App\Http\Controllers\PublicStayController;
use App\Http\Middleware\PublicSiteCompany;
use App\Http\Middleware\RedirectToPublicDomain;
use App\Support\PublicSiteUrl;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
 * The public website. Each company's pages live under /stays/{company}. When PUBLIC_SITE_DOMAIN is set, the same
 * pages are also served on that domain without the prefix (for one company), together with redirects from the old
 * WordPress website's addresses, and the /stays/{company} addresses redirect there.
 */
$publicPages = function (bool $onDomain): void {
    Route::get('/', [PublicStayController::class, 'index'])->name('index');
    Route::get('sitemap.xml', [PublicStayController::class, 'sitemap'])->name('sitemap');
    Route::get('enquiries/{receipt}', [PublicEnquiryController::class, 'received'])->where('receipt', '[a-f0-9]{64}')->name('received');
    Route::get('logo', [PublicStayController::class, 'logo'])->name('logo');
    Route::get('favicon.png', [PublicStayController::class, 'favicon'])->name('favicon');
    Route::get('homepage-photo', [PublicStayController::class, 'heroPhoto'])->name('hero-photo');
    Route::get('team/{name}', [PublicStayController::class, 'teamPhoto'])->where('name', 'team-[A-Za-z0-9]{16}\.jpg')->name('team-photo');
    Route::get('about', [PublicStayController::class, 'about'])->name('about');
    Route::get('faq', [PublicStayController::class, 'faq'])->name('faq');
    Route::get('saved', [PublicStayController::class, 'saved'])->name('saved');
    Route::get('list-your-property', [PublicStayController::class, 'owners'])->name('owners');
    Route::post('list-your-property', [PublicStayController::class, 'ownerEnquiry'])->middleware('throttle:5,1')->name('owners.enquire');
    Route::get('blog', [PublicStayController::class, 'blog'])->name('blog');
    Route::get('blog/{slug}', [PublicStayController::class, 'post'])->where('slug', '[a-z0-9]+(?:-[a-z0-9]+)*')->name('blog.show');
    Route::get('blog/images/{name}', [PublicStayController::class, 'blogImage'])->where('name', '[A-Za-z0-9]{32}')->name('blog.image');
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
    // On the main domain a single-segment address may also be an old WordPress page or article.
    Route::get('{slug}', [PublicStayController::class, $onDomain ? 'domainPage' : 'show'])->name('show');
};

// One robots.txt for every address, pointing search engines to the website's sitemap when it has its own domain.
// Routes for a domain are matched before routes without one, so the main domain registers its own copy.
$robots = fn () => response("User-agent: *\nDisallow:\n".(PublicSiteUrl::enabled() ? "\nSitemap: ".PublicSiteUrl::root()."/sitemap.xml\n" : ''), 200)
    ->header('Content-Type', 'text/plain; charset=UTF-8');
Route::get('robots.txt', $robots)->name('robots');

if ($publicDomain = PublicSiteUrl::domain()) {
    Route::domain('www.'.$publicDomain)->group(function (): void {
        Route::any('{path?}', fn (Request $request) => redirect()->to(PublicSiteUrl::root().$request->getRequestUri(), 301))->where('path', '.*');
    });
    Route::domain($publicDomain)->get('robots.txt', $robots)->name('site.robots');
    Route::domain($publicDomain)->middleware(PublicSiteCompany::class)->name('site.')->group(function () use ($publicPages): void {
        Route::get('property/{slug}', [PublicStayController::class, 'oldProperty'])->where('slug', '[A-Za-z0-9_-]+');
        Route::get('city/{city}', [PublicStayController::class, 'oldCity'])->where('city', '[A-Za-z0-9_-]+');
        Route::get('label/{label}', [PublicStayController::class, 'oldLabel'])->where('label', '[A-Za-z0-9_-]+');
        Route::get('{section}/{rest}', [PublicStayController::class, 'oldSection'])
            ->where(['section' => 'status|agency|agent|agents|category|tag|courses|course|property_city|property_area|author|page|feed', 'rest' => '.*']);
        $publicPages(true);
    });
}

Route::get('/l/{code}', [PublicStayController::class, 'shortLink'])->where('code', '[0-9a-z]{1,8}')->middleware('throttle:60,1')->name('public-stays.short');

Route::prefix('stays/{company}')->where(['company' => '[a-z0-9]+(?:-[a-z0-9]+)*'])->middleware(RedirectToPublicDomain::class)->name('public-stays.')
    ->group(fn () => $publicPages(false));
