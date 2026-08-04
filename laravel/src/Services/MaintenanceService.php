<?php
/*
 * SPDX-FileCopyrightText: 2025-2026 Zorin Projects S.P. <creator@localzet.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Localzet\LaravelAnalytics\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Filesystem\FilesystemManager;

final readonly class MaintenanceService
{
    public function __construct(private DatabaseManager $database, private FilesystemManager $filesystems)
    {
    }

    public function maintain(): array
    {
        $connection = $this->database->connection(config('personal-analytics.connection'));
        $this->createFuturePartitions();
        $eventCutoff = now()->subDays((int) config('personal-analytics.retention.events_days'));
        $partitionsDropped = $this->dropExpiredPartitions(CarbonImmutable::instance($eventCutoff));
        $rollupCutoff = now()->subDays((int) config('personal-analytics.retention.rollups_days'))->toDateString();
        $deletedEvents = $connection->table('analytics_events')->where('occurred_at', '<', $eventCutoff)->delete();
        $deletedRollups = $connection->table('analytics_daily_rollups')->where('day', '<', $rollupCutoff)->delete();
        $expired = $connection->table('analytics_replays')->where('expires_at', '<', now())->pluck('id');
        $disk = $this->filesystems->disk((string) config('personal-analytics.storage_disk'));
        foreach ($expired as $id) $disk->deleteDirectory(trim((string) config('personal-analytics.storage_prefix'), '/') . '/' . $id);
        $deletedReplays = $connection->table('analytics_replays')->whereIn('id', $expired)->delete();

        return compact('partitionsDropped', 'deletedEvents', 'deletedRollups', 'deletedReplays');
    }

    public function createFuturePartitions(): void
    {
        $connection = $this->database->connection(config('personal-analytics.connection'));
        $months = (int) config('personal-analytics.partitions_ahead_months', 3);
        for ($offset = 0; $offset <= $months; $offset++) {
            $start = CarbonImmutable::now()->startOfMonth()->addMonths($offset);
            $end = $start->addMonth();
            $table = 'analytics_events_' . $start->format('Ym');
            $from = $connection->getPdo()->quote($start->toDateString());
            $to = $connection->getPdo()->quote($end->toDateString());
            try {
                $connection->statement("CREATE TABLE IF NOT EXISTS {$table} PARTITION OF analytics_events FOR VALUES FROM ({$from}) TO ({$to})");
            } catch (\Throwable $exception) {
                report($exception);
            }
        }
    }

    private function dropExpiredPartitions(CarbonImmutable $cutoff): int
    {
        $connection = $this->database->connection(config('personal-analytics.connection'));
        $partitions = $connection->select(<<<'SQL'
            SELECT child.relname AS name
            FROM pg_inherits
            JOIN pg_class parent ON pg_inherits.inhparent = parent.oid
            JOIN pg_class child ON pg_inherits.inhrelid = child.oid
            WHERE parent.relname = 'analytics_events'
            SQL);
        $dropped = 0;
        foreach ($partitions as $partition) {
            if (!preg_match('/^analytics_events_(\d{6})$/', (string) $partition->name, $matches)) continue;
            $month = CarbonImmutable::createFromFormat('!Ym', $matches[1]);
            if ($month === false || $month->addMonth()->greaterThan($cutoff)) continue;
            $connection->statement('DROP TABLE IF EXISTS ' . $partition->name);
            $dropped++;
        }
        return $dropped;
    }
}
