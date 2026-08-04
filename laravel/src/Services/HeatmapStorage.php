<?php

/*
 * SPDX-FileCopyrightText: 2025-2026 Zorin Projects S.P. <creator@localzet.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Localzet\LaravelAnalytics\Services;

use Illuminate\Database\DatabaseManager;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Support\Str;
use RuntimeException;

final readonly class HeatmapStorage
{
    public function __construct(private DatabaseManager $database, private FilesystemManager $filesystems) {}

    /** @param list<mixed> $events */
    public function putSnapshot(string $key, string $path, string $deviceType, int $viewportWidth, array $events, string $occurredAt): void
    {
        if (! preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._:-]{0,63}$/', $key)) {
            return;
        }
        $deviceType = in_array($deviceType, ['desktop', 'tablet', 'mobile'], true) ? $deviceType : 'desktop';
        $connection = $this->database->connection(config('personal-analytics.connection'));
        $snapshotId = (string) Str::uuid();
        $payload = gzencode(json_encode($events, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE), 4);
        if ($payload === false) {
            throw new RuntimeException('Unable to compress heatmap snapshot.');
        }
        $storagePath = trim((string) config('personal-analytics.storage_prefix'), '/')."/heatmaps/{$key}/{$deviceType}-{$snapshotId}.json.gz";
        $disk = $this->filesystems->disk((string) config('personal-analytics.storage_disk'));
        if (! $disk->put($storagePath, $payload, ['visibility' => 'private'])) {
            throw new RuntimeException('Unable to store heatmap snapshot.');
        }

        $previous = $connection->table('analytics_heatmap_snapshots')->where('heatmap_key', $key)->where('device_type', $deviceType)->value('storage_path');
        try {
            $connection->transaction(function () use ($connection, $key, $path, $deviceType, $viewportWidth, $snapshotId, $storagePath, $occurredAt): void {
                $connection->table('analytics_heatmaps')->upsert([[
                    'key' => $key, 'name' => $key, 'path' => $path, 'enabled' => true,
                    'created_at' => $occurredAt, 'updated_at' => $occurredAt,
                ]], ['key'], ['path', 'updated_at']);
                $connection->table('analytics_heatmap_snapshots')->upsert([[
                    'id' => $snapshotId, 'heatmap_key' => $key, 'device_type' => $deviceType,
                    'viewport_width' => max(1, $viewportWidth), 'storage_path' => $storagePath, 'created_at' => $occurredAt,
                ]], ['heatmap_key', 'device_type'], ['id', 'viewport_width', 'storage_path', 'created_at']);
            });
        } catch (\Throwable $exception) {
            $disk->delete($storagePath);
            throw $exception;
        }
        if (is_string($previous) && $previous !== $storagePath) {
            $disk->delete($previous);
        }
    }

    /** @return list<mixed> */
    public function snapshot(string $key, string $deviceType): array
    {
        $path = $this->database->connection(config('personal-analytics.connection'))->table('analytics_heatmap_snapshots')
            ->where('heatmap_key', $key)->where('device_type', $deviceType)->value('storage_path');
        if (! is_string($path)) {
            return [];
        }
        $decoded = gzdecode($this->filesystems->disk((string) config('personal-analytics.storage_disk'))->get($path));

        return $decoded === false ? [] : json_decode($decoded, true, 512, JSON_THROW_ON_ERROR);
    }
}
