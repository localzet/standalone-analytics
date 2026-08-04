<?php
/*
 * SPDX-FileCopyrightText: 2025-2026 Zorin Projects S.P. <creator@localzet.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Localzet\PersonalAnalytics;

use DateTimeImmutable;
use JsonException;
use Localzet\PersonalAnalytics\Exception\InvalidEvent;
use Localzet\PersonalAnalytics\Exception\UnauthorizedCollection;

final readonly class Collector
{
    private const TYPES = [
        'visitor', 'landing_page', 'pageview', 'click', 'scroll', 'form', 'resize',
        'replay', 'heatmap_snapshot', 'goal_conversion', 'event',
    ];

    public function __construct(
        private Repository $repository,
        private Config $config,
    ) {
    }

    public function collectJson(string $json, RequestContext $context): CollectionResult
    {
        if (strlen($json) > $this->config->maxPayloadBytes) {
            throw new InvalidEvent('Payload is too large.');
        }
        if (str_starts_with($json, "PA1GZIP\n")) {
            $decoded = gzdecode(substr($json, 8));
            if ($decoded === false) {
                throw new InvalidEvent('Payload contains invalid gzip data.');
            }
            $json = $decoded;
        }
        if (strlen($json) > $this->config->maxDecodedPayloadBytes) {
            throw new InvalidEvent('Decoded payload is too large.');
        }

        try {
            $payload = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidEvent('Payload must be valid JSON.', previous: $exception);
        }

        if (!is_array($payload)) {
            throw new InvalidEvent('Payload must be a JSON object.');
        }

        if (($payload['schemaVersion'] ?? null) === 2) {
            $events = $payload['events'] ?? null;
            if (!is_array($events) || !array_is_list($events) || $events === [] || count($events) > 100) {
                throw new InvalidEvent('Event batch is invalid.');
            }
            foreach ($events as $event) {
                if (!is_array($event)) throw new InvalidEvent('Batch event is invalid.');
                $this->collect($event, $context);
            }
            return new CollectionResult(true);
        }

        return $this->collect($payload, $context);
    }

    /** @param array<string, mixed> $payload */
    public function collect(array $payload, RequestContext $context): CollectionResult
    {
        if (($payload['schemaVersion'] ?? null) !== 1) {
            throw new InvalidEvent('Unsupported schema version.');
        }
        $this->authorize($payload, $context);
        $type = $this->string($payload, 'type', 32);
        if (!in_array($type, self::TYPES, true)) {
            throw new InvalidEvent('Unsupported event type.');
        }

        $data = $payload['data'] ?? [];
        if (!is_array($data)) {
            throw new InvalidEvent('Event data must be an object or array.');
        }

        $now = new DateTimeImmutable('now');
        $accepted = match ($type) {
            'visitor' => $this->visitor($payload, $data, $context, $now),
            'landing_page', 'pageview' => $this->page($payload, $type, $data, $now),
            'click', 'scroll', 'form', 'resize' => $this->action($payload, $type, $data, $now),
            'replay' => $this->replay($payload, $data, $now),
            'heatmap_snapshot' => $this->snapshot($payload, $data, $now),
            'goal_conversion' => $this->goal($payload, $now),
            'event' => $this->genericEvent($payload, $data, $now),
        };

        return new CollectionResult($accepted, $accepted ? null : $this->refreshFor($type));
    }

    /** @param array<string, mixed> $payload @param array<string, mixed> $data */
    private function visitor(array $payload, array $data, RequestContext $context, DateTimeImmutable $now): bool
    {
        $this->repository->upsertVisitor($this->uuid($payload, 'visitorId'), $this->visitorData($data), $context, $now);

        return true;
    }

    /** @param array<string, mixed> $payload @param array<string, mixed> $data */
    private function page(array $payload, string $type, array $data, DateTimeImmutable $now): bool
    {
        $path = $this->string($data, 'path', 2048);
        if (!str_starts_with($path, '/')) {
            throw new InvalidEvent('Page path must start with a slash.');
        }
        $this->dimensions($data['viewport'] ?? null, 'viewport');
        $this->utm($data['utm'] ?? null);

        return $this->repository->storePageEvent(
            $this->uuid($payload, 'visitorId'),
            $this->uuid($payload, 'sessionId'),
            $this->uuid($payload, 'eventId'),
            $type,
            $data,
            $now,
        );
    }

    /** @param array<string, mixed> $payload @param array<string, mixed> $data */
    private function action(array $payload, string $type, array $data, DateTimeImmutable $now): bool
    {
        if (strlen(json_encode($data) ?: '') > 16_384) {
            throw new InvalidEvent('Action data is too large.');
        }

        $count = filter_var($payload['count'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
        if ($count === false) {
            throw new InvalidEvent('Action count is invalid.');
        }
        $heatmapKey = isset($payload['heatmapKey']) ? $this->string($payload, 'heatmapKey', 64) : null;

        return $this->repository->storeAction(
            $this->uuid($payload, 'visitorId'),
            $this->uuid($payload, 'sessionId'),
            $this->uuid($payload, 'eventId'),
            $type,
            $data,
            $count,
            $heatmapKey,
            $now,
        );
    }

    /** @param array<string, mixed> $payload @param array<mixed> $data */
    private function replay(array $payload, array $data, DateTimeImmutable $now): bool
    {
        if (!array_is_list($data) || $data === [] || count($data) > $this->config->maxReplayChunkEvents) {
            throw new InvalidEvent('Replay chunk size is invalid.');
        }

        $sequence = filter_var($payload['sequence'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($sequence === false) {
            throw new InvalidEvent('Replay sequence is invalid.');
        }

        return $this->repository->appendReplay(
            $this->uuid($payload, 'visitorId'),
            $this->uuid($payload, 'sessionId'),
            $this->uuid($payload, 'chunkId'),
            $sequence,
            $data,
            $now,
        );
    }

    /** @param array<string, mixed> $payload @param array<mixed> $data */
    private function snapshot(array $payload, array $data, DateTimeImmutable $now): bool
    {
        if (!array_is_list($data) || count($data) < 2 || count($data) > 10) {
            throw new InvalidEvent('Heatmap snapshot is invalid.');
        }
        $deviceType = $this->string($payload, 'deviceType', 16);
        if (!in_array($deviceType, ['desktop', 'tablet', 'mobile'], true)) {
            throw new InvalidEvent('Device type is invalid.');
        }
        $viewportWidth = filter_var($payload['viewportWidth'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
        if ($viewportWidth === false) {
            throw new InvalidEvent('Viewport width is invalid.');
        }

        $this->repository->storeHeatmapSnapshot(
            $this->string($payload, 'heatmapKey', 64),
            $deviceType,
            $viewportWidth,
            $data,
            $now,
        );

        return true;
    }

    /** @param array<string, mixed> $payload */
    private function goal(array $payload, DateTimeImmutable $now): bool
    {
        return $this->repository->convertGoal(
            $this->uuid($payload, 'visitorId'),
            $this->uuid($payload, 'sessionId'),
            $this->uuid($payload, 'eventId'),
            $this->string($payload, 'goalKey', 64),
            $this->pathFromUrl($this->string($payload, 'url', 4096)),
            $now,
        );
    }

    /** @param array<string, mixed> $payload @param array<string, mixed> $data */
    private function genericEvent(array $payload, array $data, DateTimeImmutable $now): bool
    {
        $name = $this->string($data, 'name', 128);
        if (preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._:-]{0,127}$/', $name) !== 1) {
            throw new InvalidEvent('Event name is invalid.');
        }
        $category = $this->string($data, 'category', 32);
        if (!in_array($category, ['interaction', 'navigation', 'engagement', 'performance', 'error', 'network', 'ecommerce', 'custom'], true)) {
            throw new InvalidEvent('Event category is invalid.');
        }

        return $this->repository->storeGenericEvent(
            $this->uuid($payload, 'telemetryId'),
            $this->uuid($payload, 'visitorId'),
            $this->uuid($payload, 'sessionId'),
            $this->uuid($payload, 'eventId'),
            $category,
            $name,
            $data,
            $now,
        );
    }

    /** @param array<string, mixed> $payload */
    private function authorize(array $payload, RequestContext $context): void
    {
        $token = $this->string($payload, 'projectToken', 128);
        if (!hash_equals($this->config->projectToken, $token)) {
            throw new UnauthorizedCollection('Project token is invalid.');
        }

        $url = $this->string($payload, 'url', 4096);
        $scheme = parse_url($url, PHP_URL_SCHEME);
        if (
            !filter_var($url, FILTER_VALIDATE_URL)
            || !in_array($scheme, ['http', 'https'], true)
            || strcasecmp((string) parse_url($url, PHP_URL_HOST), $this->config->allowedHost) !== 0
        ) {
            throw new UnauthorizedCollection('Event URL host is not allowed.');
        }

        if ($this->config->allowedOrigins !== []) {
            if ($context->origin === null) {
                throw new UnauthorizedCollection('Origin header is required.');
            }
            $origin = rtrim($context->origin, '/');
            $allowed = array_map(static fn (string $value): string => rtrim($value, '/'), $this->config->allowedOrigins);
            if (!in_array($origin, $allowed, true)) {
                throw new UnauthorizedCollection('Origin is not allowed.');
            }
        }

        if ($context->ip !== null && in_array($context->ip, $this->config->excludedIps, true)) {
            throw new UnauthorizedCollection('IP address is excluded.');
        }
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function visitorData(array $data): array
    {
        $this->dimensions($data['screen'] ?? null, 'screen');
        if (isset($data['timezone']) && (!is_string($data['timezone']) || !in_array($data['timezone'], timezone_identifiers_list(), true))) {
            throw new InvalidEvent('Timezone is invalid.');
        }
        if (isset($data['theme']) && !in_array($data['theme'], ['light', 'dark'], true)) {
            throw new InvalidEvent('Theme is invalid.');
        }
        if (isset($data['deviceType']) && !in_array($data['deviceType'], ['desktop', 'tablet', 'mobile'], true)) {
            throw new InvalidEvent('Device type is invalid.');
        }
        if (isset($data['customParameters'])) {
            if (!is_array($data['customParameters']) || array_is_list($data['customParameters']) || count($data['customParameters']) > 10) {
                throw new InvalidEvent('Custom parameters are invalid.');
            }
            foreach ($data['customParameters'] as $key => $value) {
                if (!is_string($key) || strlen($key) > 64 || !is_scalar($value) || strlen((string) $value) > 512) {
                    throw new InvalidEvent('Custom parameter is invalid.');
                }
            }
        }

        return $data;
    }

    private function dimensions(mixed $value, string $field): void
    {
        if (!is_array($value)) {
            throw new InvalidEvent(ucfirst($field) . ' is required.');
        }
        foreach (['width', 'height'] as $dimension) {
            $valid = filter_var($value[$dimension] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
            if ($valid === false) {
                throw new InvalidEvent(ucfirst($field) . ' dimensions are invalid.');
            }
        }
    }

    private function utm(mixed $value): void
    {
        if ($value === null) {
            return;
        }
        if (!is_array($value)) {
            throw new InvalidEvent('UTM data is invalid.');
        }
        foreach (['source', 'medium', 'campaign', 'term', 'content'] as $key) {
            if (isset($value[$key]) && (!is_string($value[$key]) || strlen($value[$key]) > 255)) {
                throw new InvalidEvent('UTM value is invalid.');
            }
        }
    }

    /** @param array<string, mixed> $data */
    private function uuid(array $data, string $key): string
    {
        $value = $this->string($data, $key, 36);
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value) !== 1) {
            throw new InvalidEvent("{$key} must be a UUID v4.");
        }

        return $value;
    }

    /** @param array<string, mixed> $data */
    private function string(array $data, string $key, int $maxLength): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value) || $value === '' || strlen($value) > $maxLength) {
            throw new InvalidEvent("{$key} is invalid.");
        }

        return $value;
    }

    private function pathFromUrl(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);

        return is_string($path) && $path !== '' ? $path : '/';
    }

    private function refreshFor(string $type): string
    {
        return $type === 'visitor' ? 'visitor' : (in_array($type, ['landing_page', 'pageview'], true) ? 'visitor' : 'session');
    }
}
