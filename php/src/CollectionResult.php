<?php
/*
 * SPDX-FileCopyrightText: 2025-2026 Zorin Projects S.P. <creator@localzet.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Localzet\PersonalAnalytics;

final readonly class CollectionResult
{
    public function __construct(
        public bool $accepted,
        public ?string $refresh = null,
    ) {
    }

    /** @return array{accepted: bool, refresh?: string} */
    public function toArray(): array
    {
        $result = ['accepted' => $this->accepted];
        if ($this->refresh !== null) {
            $result['refresh'] = $this->refresh;
        }

        return $result;
    }
}
