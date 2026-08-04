<?php
/*
 * SPDX-FileCopyrightText: 2025-2026 Zorin Projects S.P. <creator@localzet.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Localzet\PersonalAnalytics;

use DateTimeImmutable;
use JsonException;
use PDO;
use RuntimeException;
use Throwable;

final class Repository
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly Config $config,
    ) {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    /** @param array<string, mixed> $data */
    public function upsertVisitor(string $uuid, array $data, RequestContext $context, DateTimeImmutable $now): void
    {
        $sql = <<<'SQL'
            INSERT INTO pa_visitors (
                uuid, ip, custom_parameters, screen_width, screen_height, timezone,
                language, country_code, city_name, os_name, browser_name, device_type,
                theme, user_agent, first_seen_at, last_seen_at
            ) VALUES (
                :uuid, :ip, :custom_parameters, :screen_width, :screen_height, :timezone,
                :language, :country_code, :city_name, :os_name, :browser_name, :device_type,
                :theme, :user_agent, :now, :now
            )
            ON DUPLICATE KEY UPDATE
                ip = VALUES(ip), custom_parameters = VALUES(custom_parameters),
                screen_width = VALUES(screen_width), screen_height = VALUES(screen_height),
                timezone = VALUES(timezone), language = VALUES(language),
                country_code = VALUES(country_code), city_name = VALUES(city_name),
                os_name = VALUES(os_name), browser_name = VALUES(browser_name),
                device_type = VALUES(device_type), theme = VALUES(theme),
                user_agent = VALUES(user_agent), last_seen_at = VALUES(last_seen_at)
            SQL;

        $screen = is_array($data['screen'] ?? null) ? $data['screen'] : [];
        $statement = $this->pdo->prepare($sql);
        $statement->execute([
            'uuid' => self::uuidToBinary($uuid),
            'ip' => $this->config->storeIp ? $context->ip : null,
            'custom_parameters' => $this->encode($data['customParameters'] ?? []),
            'screen_width' => $screen['width'] ?? null,
            'screen_height' => $screen['height'] ?? null,
            'timezone' => $data['timezone'] ?? null,
            'language' => $context->acceptLanguage !== null ? substr($context->acceptLanguage, 0, 16) : null,
            'country_code' => $context->countryCode !== null ? strtoupper(substr($context->countryCode, 0, 2)) : null,
            'city_name' => self::bounded($context->cityName, 128),
            'os_name' => self::bounded($context->osName, 64),
            'browser_name' => self::bounded($context->browserName, 64),
            'device_type' => $data['deviceType'] ?? null,
            'theme' => $data['theme'] ?? null,
            'user_agent' => $context->userAgent !== null ? substr($context->userAgent, 0, 512) : null,
            'now' => self::date($now),
        ]);
    }

    /** @param array<string, mixed> $data */
    public function storePageEvent(
        string $visitorUuid,
        string $sessionUuid,
        string $eventUuid,
        string $type,
        array $data,
        DateTimeImmutable $now,
    ): bool {
        return $this->transaction(function () use ($visitorUuid, $sessionUuid, $eventUuid, $type, $data, $now): bool {
            $visitorId = $this->idByUuid('pa_visitors', $visitorUuid);
            if ($visitorId === null) {
                return false;
            }

            $sessionId = $this->upsertSession($sessionUuid, $visitorId, $now);
            if ($sessionId === null) {
                return false;
            }
            $url = parse_url((string) ($data['referrer'] ?? '')) ?: [];
            if (isset($url['host']) && strcasecmp($url['host'], $this->config->allowedHost) === 0) {
                $url = [];
            }
            $utm = is_array($data['utm'] ?? null) ? $data['utm'] : [];
            $viewport = is_array($data['viewport'] ?? null) ? $data['viewport'] : [];
            $path = $this->normalizePath((string) ($data['path'] ?? '/'));

            $statement = $this->pdo->prepare(<<<'SQL'
                INSERT INTO pa_page_events (
                    uuid, session_id, visitor_id, type, path, title, referrer_host, referrer_path,
                    utm_source, utm_medium, utm_campaign, utm_term, utm_content,
                    viewport_width, viewport_height, bounced, occurred_at, expires_at
                ) VALUES (
                    :uuid, :session_id, :visitor_id, :type, :path, :title, :referrer_host, :referrer_path,
                    :utm_source, :utm_medium, :utm_campaign, :utm_term, :utm_content,
                    :viewport_width, :viewport_height, :bounced, :occurred_at, :expires_at
                ) ON DUPLICATE KEY UPDATE uuid = VALUES(uuid)
                SQL);
            $statement->execute([
                'uuid' => self::uuidToBinary($eventUuid),
                'session_id' => $sessionId,
                'visitor_id' => $visitorId,
                'type' => $type,
                'path' => $path,
                'title' => self::bounded($data['title'] ?? null, 512),
                'referrer_host' => self::bounded($url['host'] ?? null, 255),
                'referrer_path' => self::bounded($url['path'] ?? null, 2048),
                'utm_source' => self::bounded($utm['source'] ?? null, 255),
                'utm_medium' => self::bounded($utm['medium'] ?? null, 255),
                'utm_campaign' => self::bounded($utm['campaign'] ?? null, 255),
                'utm_term' => self::bounded($utm['term'] ?? null, 255),
                'utm_content' => self::bounded($utm['content'] ?? null, 255),
                'viewport_width' => $viewport['width'] ?? null,
                'viewport_height' => $viewport['height'] ?? null,
                'bounced' => $type === 'landing_page' ? 1 : null,
                'occurred_at' => self::date($now),
                'expires_at' => $now->modify('+' . $this->config->eventRetentionDays . ' days')->format('Y-m-d'),
            ]);

            if ($statement->rowCount() === 0) {
                return true;
            }

            if ($type === 'pageview') {
                $update = $this->pdo->prepare("UPDATE pa_page_events SET bounced = 0 WHERE session_id = :session_id AND type = 'landing_page'");
                $update->execute(['session_id' => $sessionId]);
            }

            $update = $this->pdo->prepare('UPDATE pa_sessions SET event_count = event_count + 1, last_seen_at = :now WHERE id = :id');
            $update->execute(['now' => self::date($now), 'id' => $sessionId]);
            $update = $this->pdo->prepare('UPDATE pa_visitors SET last_seen_at = :now WHERE id = :id');
            $update->execute(['now' => self::date($now), 'id' => $visitorId]);

            return true;
        });
    }

    /** @param array<string, mixed> $data */
    public function storeAction(
        string $visitorUuid,
        string $sessionUuid,
        string $eventUuid,
        string $type,
        array $data,
        int $count,
        ?string $heatmapKey,
        DateTimeImmutable $now,
    ): bool {
        $identity = $this->eventIdentity($visitorUuid, $sessionUuid, $eventUuid);
        if ($identity === null) {
            return false;
        }

        $heatmapId = null;
        if ($heatmapKey !== null) {
            $statement = $this->pdo->prepare('SELECT id FROM pa_heatmaps WHERE heatmap_key = :key AND enabled = 1');
            $statement->execute(['key' => $heatmapKey]);
            $heatmapId = $statement->fetchColumn() ?: null;
        }

        $statement = $this->pdo->prepare(<<<'SQL'
            INSERT INTO pa_action_events (
                event_id, session_id, visitor_id, heatmap_id, type, data,
                occurrence_count, occurred_at, expires_at
            ) VALUES (
                :event_id, :session_id, :visitor_id, :heatmap_id, :type, :data,
                :occurrence_count, :occurred_at, :expires_at
            )
            SQL);
        $statement->execute([
            ...$identity,
            'heatmap_id' => $heatmapId,
            'type' => $type,
            'data' => $this->encode($data),
            'occurrence_count' => $count,
            'occurred_at' => self::date($now),
            'expires_at' => $heatmapId === null
                ? $now->modify('+' . $this->config->actionRetentionDays . ' days')->format('Y-m-d')
                : null,
        ]);

        return true;
    }

    /** @param array<string, mixed> $data */
    public function storeGenericEvent(
        string $telemetryUuid,
        string $visitorUuid,
        string $sessionUuid,
        string $eventUuid,
        string $category,
        string $name,
        array $data,
        DateTimeImmutable $now,
    ): bool {
        $identity = $this->eventIdentity($visitorUuid, $sessionUuid, $eventUuid);
        if ($identity === null) return false;
        try {
            $occurredAt = new DateTimeImmutable((string) ($data['occurredAt'] ?? 'now'));
        } catch (\Throwable) {
            $occurredAt = $now;
        }
        $properties = is_array($data['properties'] ?? null) ? $data['properties'] : [];
        $properties['attribution'] = $data['attribution'] ?? [];
        $properties['viewport'] = $data['viewport'] ?? [];
        $properties['deviceType'] = $data['deviceType'] ?? null;
        $metrics = is_array($data['metrics'] ?? null) ? array_filter(
            $data['metrics'],
            static fn (mixed $value): bool => (is_int($value) || is_float($value)) && is_finite((float) $value),
        ) : [];
        $statement = $this->pdo->prepare(<<<'SQL'
            INSERT INTO pa_events (
                uuid, event_id, session_id, visitor_id, category, name, path,
                properties, metrics, occurred_at, expires_at
            ) VALUES (
                :uuid, :event_id, :session_id, :visitor_id, :category, :name, :path,
                :properties, :metrics, :occurred_at, :expires_at
            ) ON DUPLICATE KEY UPDATE uuid = VALUES(uuid)
            SQL);
        $statement->execute([
            'uuid' => self::uuidToBinary($telemetryUuid),
            ...$identity,
            'category' => $category,
            'name' => $name,
            'path' => $this->normalizePath((string) ($data['path'] ?? '/')),
            'properties' => $this->encode($this->sanitizeAnalytics($properties)),
            'metrics' => $this->encode($metrics),
            'occurred_at' => self::date($occurredAt),
            'expires_at' => $now->modify('+' . $this->config->eventRetentionDays . ' days')->format('Y-m-d'),
        ]);

        return true;
    }

    private function sanitizeAnalytics(mixed $value, int $depth = 0): mixed
    {
        if ($depth > 4) return '[truncated]';
        if ($value === null || is_bool($value) || is_int($value) || is_float($value)) return $value;
        if (is_string($value)) return mb_substr($value, 0, 512);
        if (!is_array($value) && !is_object($value)) return mb_substr((string) $value, 0, 512);
        $result = [];
        foreach (array_slice((array) $value, 0, 50, true) as $key => $item) {
            $key = mb_substr((string) $key, 0, 64);
            $result[$key] = preg_match('/pass|secret|token|authorization|cookie|credit.?card|card.?number|cvv|cvc|iban|account.?number|email|phone|address/i', $key)
                ? '[redacted]'
                : $this->sanitizeAnalytics($item, $depth + 1);
        }
        return $result;
    }

    /** @param list<array<string, mixed>> $events */
    public function appendReplay(
        string $visitorUuid,
        string $sessionUuid,
        string $chunkUuid,
        int $sequence,
        array $events,
        DateTimeImmutable $now,
    ): bool {
        return $this->transaction(function () use ($visitorUuid, $sessionUuid, $chunkUuid, $sequence, $events, $now): bool {
            $visitorId = $this->idByUuid('pa_visitors', $visitorUuid);
            $sessionId = $this->idByUuid('pa_sessions', $sessionUuid);
            if ($visitorId === null || $sessionId === null) {
                return false;
            }

            $compressed = gzencode($this->encode($events), 4);
            if ($compressed === false) {
                throw new RuntimeException('Unable to compress replay events.');
            }

            $expiresAt = $now->modify('+' . $this->config->replayRetentionDays . ' days')->format('Y-m-d');
            $statement = $this->pdo->prepare(<<<'SQL'
                INSERT INTO pa_replay_sessions (
                    session_id, visitor_id, event_count, byte_size, started_at, last_seen_at, expires_at
                ) VALUES (:session_id, :visitor_id, 0, 0, :now, :now, :expires_at)
                ON DUPLICATE KEY UPDATE last_seen_at = VALUES(last_seen_at), expires_at = VALUES(expires_at)
                SQL);
            $statement->execute([
                'session_id' => $sessionId,
                'visitor_id' => $visitorId,
                'now' => self::date($now),
                'expires_at' => $expiresAt,
            ]);

            $statement = $this->pdo->prepare('SELECT id FROM pa_replay_sessions WHERE session_id = :session_id');
            $statement->execute(['session_id' => $sessionId]);
            $replayId = (int) $statement->fetchColumn();

            $statement = $this->pdo->prepare(<<<'SQL'
                INSERT INTO pa_replay_chunks (
                    replay_id, chunk_uuid, sequence_no, event_count, payload, created_at
                ) VALUES (:replay_id, :chunk_uuid, :sequence_no, :event_count, :payload, :created_at)
                ON DUPLICATE KEY UPDATE chunk_uuid = VALUES(chunk_uuid)
                SQL);
            $statement->bindValue(':replay_id', $replayId, PDO::PARAM_INT);
            $statement->bindValue(':chunk_uuid', self::uuidToBinary($chunkUuid), PDO::PARAM_LOB);
            $statement->bindValue(':sequence_no', $sequence, PDO::PARAM_INT);
            $statement->bindValue(':event_count', count($events), PDO::PARAM_INT);
            $statement->bindValue(':payload', $compressed, PDO::PARAM_LOB);
            $statement->bindValue(':created_at', self::date($now));
            $statement->execute();

            if ($statement->rowCount() > 0) {
                $update = $this->pdo->prepare(<<<'SQL'
                    UPDATE pa_replay_sessions
                    SET event_count = event_count + :events,
                        byte_size = byte_size + :bytes,
                        last_seen_at = :now,
                        expires_at = :expires_at
                    WHERE id = :id
                    SQL);
                $update->execute([
                    'events' => count($events),
                    'bytes' => strlen($compressed),
                    'now' => self::date($now),
                    'expires_at' => $expiresAt,
                    'id' => $replayId,
                ]);
            }

            return true;
        });
    }

    /** @param list<array<string, mixed>> $events */
    public function storeHeatmapSnapshot(
        string $key,
        string $deviceType,
        int $viewportWidth,
        array $events,
        DateTimeImmutable $now,
    ): void {
        $statement = $this->pdo->prepare('SELECT id FROM pa_heatmaps WHERE heatmap_key = :key AND enabled = 1');
        $statement->execute(['key' => $key]);
        $heatmapId = $statement->fetchColumn();
        if ($heatmapId === false) {
            return;
        }

        $compressed = gzencode($this->encode($events), 4);
        if ($compressed === false) {
            throw new RuntimeException('Unable to compress heatmap snapshot.');
        }

        $statement = $this->pdo->prepare(<<<'SQL'
            INSERT INTO pa_heatmap_snapshots (
                heatmap_id, device_type, viewport_width, payload, created_at
            ) VALUES (:heatmap_id, :device_type, :viewport_width, :payload, :created_at)
            ON DUPLICATE KEY UPDATE id = id
            SQL);
        $statement->bindValue(':heatmap_id', (int) $heatmapId, PDO::PARAM_INT);
        $statement->bindValue(':device_type', $deviceType);
        $statement->bindValue(':viewport_width', $viewportWidth, PDO::PARAM_INT);
        $statement->bindValue(':payload', $compressed, PDO::PARAM_LOB);
        $statement->bindValue(':created_at', self::date($now));
        $statement->execute();
    }

    public function convertGoal(
        string $visitorUuid,
        string $sessionUuid,
        string $eventUuid,
        string $goalKey,
        string $currentPath,
        DateTimeImmutable $now,
    ): bool {
        $identity = $this->eventIdentity($visitorUuid, $sessionUuid, $eventUuid);
        if ($identity === null) {
            return false;
        }

        $statement = $this->pdo->prepare('SELECT id, type, path FROM pa_goals WHERE goal_key = :key AND enabled = 1');
        $statement->execute(['key' => $goalKey]);
        $goal = $statement->fetch(PDO::FETCH_ASSOC);
        if ($goal === false || ($goal['type'] === 'pageview' && $this->normalizePath($currentPath) !== $goal['path'])) {
            return true;
        }

        $statement = $this->pdo->prepare(<<<'SQL'
            INSERT INTO pa_goal_conversions (
                goal_id, event_id, session_id, visitor_id, occurred_at
            ) VALUES (:goal_id, :event_id, :session_id, :visitor_id, :occurred_at)
            ON DUPLICATE KEY UPDATE id = id
            SQL);
        $statement->execute([
            'goal_id' => $goal['id'],
            ...$identity,
            'occurred_at' => self::date($now),
        ]);

        return true;
    }

    /** @return array{event_id: int, session_id: int, visitor_id: int}|null */
    private function eventIdentity(string $visitorUuid, string $sessionUuid, string $eventUuid): ?array
    {
        $statement = $this->pdo->prepare(<<<'SQL'
            SELECT e.id AS event_id, e.session_id, e.visitor_id
            FROM pa_page_events e
            INNER JOIN pa_sessions s ON s.id = e.session_id
            INNER JOIN pa_visitors v ON v.id = e.visitor_id
            WHERE e.uuid = :event_uuid AND s.uuid = :session_uuid AND v.uuid = :visitor_uuid
            SQL);
        $statement->execute([
            'event_uuid' => self::uuidToBinary($eventUuid),
            'session_uuid' => self::uuidToBinary($sessionUuid),
            'visitor_uuid' => self::uuidToBinary($visitorUuid),
        ]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : array_map('intval', $row);
    }

    private function upsertSession(string $uuid, int $visitorId, DateTimeImmutable $now): ?int
    {
        $statement = $this->pdo->prepare(<<<'SQL'
            INSERT INTO pa_sessions (uuid, visitor_id, started_at, last_seen_at)
            VALUES (:uuid, :visitor_id, :now, :now)
            ON DUPLICATE KEY UPDATE uuid = VALUES(uuid)
            SQL);
        $statement->execute([
            'uuid' => self::uuidToBinary($uuid),
            'visitor_id' => $visitorId,
            'now' => self::date($now),
        ]);

        $lookup = $this->pdo->prepare('SELECT id, visitor_id FROM pa_sessions WHERE uuid = :uuid');
        $lookup->execute(['uuid' => self::uuidToBinary($uuid)]);
        $session = $lookup->fetch(PDO::FETCH_ASSOC);
        if ($session === false) {
            throw new RuntimeException('Unable to resolve session.');
        }

        return (int) $session['visitor_id'] === $visitorId ? (int) $session['id'] : null;
    }

    private function idByUuid(string $table, string $uuid): ?int
    {
        if (!in_array($table, ['pa_visitors', 'pa_sessions'], true)) {
            throw new RuntimeException('Unsupported UUID table.');
        }

        $statement = $this->pdo->prepare("SELECT id FROM {$table} WHERE uuid = :uuid");
        $statement->execute(['uuid' => self::uuidToBinary($uuid)]);
        $id = $statement->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    private function normalizePath(string $path): string
    {
        $queryPosition = strpos($path, '?');
        if (!$this->config->trackQueryParameters && $queryPosition !== false) {
            $path = substr($path, 0, $queryPosition);
        }

        return substr($path === '' ? '/' : $path, 0, 2048);
    }

    /** @param mixed $value */
    private function encode(mixed $value): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
        } catch (JsonException $exception) {
            throw new RuntimeException('Unable to encode event data.', previous: $exception);
        }
    }

    private function transaction(callable $operation): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $operation();
            $this->pdo->commit();

            return $result;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    private static function uuidToBinary(string $uuid): string
    {
        $binary = hex2bin(str_replace('-', '', $uuid));
        if ($binary === false || strlen($binary) !== 16) {
            throw new RuntimeException('Invalid UUID.');
        }

        return $binary;
    }

    private static function date(DateTimeImmutable $date): string
    {
        return $date->format('Y-m-d H:i:s.v');
    }

    private static function bounded(mixed $value, int $length): ?string
    {
        return is_string($value) && $value !== '' ? mb_substr($value, 0, $length) : null;
    }
}
