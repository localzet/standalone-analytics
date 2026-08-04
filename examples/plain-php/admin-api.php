<?php
/*
 * SPDX-FileCopyrightText: 2025-2026 Zorin Projects S.P. <creator@localzet.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

$config = require __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if ($config['adminToken'] === '' || !hash_equals('Bearer ' . $config['adminToken'], $authorization)) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized.']);
    exit;
}

try {
    $to = new DateTimeImmutable($_GET['to'] ?? 'now');
    $from = new DateTimeImmutable($_GET['from'] ?? '-30 days');
    $view = $_GET['view'] ?? 'dashboard';
    $analytics = $config['analytics'];

    $result = match ($view) {
        'dashboard' => [
            'summary' => $analytics->reports->summary($from, $to),
            'timeline' => $analytics->reports->timeline($from, $to),
            'pages' => $analytics->reports->pages($from, $to),
            'referrers' => $analytics->reports->referrers($from, $to),
            'utm' => $analytics->reports->utm($from, $to),
            'goals' => $analytics->reports->goals($from, $to),
            'devices' => $analytics->reports->audience('device_type', $from, $to),
        ],
        'replays' => $analytics->replays->latest(),
        'replay' => $analytics->replays->events(filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0),
        'heatmaps' => $analytics->heatmaps->all(),
        'heatmap' => $analytics->heatmaps->data(
            filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0,
            in_array($_GET['device'] ?? '', ['desktop', 'tablet', 'mobile'], true) ? $_GET['device'] : 'desktop',
        ),
        default => throw new InvalidArgumentException('Unknown view.'),
    };

    echo json_encode($result, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $exception) {
    http_response_code(422);
    echo json_encode(['error' => $exception->getMessage()]);
}
