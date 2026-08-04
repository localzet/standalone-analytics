<?php
/*
 * SPDX-FileCopyrightText: 2025-2026 Zorin Projects S.P. <creator@localzet.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Localzet\PersonalAnalytics;

use InvalidArgumentException;

final readonly class Config
{
    /**
     * @param list<string> $allowedOrigins
     * @param list<string> $excludedIps
     */
    public function __construct(
        public string $projectToken,
        public string $allowedHost,
        public array $allowedOrigins = [],
        public array $excludedIps = [],
        public bool $storeIp = false,
        public bool $trackQueryParameters = false,
        public int $eventRetentionDays = 365,
        public int $actionRetentionDays = 90,
        public int $replayRetentionDays = 30,
        public int $maxPayloadBytes = 1_048_576,
        public int $maxReplayChunkEvents = 100,
    ) {
        if ($projectToken === '' || strlen($projectToken) > 128) {
            throw new InvalidArgumentException('Project token must contain 1-128 characters.');
        }

        if (
            !filter_var('https://' . $allowedHost, FILTER_VALIDATE_URL)
            || parse_url('https://' . $allowedHost, PHP_URL_HOST) !== $allowedHost
        ) {
            throw new InvalidArgumentException('Allowed host is invalid.');
        }

        foreach ([$eventRetentionDays, $actionRetentionDays, $replayRetentionDays] as $days) {
            if ($days < 1) {
                throw new InvalidArgumentException('Retention must be at least one day.');
            }
        }
    }
}
