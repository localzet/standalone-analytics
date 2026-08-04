<?php
/*
 * SPDX-FileCopyrightText: 2025-2026 Zorin Projects S.P. <creator@localzet.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Localzet\LaravelAnalytics\Contracts;

interface GeoResolver
{
    /** @return array{country?: string, region?: string, city?: string, latitude?: float, longitude?: float, timezone?: string} */
    public function resolve(?string $ip): array;
}
