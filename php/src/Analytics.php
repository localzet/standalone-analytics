<?php
/*
 * SPDX-FileCopyrightText: 2025-2026 Zorin Projects S.P. <creator@localzet.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Localzet\PersonalAnalytics;

use PDO;

final readonly class Analytics
{
    public Collector $collector;
    public Reports $reports;
    public Goals $goals;
    public Heatmaps $heatmaps;
    public Replays $replays;
    public Retention $retention;

    public function __construct(PDO $pdo, Config $config)
    {
        $repository = new Repository($pdo, $config);
        $this->collector = new Collector($repository, $config);
        $this->reports = new Reports($pdo);
        $this->goals = new Goals($pdo);
        $this->heatmaps = new Heatmaps($pdo);
        $this->replays = new Replays($pdo);
        $this->retention = new Retention($pdo);
    }
}
