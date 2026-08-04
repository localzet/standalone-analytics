<?php
/*
 * SPDX-FileCopyrightText: 2025-2026 Zorin Projects S.P. <creator@localzet.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Localzet\PersonalAnalytics;

use PDO;
use RuntimeException;

final readonly class Replays
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return list<array<string, int|string|null>> */
    public function latest(int $limit = 50, int $offset = 0): array
    {
        if ($limit < 1 || $limit > 200 || $offset < 0) {
            throw new RuntimeException('Replay pagination is invalid.');
        }
        $statement = $this->pdo->query(<<<SQL
            SELECT r.id, r.session_id, r.event_count, r.byte_size, r.started_at, r.last_seen_at,
                   r.expires_at, HEX(v.uuid) AS visitor_uuid
            FROM pa_replay_sessions r
            INNER JOIN pa_visitors v ON v.id = r.visitor_id
            ORDER BY r.started_at DESC
            LIMIT {$limit} OFFSET {$offset}
            SQL);

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'sessionId' => (int) $row['session_id'],
            'visitorUuid' => strtolower($row['visitor_uuid']),
            'events' => (int) $row['event_count'],
            'bytes' => (int) $row['byte_size'],
            'startedAt' => $row['started_at'],
            'lastSeenAt' => $row['last_seen_at'],
            'expiresAt' => $row['expires_at'],
        ], $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return list<array<string, mixed>> */
    public function events(int $replayId): array
    {
        $statement = $this->pdo->prepare('SELECT payload FROM pa_replay_chunks WHERE replay_id = :id ORDER BY created_at, sequence_no, id');
        $statement->execute(['id' => $replayId]);
        $events = [];
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $payload) {
            $json = gzdecode($payload);
            if ($json === false) {
                throw new RuntimeException('Replay chunk is corrupted.');
            }
            $chunk = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($chunk)) {
                throw new RuntimeException('Replay chunk is invalid.');
            }
            array_push($events, ...$chunk);
        }

        return $events;
    }
}
