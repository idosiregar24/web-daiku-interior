<?php

use App\Http\Controllers\Site\SiteController;
use Illuminate\Support\Facades\Route;

/*
 * Sprint 20 — the public company profile's pages besides `/` (which sits
 * in routes/web.php: it needs the session to send staff to their app).
 * Registered in bootstrap/app.php under the `site` middleware group: no
 * session, no cookies, no CSRF — read-only GET pages (K1: no form), so
 * they can be cached for five minutes by browsers and proxies.
 */
Route::get('portofolio', [SiteController::class, 'portfolioIndex'])->name('site.portfolio.index');
Route::get('portofolio/{slug}', [SiteController::class, 'portfolioShow'])
    ->where('slug', '[a-z0-9\-]+')
    ->name('site.portfolio.show');
Route::get('layanan/{slug}', [SiteController::class, 'service'])
    ->where('slug', '[a-z0-9\-]+')
    ->name('site.services.show');

Route::get('sitemap.xml', [SiteController::class, 'sitemap'])->name('site.sitemap');
Route::get('robots.txt', [SiteController::class, 'robots'])->name('site.robots');
