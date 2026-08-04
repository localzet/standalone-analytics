<?php
/*
 * SPDX-FileCopyrightText: 2025-2026 Zorin Projects S.P. <creator@localzet.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Localzet\LaravelAnalytics\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Localzet\LaravelAnalytics\Services\IngestionService;

final class ProcessAnalyticsBatch implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;
    public int $timeout = 120;

    /** @param list<array<string, mixed>> $events @param array<string, mixed> $context */
    public function __construct(public array $events, public array $context)
    {
    }

    public function handle(IngestionService $ingestion): void
    {
        $ingestion->ingest($this->events, $this->context);
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [1, 5, 30, 120];
    }
}
