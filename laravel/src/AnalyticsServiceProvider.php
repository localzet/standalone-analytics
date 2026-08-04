<?php
/*
 * SPDX-FileCopyrightText: 2025-2026 Zorin Projects S.P. <creator@localzet.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Localzet\LaravelAnalytics;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Localzet\LaravelAnalytics\Commands\AggregateAnalyticsCommand;
use Localzet\LaravelAnalytics\Commands\MaintainAnalyticsCommand;
use Localzet\LaravelAnalytics\Contracts\GeoResolver;
use Localzet\LaravelAnalytics\Contracts\UserAgentResolver;
use Localzet\LaravelAnalytics\Services\ReplayStorage;
use Localzet\LaravelAnalytics\Services\HeatmapStorage;
use Localzet\LaravelAnalytics\Support\NullGeoResolver;
use Localzet\LaravelAnalytics\Support\NullUserAgentResolver;

final class AnalyticsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(dirname(__DIR__) . '/config/personal-analytics.php', 'personal-analytics');
        $this->app->bind(GeoResolver::class, NullGeoResolver::class);
        $this->app->bind(UserAgentResolver::class, NullUserAgentResolver::class);
        $this->app->singleton(ReplayStorage::class);
        $this->app->singleton(HeatmapStorage::class);
    }

    public function boot(): void
    {
        if (!config('personal-analytics.enabled')) return;
        $this->loadRoutesFrom(dirname(__DIR__) . '/routes/analytics.php');
        $this->loadViewsFrom(dirname(__DIR__) . '/resources/views', 'personal-analytics');
        $this->loadMigrationsFrom(dirname(__DIR__) . '/database/migrations');
        $this->publishes([
            dirname(__DIR__) . '/config/personal-analytics.php' => config_path('personal-analytics.php'),
        ], 'personal-analytics-config');
        $this->publishes([
            dirname(__DIR__) . '/resources/views' => resource_path('views/vendor/personal-analytics'),
        ], 'personal-analytics-views');

        RateLimiter::for('personal-analytics-ingest', static fn (Request $request): Limit => Limit::perMinute(
            (int) config('personal-analytics.rate_limit_per_minute', 600),
        )->by($request->ip() . '|' . $request->header('Origin', 'unknown')));

        if ($this->app->runningInConsole()) {
            $this->commands([AggregateAnalyticsCommand::class, MaintainAnalyticsCommand::class]);
            $this->callAfterResolving(Schedule::class, static function (Schedule $schedule): void {
                $schedule->command('analytics:aggregate')->hourly()->withoutOverlapping();
                $schedule->command('analytics:maintain')->dailyAt('02:15')->withoutOverlapping();
            });
        }
    }
}
