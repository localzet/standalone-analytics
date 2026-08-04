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
use RuntimeException;

final readonly class Heatmaps
{
    public function __construct(private PDO $pdo)
    {
    }

    public function create(string $key, string $name, string $path): int
    {
        if (!preg_match('/^[a-zA-Z0-9._-]{1,64}$/', $key) || !str_starts_with($path, '/')) {
            throw new InvalidArgumentException('Heatmap key or path is invalid.');
        }
        $statement = $this->pdo->prepare(<<<'SQL'
            INSERT INTO pa_heatmaps (heatmap_key, name, path, created_at)
            VALUES (:key, :name, :path, :created_at)
            SQL);
        $statement->execute([
            'key' => $key,
            'name' => mb_substr($name, 0, 128),
            'path' => mb_substr($path, 0, 2048),
            'created_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s.v'),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        return $this->pdo->query('SELECT id, heatmap_key, name, path, enabled, created_at FROM pa_heatmaps ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array{snapshot: list<mixed>|null, clicks: list<array<string, mixed>>, scrolls: list<array<string, mixed>>} */
    public function data(int $heatmapId, string $deviceType): array
    {
        if (!in_array($deviceType, ['desktop', 'tablet', 'mobile'], true)) {
            throw new InvalidArgumentException('Device type is invalid.');
        }
        $statement = $this->pdo->prepare('SELECT payload FROM pa_heatmap_snapshots WHERE heatmap_id = :id AND device_type = :device');
        $statement->execute(['id' => $heatmapId, 'device' => $deviceType]);
        $payload = $statement->fetchColumn();
        $snapshot = null;
        if (is_string($payload)) {
            $decoded = gzdecode($payload);
            if ($decoded === false) {
                throw new RuntimeException('Heatmap snapshot is corrupted.');
            }
            $snapshot = json_decode($decoded, true, 512, JSON_THROW_ON_ERROR);
        }

        $statement = $this->pdo->prepare(<<<'SQL'
            SELECT type, data, SUM(occurrence_count) AS total
            FROM pa_action_events
            WHERE heatmap_id = :id AND type IN ('click', 'scroll')
            GROUP BY type, data
            SQL);
        $statement->execute(['id' => $heatmapId]);
        $result = ['snapshot' => $snapshot, 'clicks' => [], 'scrolls' => []];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $item = json_decode($row['data'], true, 32, JSON_THROW_ON_ERROR);
            $item['count'] = (int) $row['total'];
            $result[$row['type'] === 'click' ? 'clicks' : 'scrolls'][] = $item;
        }

        return $result;
    }
}
