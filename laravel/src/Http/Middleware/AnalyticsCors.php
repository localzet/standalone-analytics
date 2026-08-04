<?php

/*
 * SPDX-FileCopyrightText: 2025-2026 Zorin Projects S.P. <creator@localzet.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Localzet\LaravelAnalytics\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class AnalyticsCors
{
    public function handle(Request $request, Closure $next): Response
    {
        $origin = rtrim((string) $request->header('Origin'), '/');
        $allowed = array_map(static fn (string $value): string => rtrim($value, '/'), (array) config('personal-analytics.allowed_origins'));
        if ($request->isMethod('OPTIONS')) {
            abort_unless($origin !== '' && in_array($origin, $allowed, true), 403);
            $response = response()->noContent();
        } else {
            $response = $next($request);
        }
        if ($origin !== '' && in_array($origin, $allowed, true)) {
            $response->headers->set('Access-Control-Allow-Origin', $origin);
            $response->headers->set('Access-Control-Allow-Methods', 'POST, OPTIONS');
            $response->headers->set('Access-Control-Allow-Headers', 'Content-Type');
            $response->headers->set('Access-Control-Max-Age', '86400');
            $response->headers->set('Vary', 'Origin');
        }

        return $response;
    }
}
