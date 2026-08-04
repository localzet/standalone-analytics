<?php
/*
 * SPDX-FileCopyrightText: 2025-2026 Zorin Projects S.P. <creator@localzet.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Localzet\LaravelAnalytics\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Localzet\LaravelAnalytics\Services\AggregationService;

final class AggregateAnalyticsCommand extends Command
{
    protected $signature = 'analytics:aggregate {--date= : Date to rebuild, defaults to today and yesterday}';
    protected $description = 'Rebuild analytics rollups, heatmaps and goal conversions';

    public function handle(AggregationService $aggregation): int
    {
        $date = $this->option('date');
        $days = $date ? [CarbonImmutable::parse((string) $date)] : [CarbonImmutable::yesterday(), CarbonImmutable::today()];
        foreach ($days as $day) $aggregation->aggregate($day);
        $this->info('Analytics aggregates rebuilt.');
        return self::SUCCESS;
    }
}
