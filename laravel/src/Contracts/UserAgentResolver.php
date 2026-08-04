<?php
/*
 * SPDX-FileCopyrightText: 2025-2026 Zorin Projects S.P. <creator@localzet.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Localzet\LaravelAnalytics\Contracts;

interface UserAgentResolver
{
    /** @return array{browser?: string, browserVersion?: string, os?: string, osVersion?: string, device?: string, bot?: bool} */
    public function resolve(?string $userAgent): array;
}
