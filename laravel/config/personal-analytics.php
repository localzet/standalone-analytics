<?php

/*
 * SPDX-FileCopyrightText: 2025-2026 Zorin Projects S.P. <creator@localzet.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

return [
    'enabled' => env('PERSONAL_ANALYTICS_ENABLED', false),
    'project_token' => env('PERSONAL_ANALYTICS_TOKEN'),
    'allowed_host' => env('PERSONAL_ANALYTICS_HOST', parse_url((string) env('APP_URL'), PHP_URL_HOST)),
    'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', (string) env('PERSONAL_ANALYTICS_ORIGINS', env('APP_URL')))))),
    'connection' => env('PERSONAL_ANALYTICS_DB_CONNECTION', env('DB_CONNECTION', 'pgsql')),
    'queue_connection' => env('PERSONAL_ANALYTICS_QUEUE_CONNECTION', env('QUEUE_CONNECTION')),
    'queue' => env('PERSONAL_ANALYTICS_QUEUE', 'analytics'),
    'storage_disk' => env('PERSONAL_ANALYTICS_STORAGE_DISK', env('FILESYSTEM_DISK', 'local')),
    'storage_prefix' => env('PERSONAL_ANALYTICS_STORAGE_PREFIX', 'analytics/replays'),
    'admin_middleware' => ['web', 'auth'],
    'admin_prefix' => 'admin/analytics',
    'collector_path' => 'api/analytics/collect',
    'max_payload_bytes' => (int) env('PERSONAL_ANALYTICS_MAX_PAYLOAD_BYTES', 8_388_608),
    'max_batch_events' => 100,
    'store_ip' => env('PERSONAL_ANALYTICS_STORE_IP', false),
    'rate_limit_per_minute' => 600,
    'retention' => [
        'events_days' => 365,
        'replays_days' => 30,
        'rollups_days' => 1095,
    ],
    'partitions_ahead_months' => 3,
    'replay' => [
        'sample_rate' => 1.0,
        'minimum_duration_seconds' => 1,
    ],
];
