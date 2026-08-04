<?php

/*
 * SPDX-FileCopyrightText: 2025-2026 Zorin Projects S.P. <creator@localzet.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Illuminate\View\Compilers\BladeCompiler;

require dirname(__DIR__).'/vendor/autoload.php';

if (! function_exists('csrf_token')) {
    function csrf_token(): string
    {
        return 'test';
    }
}
if (! function_exists('route')) {
    function route(string $name, array $parameters = []): string
    {
        return '/admin/analytics/assets/'.($parameters['asset'] ?? '');
    }
}
if (! function_exists('config')) {
    function config(?string $key = null): mixed
    {
        return match ($key) {
            'personal-analytics.admin_prefix' => 'admin/analytics',
            'personal-analytics.allowed_host' => 'example.test',
            'personal-analytics.admin_auth_mode' => 'middleware',
            default => null,
        };
    }
}
if (! function_exists('url')) {
    function url(string $path): string
    {
        return 'https://example.test/'.ltrim($path, '/');
    }
}

$compiler = new BladeCompiler(new Filesystem(), sys_get_temp_dir());
$compiled = $compiler->compileString(file_get_contents(dirname(__DIR__).'/resources/views/dashboard.blade.php'));
ob_start();
eval('?>'.$compiled);
$html = (string) ob_get_clean();
if (! str_contains($html, '"apiBase":"https:\/\/example.test\/admin\/analytics\/api"')) {
    throw new RuntimeException('Admin configuration JSON was not rendered.');
}

fwrite(STDOUT, "Blade compilation and render OK\n");
