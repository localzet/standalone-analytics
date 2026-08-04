<?php
/*
 * SPDX-FileCopyrightText: 2025-2026 Zorin Projects S.P. <creator@localzet.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Localzet\PersonalAnalytics\Tests;

use InvalidArgumentException;
use Localzet\PersonalAnalytics\Config;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Config::class)]
final class ConfigTest extends TestCase
{
    public function testAcceptsSingleProjectConfiguration(): void
    {
        $config = new Config('token', 'example.test', ['https://example.test']);

        self::assertSame('example.test', $config->allowedHost);
        self::assertFalse($config->storeIp);
    }

    public function testRejectsInvalidRetention(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Config('token', 'example.test', eventRetentionDays: 0);
    }
}
