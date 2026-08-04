<?php

/*
 * SPDX-FileCopyrightText: 2025-2026 Zorin Projects S.P. <creator@localzet.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Localzet\LaravelAnalytics\Http\Controllers\AnalyticsAdminController;
use Localzet\LaravelAnalytics\Http\Controllers\AnalyticsCollectorController;

Route::post((string) config('personal-analytics.collector_path'), AnalyticsCollectorController::class)
    ->middleware(['personal-analytics-cors', 'throttle:personal-analytics-ingest'])
    ->name('personal-analytics.collect');
Route::options((string) config('personal-analytics.collector_path'), static fn () => response()->noContent())
    ->middleware('personal-analytics-cors');

Route::prefix((string) config('personal-analytics.admin_prefix'))
    ->middleware([...(array) config('personal-analytics.admin_middleware'), 'personal-analytics-admin-security'])
    ->name('personal-analytics.admin.')
    ->group(function (): void {
        Route::get('/', [AnalyticsAdminController::class, 'index'])->name('index');
        Route::get('/assets/{asset}', [AnalyticsAdminController::class, 'asset'])
            ->whereIn('asset', ['admin.js', 'admin.css', 'admin.js.LEGAL.txt'])->name('asset');
        Route::middleware(['personal-analytics-admin', 'throttle:personal-analytics-admin'])->group(function (): void {
            Route::get('/api/{report}', [AnalyticsAdminController::class, 'report'])->name('report');
            Route::get('/replays/{replay}', [AnalyticsAdminController::class, 'replay'])->whereUuid('replay')->name('replay');
            Route::get('/heatmaps/{heatmap}/{device}/snapshot', [AnalyticsAdminController::class, 'heatmapSnapshot'])
                ->where('heatmap', '[a-zA-Z0-9._:-]+')->whereIn('device', ['desktop', 'tablet', 'mobile'])->name('heatmap.snapshot');
        });
    });
