<?php

/*
 * SPDX-FileCopyrightText: 2025-2026 Zorin Projects S.P. <creator@localzet.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Localzet\LaravelAnalytics\Support;

use Localzet\LaravelAnalytics\Contracts\GeoResolver;

final class NullGeoResolver implements GeoResolver
{
    public function resolve(?string $ip): array
    {
        return [];
    }
}
