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

final class AuthenticateAnalyticsAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (config('personal-analytics.admin_auth_mode', 'middleware') !== 'token') {
            return $next($request);
        }

        $expected = (string) config('personal-analytics.admin_token');
        $provided = (string) $request->bearerToken();
        abort_if($expected === '', 503, 'Analytics admin token is not configured.');
        abort_unless(
            $provided !== '' && hash_equals(hash('sha256', $expected), hash('sha256', $provided)),
            401,
            'Invalid analytics admin token.',
        );

        return $next($request);
    }
}
