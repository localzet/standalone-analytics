<?php

/*
 * SPDX-FileCopyrightText: 2025-2026 Zorin Projects S.P. <creator@localzet.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Localzet\LaravelAnalytics\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Bus;
use JsonException;
use Localzet\LaravelAnalytics\Contracts\GeoResolver;
use Localzet\LaravelAnalytics\Contracts\UserAgentResolver;
use Localzet\LaravelAnalytics\Jobs\ProcessAnalyticsBatch;

final class AnalyticsCollectorController extends Controller
{
    public function __invoke(Request $request, GeoResolver $geo, UserAgentResolver $userAgent): JsonResponse
    {
        $body = $request->getContent();
        abort_if(strlen($body) > (int) config('personal-analytics.max_payload_bytes'), 413, 'Analytics payload is too large.');
        try {
            $payload = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            abort(422, 'Analytics payload contains invalid JSON.');
        }
        abort_unless(is_array($payload), 422, 'Analytics payload must be an object.');
        abort_unless(hash_equals((string) config('personal-analytics.project_token'), (string) ($payload['projectToken'] ?? '')), 403);
        $origin = rtrim((string) $request->header('Origin'), '/');
        $origins = array_map(static fn (string $value): string => rtrim($value, '/'), (array) config('personal-analytics.allowed_origins'));
        abort_unless($origin !== '' && in_array($origin, $origins, true), 403);

        $events = ($payload['schemaVersion'] ?? null) === 2 ? ($payload['events'] ?? []) : [$payload];
        abort_unless(is_array($events) && array_is_list($events) && $events !== [] && count($events) <= (int) config('personal-analytics.max_batch_events'), 422);
        foreach ($events as $event) {
            $host = is_array($event) ? parse_url((string) ($event['url'] ?? ''), PHP_URL_HOST) : null;
            abort_unless(is_string($host) && strcasecmp($host, (string) config('personal-analytics.allowed_host')) === 0, 403);
        }

        $ip = $request->ip();
        $job = (new ProcessAnalyticsBatch($events, [
            'ip' => config('personal-analytics.store_ip') ? $ip : null,
            'language' => substr((string) $request->header('Accept-Language'), 0, 16),
            'geo' => $geo->resolve($ip),
            'technology' => $userAgent->resolve($request->userAgent()),
            'receivedAt' => now()->toISOString(),
        ]))->onConnection(config('personal-analytics.queue_connection'))->onQueue((string) config('personal-analytics.queue'));
        Bus::dispatch($job);

        return response()->json(['accepted' => true], 202)->header('Cache-Control', 'no-store');
    }
}
