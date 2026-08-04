<?php
/*
 * SPDX-FileCopyrightText: 2025-2026 Zorin Projects S.P. <creator@localzet.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

use Localzet\PersonalAnalytics\Exception\InvalidEvent;
use Localzet\PersonalAnalytics\Exception\UnauthorizedCollection;
use Localzet\PersonalAnalytics\RequestContext;

$config = require __DIR__ . '/config.php';
$origin = $_SERVER['HTTP_ORIGIN'] ?? null;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if ($origin !== null && rtrim($origin, '/') === rtrim($config['allowedOrigin'], '/')) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['error' => 'Method not allowed.']);
    exit;
}

try {
    $body = file_get_contents('php://input');
    if ($body === false) {
        throw new InvalidEvent('Unable to read request body.');
    }
    $result = $config['analytics']->collector->collectJson($body, new RequestContext(
        origin: $origin,
        ip: $_SERVER['REMOTE_ADDR'] ?? null,
        userAgent: $_SERVER['HTTP_USER_AGENT'] ?? null,
        acceptLanguage: $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? null,
    ));
    echo json_encode($result->toArray(), JSON_THROW_ON_ERROR);
} catch (UnauthorizedCollection $exception) {
    http_response_code(403);
    echo json_encode(['error' => $exception->getMessage()]);
} catch (InvalidEvent $exception) {
    http_response_code(422);
    echo json_encode(['error' => $exception->getMessage()]);
} catch (Throwable) {
    http_response_code(500);
    echo json_encode(['error' => 'Collector failure.']);
}
