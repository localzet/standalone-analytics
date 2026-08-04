<?php
/*
 * SPDX-FileCopyrightText: 2025-2026 Zorin Projects S.P. <creator@localzet.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Localzet\PersonalAnalytics;

use DateTimeImmutable;
use PDO;

final readonly class Retention
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return array{actions: int, pageEvents: int, replays: int} */
    public function purge(?DateTimeImmutable $today = null): array
    {
        $today ??= new DateTimeImmutable('today');
        $date = $today->format('Y-m-d');

        $actions = $this->pdo->prepare('DELETE FROM pa_action_events WHERE expires_at IS NOT NULL AND expires_at < :today');
        $actions->execute(['today' => $date]);
        $pageEvents = $this->pdo->prepare('DELETE FROM pa_page_events WHERE expires_at < :today');
        $pageEvents->execute(['today' => $date]);
        $replays = $this->pdo->prepare('DELETE FROM pa_replay_sessions WHERE expires_at < :today');
        $replays->execute(['today' => $date]);

        return [
            'actions' => $actions->rowCount(),
            'pageEvents' => $pageEvents->rowCount(),
            'replays' => $replays->rowCount(),
        ];
    }
}
