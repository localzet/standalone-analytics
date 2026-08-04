<?php

/*
 * SPDX-FileCopyrightText: 2025-2026 Zorin Projects S.P. <creator@localzet.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Localzet\LaravelAnalytics\Services;

use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final readonly class ReplayStorage
{
    public function __construct(private FilesystemManager $filesystems) {}

    /** @param list<mixed> $events */
    public function putChunk(string $visitorId, string $sessionId, string $chunkId, int $sequence, array $events, string $occurredAt): void
    {
        $connection = DB::connection(config('personal-analytics.connection'));
        $replay = $connection->table('analytics_replays')->where('session_id', $sessionId)->first();
        $replayId = $replay?->id ?? (string) Str::uuid();
        $payload = gzencode(json_encode($events, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE), 4);
        if ($payload === false) {
            throw new RuntimeException('Unable to compress replay chunk.');
        }
        $path = trim((string) config('personal-analytics.storage_prefix'), '/')."/{$replayId}/{$sequence}-{$chunkId}.json.gz";
        if (! $this->filesystems->disk((string) config('personal-analytics.storage_disk'))->put($path, $payload, ['visibility' => 'private'])) {
            throw new RuntimeException('Unable to store replay chunk.');
        }
        $expiresAt = now()->addDays((int) config('personal-analytics.retention.replays_days'));

        try {
            $connection->transaction(function () use ($connection, $replayId, $sessionId, $visitorId, $events, $payload, $occurredAt, $expiresAt, $chunkId, $sequence, $path): void {
                $connection->table('analytics_replays')->upsert([[
                    'id' => $replayId, 'session_id' => $sessionId, 'visitor_id' => $visitorId,
                    'events_count' => 0, 'bytes' => 0, 'started_at' => $occurredAt,
                    'last_seen_at' => $occurredAt, 'expires_at' => $expiresAt,
                ]], ['session_id'], ['last_seen_at', 'expires_at']);
                $inserted = $connection->table('analytics_replay_chunks')->insertOrIgnore([
                    'id' => $chunkId, 'replay_id' => $replayId, 'sequence_no' => $sequence,
                    'event_count' => count($events), 'storage_path' => $path, 'bytes' => strlen($payload), 'created_at' => $occurredAt,
                ]);
                if ($inserted > 0) {
                    $connection->table('analytics_replays')->where('id', $replayId)->update([
                        'events_count' => DB::raw('events_count + '.count($events)),
                        'bytes' => DB::raw('bytes + '.strlen($payload)),
                        'last_seen_at' => $occurredAt,
                    ]);
                }
            });
        } catch (\Throwable $exception) {
            $this->filesystems->disk((string) config('personal-analytics.storage_disk'))->delete($path);
            throw $exception;
        }
    }

    /** @return list<array<string, mixed>> */
    public function events(string $replayId): array
    {
        return $this->playback($replayId)['events'];
    }

    /** @return array{events: list<array<string, mixed>>, chunks: int, missingChunks: list<string>, corruptedChunks: list<string>, complete: bool} */
    public function playback(string $replayId): array
    {
        $chunks = DB::connection(config('personal-analytics.connection'))->table('analytics_replay_chunks')
            ->where('replay_id', $replayId)->orderBy('created_at')->orderBy('sequence_no')->get();
        $events = [];
        $missingChunks = [];
        $corruptedChunks = [];
        $disk = $this->filesystems->disk((string) config('personal-analytics.storage_disk'));
        foreach ($chunks as $chunk) {
            try {
                $payload = $disk->get($chunk->storage_path);
            } catch (\Throwable) {
                $payload = null;
            }
            if (! is_string($payload)) {
                $missingChunks[] = (string) $chunk->id;
                continue;
            }
            $decoded = gzdecode($payload);
            if ($decoded === false) {
                $corruptedChunks[] = (string) $chunk->id;
                continue;
            }
            try {
                $chunkEvents = json_decode($decoded, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $corruptedChunks[] = (string) $chunk->id;
                continue;
            }
            if (! is_array($chunkEvents) || ! array_is_list($chunkEvents)) {
                $corruptedChunks[] = (string) $chunk->id;
                continue;
            }
            array_push($events, ...$chunkEvents);
        }

        return [
            'events' => $events,
            'chunks' => $chunks->count(),
            'missingChunks' => $missingChunks,
            'corruptedChunks' => $corruptedChunks,
            'complete' => $missingChunks === [] && $corruptedChunks === [],
        ];
    }
}
