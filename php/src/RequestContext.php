<?php
/*
 * SPDX-FileCopyrightText: 2025-2026 Zorin Projects S.P. <creator@localzet.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Localzet\PersonalAnalytics;

final readonly class RequestContext
{
    public function __construct(
        public ?string $origin = null,
        public ?string $ip = null,
        public ?string $userAgent = null,
        public ?string $acceptLanguage = null,
        public ?string $countryCode = null,
        public ?string $cityName = null,
        public ?string $osName = null,
        public ?string $browserName = null,
    ) {
    }
}
