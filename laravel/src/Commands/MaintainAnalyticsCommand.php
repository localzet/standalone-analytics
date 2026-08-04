<?php

/*
 * SPDX-FileCopyrightText: 2025-2026 Zorin Projects S.P. <creator@localzet.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Localzet\LaravelAnalytics\Commands;

use Illuminate\Console\Command;
use Localzet\LaravelAnalytics\Services\MaintenanceService;

final class MaintainAnalyticsCommand extends Command
{
    protected $signature = 'analytics:maintain';

    protected $description = 'Create PostgreSQL partitions and enforce analytics retention';

    public function handle(MaintenanceService $maintenance): int
    {
        $result = $maintenance->maintain();
        $this->info('Analytics maintenance completed: '.json_encode($result, JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
