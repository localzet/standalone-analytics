<?php

/*
 * SPDX-FileCopyrightText: 2025-2026 Zorin Projects S.P. <creator@localzet.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Localzet\LaravelAnalytics\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Localzet\LaravelAnalytics\Services\HeatmapStorage;
use Localzet\LaravelAnalytics\Services\ReplayStorage;
use Localzet\LaravelAnalytics\Services\ReportService;

final class AnalyticsAdminController extends Controller
{
    public function index(): View
    {
        return view('personal-analytics::dashboard');
    }

    public function report(string $report, Request $request, ReportService $reports): JsonResponse
    {
        $from = CarbonImmutable::parse((string) $request->query('from', '-30 days'))->startOfDay();
        $to = CarbonImmutable::parse((string) $request->query('to', 'now'))->endOfDay();
        abort_if($from->greaterThan($to), 422, 'Start date must not be after end date.');
        abort_if($from->diffInDays($to) > 1095, 422, 'Date range is too large.');
        $data = match ($report) {
            'dashboard' => $reports->dashboard($from, $to),
            'realtime' => $reports->realtime(),
            'journeys' => $reports->journeys($from, $to),
            'replays' => $reports->replays($from, $to),
            'heatmaps' => $reports->heatmaps($from, $to),
            default => abort(404),
        };

        return response()->json($data)->header('Cache-Control', 'private, max-age=30');
    }

    public function replay(string $replay, ReplayStorage $storage): JsonResponse
    {
        return response()->json(['events' => $storage->events($replay)])->header('Cache-Control', 'private, no-store');
    }

    public function heatmapSnapshot(string $heatmap, string $device, HeatmapStorage $storage): JsonResponse
    {
        abort_unless(in_array($device, ['desktop', 'tablet', 'mobile'], true), 404);

        return response()->json(['events' => $storage->snapshot($heatmap, $device)])->header('Cache-Control', 'private, no-store');
    }
}
