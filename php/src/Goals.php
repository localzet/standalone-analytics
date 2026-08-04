<?php
/*
 * SPDX-FileCopyrightText: 2025-2026 Zorin Projects S.P. <creator@localzet.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Localzet\PersonalAnalytics;

use DateTimeImmutable;
use InvalidArgumentException;
use PDO;

final readonly class Goals
{
    public function __construct(private PDO $pdo)
    {
    }

    public function create(string $key, string $name, string $type, ?string $path = null): int
    {
        if (!preg_match('/^[a-zA-Z0-9._-]{1,64}$/', $key)) {
            throw new InvalidArgumentException('Goal key is invalid.');
        }
        if (!in_array($type, ['pageview', 'custom'], true)) {
            throw new InvalidArgumentException('Goal type is invalid.');
        }
        if ($type === 'pageview' && ($path === null || !str_starts_with($path, '/'))) {
            throw new InvalidArgumentException('Pageview goals require an absolute path.');
        }

        $statement = $this->pdo->prepare(<<<'SQL'
            INSERT INTO pa_goals (goal_key, name, type, path, created_at)
            VALUES (:key, :name, :type, :path, :created_at)
            SQL);
        $statement->execute([
            'key' => $key,
            'name' => mb_substr($name, 0, 128),
            'type' => $type,
            'path' => $path,
            'created_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s.v'),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        return $this->pdo->query('SELECT id, goal_key, name, type, path, enabled, created_at FROM pa_goals ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    }
}
