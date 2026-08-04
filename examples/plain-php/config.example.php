<?php
/*
 * SPDX-FileCopyrightText: 2025-2026 Zorin Projects S.P. <creator@localzet.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

use Localzet\PersonalAnalytics\Analytics;
use Localzet\PersonalAnalytics\Config;

require dirname(__DIR__, 2) . '/php/vendor/autoload.php';

$pdo = new PDO(
    getenv('ANALYTICS_DSN') ?: 'mysql:host=127.0.0.1;dbname=personal_analytics;charset=utf8mb4',
    getenv('ANALYTICS_DB_USER') ?: 'analytics',
    getenv('ANALYTICS_DB_PASSWORD') ?: '',
    [PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC],
);

$allowedOrigin = getenv('ANALYTICS_ALLOWED_ORIGIN') ?: 'https://project.example.test';

return [
    'analytics' => new Analytics($pdo, new Config(
        projectToken: getenv('ANALYTICS_PROJECT_TOKEN') ?: 'change-this-public-token',
        allowedHost: getenv('ANALYTICS_ALLOWED_HOST') ?: 'project.example.test',
        allowedOrigins: [$allowedOrigin],
        storeIp: false,
    )),
    'allowedOrigin' => $allowedOrigin,
    'adminToken' => getenv('ANALYTICS_ADMIN_TOKEN') ?: '',
];
