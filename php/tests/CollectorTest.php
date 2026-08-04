<?php
/*
 * SPDX-FileCopyrightText: 2025-2026 Zorin Projects S.P. <creator@localzet.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Localzet\PersonalAnalytics\Tests;

use Localzet\PersonalAnalytics\Collector;
use Localzet\PersonalAnalytics\Config;
use Localzet\PersonalAnalytics\Exception\InvalidEvent;
use Localzet\PersonalAnalytics\Exception\UnauthorizedCollection;
use Localzet\PersonalAnalytics\Repository;
use Localzet\PersonalAnalytics\RequestContext;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Collector::class)]
final class CollectorTest extends TestCase
{
    public function testRejectsOversizedPayloadBeforePersistence(): void
    {
        $collector = $this->collector(maxPayloadBytes: 10);
        $this->expectException(InvalidEvent::class);

        $collector->collectJson(str_repeat('x', 11), new RequestContext());
    }

    public function testRejectsWrongProjectToken(): void
    {
        $collector = $this->collector();
        $this->expectException(UnauthorizedCollection::class);

        $collector->collect([
            'schemaVersion' => 1,
            'projectToken' => 'wrong',
            'url' => 'https://example.test/',
            'type' => 'visitor',
        ], new RequestContext(origin: 'https://example.test'));
    }

    private function collector(int $maxPayloadBytes = 1_048_576): Collector
    {
        $config = new Config(
            'expected',
            'example.test',
            ['https://example.test'],
            maxPayloadBytes: $maxPayloadBytes,
        );
        $repository = new Repository(new PDO('sqlite::memory:'), $config);

        return new Collector($repository, $config);
    }
}
