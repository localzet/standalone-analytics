<?php

/*
 * SPDX-FileCopyrightText: 2025-2026 Zorin Projects S.P. <creator@localzet.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Localzet\LaravelAnalytics\Services;

use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;

final readonly class IngestionService
{
    public function __construct(
        private DatabaseManager $database,
        private ReplayStorage $replays,
        private HeatmapStorage $heatmaps,
        private PayloadSanitizer $sanitizer,
    ) {}

    /** @param list<array<string, mixed>> $events @param array<string, mixed> $requestContext */
    public function ingest(array $events, array $requestContext): void
    {
        $connection = $this->database->connection(config('personal-analytics.connection'));
        $connection->transaction(function () use ($connection, $events, $requestContext): void {
            foreach ($events as $event) {
                $this->projectIdentities($connection, $event, $requestContext);
            }
            $rows = [];
            foreach ($events as $event) {
                if (in_array(($event['type'] ?? null), ['replay', 'heatmap_snapshot'], true)) {
                    continue;
                }
                $row = $this->eventRow($event, $requestContext);
                if ($row !== null) {
                    $rows[] = $row;
                }
            }
            foreach (array_chunk($rows, 250) as $chunk) {
                $connection->table('analytics_events')->insertOrIgnore($chunk);
            }
            $this->updateSessionCounters($connection, $rows);
        }, 3);
        foreach ($events as $event) {
            if (($event['type'] ?? null) === 'replay') {
                $this->storeReplay($event);
            }
            if (($event['type'] ?? null) === 'heatmap_snapshot') {
                $this->storeHeatmapSnapshot($event);
            }
        }
    }

    /** @param array<string, mixed> $event @param array<string, mixed> $context */
    private function projectIdentities(ConnectionInterface $connection, array $event, array $context): void
    {
        $visitorId = $this->uuid($event['visitorId'] ?? null);
        if ($visitorId === null) {
            return;
        }
        $data = is_array($event['data'] ?? null) ? $event['data'] : [];
        $occurredAt = $this->occurredAt($data['occurredAt'] ?? null);
        $connection->table('analytics_visitors')->insertOrIgnore([[
            'id' => $visitorId, 'identified_user_id' => null, 'traits' => '{}', 'acquisition' => '{}',
            'technology' => '{}', 'geography' => '{}', 'first_seen_at' => $occurredAt, 'last_seen_at' => $occurredAt,
        ]]);
        if (($event['type'] ?? null) === 'visitor') {
            $connection->table('analytics_visitors')->where('id', $visitorId)->update([
                'traits' => $this->json($data['customParameters'] ?? []),
                'acquisition' => $this->json($data['attribution'] ?? []),
                'technology' => $this->json(array_merge((array) ($context['technology'] ?? []), [
                    'screen' => $data['screen'] ?? null, 'deviceType' => $data['deviceType'] ?? null,
                    'theme' => $data['theme'] ?? null, 'language' => $context['language'] ?? null,
                ])),
                'geography' => $this->json($context['geo'] ?? []), 'last_seen_at' => $occurredAt,
            ]);
        } else {
            $connection->table('analytics_visitors')->where('id', $visitorId)->update(['last_seen_at' => $occurredAt]);
        }
        if (($event['type'] ?? null) === 'event' && ($data['name'] ?? null) === 'identify') {
            $properties = is_array($data['properties'] ?? null) ? $data['properties'] : [];
            $userId = substr((string) ($properties['userId'] ?? ''), 0, 256);
            if ($userId !== '') {
                $connection->table('analytics_visitors')->where('id', $visitorId)->update([
                    'identified_user_id' => $userId,
                    'traits' => $this->json($properties['traits'] ?? []),
                ]);
            }
        }

        $sessionId = $this->uuid($event['sessionId'] ?? null);
        if ($sessionId === null) {
            return;
        }
        $path = substr((string) ($data['path'] ?? parse_url((string) ($event['url'] ?? ''), PHP_URL_PATH) ?: '/'), 0, 2048);
        $inserted = $connection->table('analytics_sessions')->insertOrIgnore([[
            'id' => $sessionId, 'visitor_id' => $visitorId, 'started_at' => $occurredAt, 'last_seen_at' => $occurredAt,
            'entry_path' => $path, 'exit_path' => $path, 'attribution' => $this->json($data['attribution'] ?? []),
        ]]);
        $connection->table('analytics_sessions')->where('id', $sessionId)->update([
            'last_seen_at' => $occurredAt, 'exit_path' => $path,
        ]);
        if ($inserted > 0) {
            $connection->table('analytics_visitors')->where('id', $visitorId)->increment('sessions_count');
        }
    }

    /** @param array<string, mixed> $event @param array<string, mixed> $context @return array<string, mixed>|null */
    private function eventRow(array $event, array $context): ?array
    {
        $visitorId = $this->uuid($event['visitorId'] ?? null);
        if ($visitorId === null) {
            return null;
        }
        $data = is_array($event['data'] ?? null) ? $event['data'] : [];
        $type = (string) ($event['type'] ?? 'unknown');
        $isGeneric = $type === 'event';
        $name = $isGeneric ? (string) ($data['name'] ?? 'unknown') : match ($type) {
            'landing_page', 'pageview' => 'page_view',
            'goal_conversion' => 'goal_conversion',
            'heatmap_snapshot' => 'heatmap_snapshot',
            default => $type,
        };
        $category = $isGeneric ? (string) ($data['category'] ?? 'custom') : match ($type) {
            'landing_page', 'pageview' => 'navigation',
            'click', 'scroll', 'form', 'resize' => 'interaction',
            'goal_conversion' => 'custom',
            default => 'custom',
        };
        $id = $this->uuid($event['telemetryId'] ?? $event['eventId'] ?? $event['chunkId'] ?? null) ?? (string) Str::uuid();
        $properties = $isGeneric && is_array($data['properties'] ?? null) ? $data['properties'] : $data;
        if ($isGeneric) {
            $properties['attribution'] = $data['attribution'] ?? [];
            $properties['viewport'] = $data['viewport'] ?? [];
            $properties['deviceType'] = $data['deviceType'] ?? null;
        }
        $metrics = $isGeneric && is_array($data['metrics'] ?? null) ? array_filter(
            $data['metrics'],
            static fn (mixed $value): bool => (is_int($value) || is_float($value)) && is_finite((float) $value),
        ) : [];

        return [
            'id' => $id, 'visitor_id' => $visitorId, 'session_id' => $this->uuid($event['sessionId'] ?? null),
            'page_event_id' => $this->uuid($event['eventId'] ?? null), 'name' => substr($name, 0, 128),
            'category' => substr($category, 0, 32),
            'path' => substr((string) ($data['path'] ?? parse_url((string) ($event['url'] ?? ''), PHP_URL_PATH) ?: '/'), 0, 2048),
            'title' => substr((string) ($data['title'] ?? ''), 0, 512) ?: null,
            'properties' => $this->json($properties),
            'metrics' => $this->json($metrics),
            'context' => $this->json(['request' => $context, 'url' => $event['url'] ?? null]),
            'occurred_at' => $this->occurredAt($data['occurredAt'] ?? null),
            'received_at' => $context['receivedAt'] ?? now(),
        ];
    }

    /** @param array<string, mixed> $event */
    private function storeReplay(array $event): void
    {
        $visitorId = $this->uuid($event['visitorId'] ?? null);
        $sessionId = $this->uuid($event['sessionId'] ?? null);
        $chunkId = $this->uuid($event['chunkId'] ?? null);
        $data = $event['data'] ?? null;
        if ($visitorId === null || $sessionId === null || $chunkId === null || ! is_array($data) || ! array_is_list($data)) {
            return;
        }
        $this->replays->putChunk($visitorId, $sessionId, $chunkId, max(0, (int) ($event['sequence'] ?? 0)), $data, now()->toISOString());
    }

    /** @param array<string, mixed> $event */
    private function storeHeatmapSnapshot(array $event): void
    {
        $key = (string) ($event['heatmapKey'] ?? '');
        $data = $event['data'] ?? null;
        if ($key === '' || ! is_array($data) || ! array_is_list($data)) {
            return;
        }
        $path = substr((string) (parse_url((string) ($event['url'] ?? ''), PHP_URL_PATH) ?: '/'), 0, 2048);
        $this->heatmaps->putSnapshot(
            $key,
            $path,
            (string) ($event['deviceType'] ?? 'desktop'),
            (int) ($event['viewportWidth'] ?? 1),
            $data,
            now()->toISOString(),
        );
    }

    /** @param list<array<string, mixed>> $rows */
    private function updateSessionCounters(ConnectionInterface $connection, array $rows): void
    {
        $sessionIds = array_values(array_unique(array_filter(array_column($rows, 'session_id'))));
        if ($sessionIds !== []) {
            $placeholders = implode(',', array_fill(0, count($sessionIds), '?::uuid'));
            $connection->update(<<<SQL
                UPDATE analytics_sessions AS session
                SET events_count = facts.events,
                    pageviews = facts.pageviews,
                    engagement_ms = facts.engagement_ms,
                    is_bounced = facts.pageviews <= 1
                FROM (
                    SELECT session_id, COUNT(*) AS events,
                           COUNT(*) FILTER (WHERE name = 'page_view') AS pageviews,
                           COALESCE(MAX((metrics->>'visible')::numeric), 0)::bigint AS engagement_ms
                    FROM analytics_events WHERE session_id IN ({$placeholders}) GROUP BY session_id
                ) AS facts
                WHERE session.id = facts.session_id
                SQL, $sessionIds);
        }

        $visitorIds = array_values(array_unique(array_column($rows, 'visitor_id')));
        if ($visitorIds === []) {
            return;
        }
        $placeholders = implode(',', array_fill(0, count($visitorIds), '?::uuid'));
        $connection->update(<<<SQL
            UPDATE analytics_visitors AS visitor
            SET events_count = facts.events,
                sessions_count = facts.sessions
            FROM (
                SELECT candidate.id,
                       (SELECT COUNT(*) FROM analytics_events event WHERE event.visitor_id = candidate.id) AS events,
                       (SELECT COUNT(*) FROM analytics_sessions session WHERE session.visitor_id = candidate.id) AS sessions
                FROM analytics_visitors candidate WHERE candidate.id IN ({$placeholders})
            ) AS facts
            WHERE visitor.id = facts.id
            SQL, $visitorIds);
    }

    private function uuid(mixed $value): ?string
    {
        return is_string($value) && Str::isUuid($value) ? $value : null;
    }

    private function occurredAt(mixed $value): string
    {
        try {
            return (new DateTimeImmutable(is_string($value) ? $value : 'now'))->format(DATE_ATOM);
        } catch (\Throwable) {
            return now()->toISOString();
        }
    }

    private function json(mixed $value): string
    {
        return json_encode($this->sanitizer->sanitize($value), JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
