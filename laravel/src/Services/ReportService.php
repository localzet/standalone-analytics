<?php

/*
 * SPDX-FileCopyrightText: 2025-2026 Zorin Projects S.P. <creator@localzet.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Localzet\LaravelAnalytics\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;

final readonly class ReportService
{
    public function __construct(private DatabaseManager $database) {}

    public function dashboard(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return [
            'overview' => $this->overview($from, $to),
            'timeline' => $this->timeline($from, $to),
            'events' => $this->top($from, $to, 'event'),
            'pages' => $this->top($from, $to, 'path'),
            'sources' => $this->top($from, $to, 'source'),
            'mediums' => $this->top($from, $to, 'medium'),
            'campaigns' => $this->top($from, $to, 'campaign'),
            'terms' => $this->top($from, $to, 'term'),
            'contents' => $this->top($from, $to, 'content'),
            'countries' => $this->top($from, $to, 'country'),
            'devices' => $this->top($from, $to, 'device'),
            'errors' => $this->category($from, $to, 'error'),
            'errorDetails' => $this->errorDetails($from, $to),
            'performance' => $this->category($from, $to, 'performance'),
            'ecommerce' => $this->ecommerce($from, $to),
            'funnels' => $this->funnels($from, $to),
            'goals' => $this->goals($from, $to),
            'replays' => $this->replays($from, $to),
            'heatmaps' => $this->heatmaps($from, $to),
        ];
    }

    public function overview(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $row = $this->connection()->table('analytics_events')->whereBetween('occurred_at', [$from, $to])->selectRaw(
            "COUNT(*) AS events, COUNT(DISTINCT visitor_id) AS visitors, COUNT(DISTINCT session_id) AS sessions, COUNT(*) FILTER (WHERE name = 'page_view') AS pageviews, COUNT(*) FILTER (WHERE name IN ('goal_conversion','purchase')) AS conversions, COALESCE(SUM(CASE WHEN category='ecommerce' THEN COALESCE((metrics->>'revenue')::numeric,0) ELSE 0 END),0) AS revenue",
        )->first();
        $session = $this->connection()->table('analytics_sessions')->whereBetween('started_at', [$from, $to])->selectRaw(
            'COALESCE(AVG(engagement_ms),0) AS engagement, COALESCE(AVG(CASE WHEN is_bounced THEN 1 ELSE 0 END) * 100,0) AS bounce_rate',
        )->first();

        return [
            'visitors' => (int) $row->visitors, 'sessions' => (int) $row->sessions, 'pageviews' => (int) $row->pageviews,
            'events' => (int) $row->events, 'conversions' => (int) $row->conversions, 'revenue' => (float) $row->revenue,
            'engagementMs' => (float) $session->engagement, 'bounceRate' => (float) $session->bounce_rate,
        ];
    }

    public function timeline(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return $this->connection()->table('analytics_events')->whereBetween('occurred_at', [$from, $to])
            ->selectRaw("date_trunc('day', occurred_at)::date AS day, COUNT(*) AS events, COUNT(DISTINCT visitor_id) AS visitors, COUNT(DISTINCT session_id) AS sessions")
            ->groupByRaw("date_trunc('day', occurred_at)::date")->orderBy('day')->get()->all();
    }

    public function top(CarbonImmutable $from, CarbonImmutable $to, string $dimension): array
    {
        return $this->connection()->table('analytics_daily_rollups')->where('dimension', $dimension)
            ->whereBetween('day', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('dimension_key AS value, SUM(visitors) AS visitors, SUM(sessions) AS sessions, SUM(events) AS events, SUM(conversions) AS conversions, SUM(revenue) AS revenue')
            ->groupBy('dimension_key')->orderByDesc('events')->limit(100)->get()->all();
    }

    public function category(CarbonImmutable $from, CarbonImmutable $to, string $category): array
    {
        return $this->connection()->table('analytics_events')->where('category', $category)->whereBetween('occurred_at', [$from, $to])
            ->selectRaw('name, COUNT(*) AS events, COUNT(DISTINCT visitor_id) AS visitors, AVG(COALESCE((metrics->>\'value\')::numeric, (metrics->>\'duration\')::numeric, 0)) AS average')
            ->groupBy('name')->orderByDesc('events')->limit(100)->get()->all();
    }

    public function errorDetails(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return $this->connection()->table('analytics_events')
            ->where('category', 'error')->whereBetween('occurred_at', [$from, $to])
            ->select(['id', 'name', 'path', 'visitor_id', 'session_id', 'occurred_at'])
            ->selectRaw(<<<'SQL'
                properties->>'message' AS message,
                properties->>'errorType' AS error_type,
                properties->>'method' AS method,
                properties->>'url' AS url,
                properties->>'status' AS status,
                properties->>'statusText' AS status_text,
                properties->>'source' AS source,
                properties->>'line' AS line,
                properties->>'column' AS column,
                properties->>'stack' AS stack,
                properties->>'reason' AS reason,
                properties->>'tag' AS resource_tag,
                metrics->>'duration' AS duration
                SQL)
            ->orderByDesc('occurred_at')->limit(200)->get()->all();
    }

    public function ecommerce(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return $this->connection()->table('analytics_events')->where('category', 'ecommerce')->whereBetween('occurred_at', [$from, $to])
            ->selectRaw("name, COUNT(*) AS events, COUNT(DISTINCT visitor_id) AS visitors, COALESCE(SUM((metrics->>'revenue')::numeric),0) AS revenue")
            ->groupBy('name')->orderByDesc('events')->get()->all();
    }

    public function funnels(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $funnels = $this->connection()->table('analytics_funnels')->where('enabled', true)->get();

        return $funnels->map(function (object $funnel) use ($from, $to): array {
            $steps = json_decode($funnel->steps, true) ?: [];
            $result = [];
            foreach ($steps as $index => $step) {
                $bindings = [$from, $to];
                $joins = '';
                $conditions = [];
                for ($position = 0; $position <= $index; $position++) {
                    $alias = 'step_'.$position;
                    if ($position > 0) {
                        $previous = 'step_'.($position - 1);
                        $hours = max(1, min(8760, (int) $funnel->conversion_window_hours));
                        $joins .= " JOIN analytics_events {$alias} ON {$alias}.visitor_id = step_0.visitor_id AND {$alias}.occurred_at >= {$previous}.occurred_at AND {$alias}.occurred_at <= step_0.occurred_at + INTERVAL '{$hours} hours'";
                    }
                    $definition = is_array($steps[$position] ?? null) ? $steps[$position] : [];
                    if (isset($definition['event'])) {
                        $conditions[] = "{$alias}.name = ?";
                        $bindings[] = $definition['event'];
                    }
                    if (isset($definition['path'])) {
                        $conditions[] = "{$alias}.path = ?";
                        $bindings[] = $definition['path'];
                    }
                }
                $where = $conditions === [] ? '' : ' AND '.implode(' AND ', $conditions);
                $row = $this->connection()->selectOne(
                    "SELECT COUNT(DISTINCT step_0.visitor_id) AS visitors FROM analytics_events step_0{$joins} WHERE step_0.occurred_at BETWEEN ? AND ?{$where}",
                    $bindings,
                );
                $result[] = [
                    'step' => $index + 1,
                    'name' => $step['name'] ?? $step['event'] ?? $step['path'] ?? 'Step',
                    'visitors' => (int) ($row->visitors ?? 0),
                ];
            }

            return ['id' => $funnel->id, 'name' => $funnel->name, 'steps' => $result];
        })->all();
    }

    public function realtime(): array
    {
        $from = now()->subMinutes(5);

        return [
            'activeVisitors' => $this->connection()->table('analytics_events')->where('occurred_at', '>=', $from)->distinct()->count('visitor_id'),
            'events' => $this->connection()->table('analytics_events')->where('occurred_at', '>=', $from)->count(),
            'paths' => $this->connection()->table('analytics_events')->where('occurred_at', '>=', $from)->whereNotNull('path')->selectRaw('path, COUNT(*) AS events')->groupBy('path')->orderByDesc('events')->limit(20)->get()->all(),
        ];
    }

    public function journeys(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return $this->connection()->select(<<<'SQL'
            SELECT session_id, MIN(visitor_id::text) AS visitor_id,
                   jsonb_agg(jsonb_build_object('name', name, 'path', path, 'at', occurred_at) ORDER BY occurred_at) AS journey
            FROM analytics_events
            WHERE occurred_at BETWEEN ? AND ? AND session_id IS NOT NULL
            GROUP BY session_id ORDER BY MAX(occurred_at) DESC LIMIT 100
            SQL, [$from, $to]);
    }

    public function replays(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return $this->connection()->table('analytics_replays AS replay')
            ->join('analytics_sessions AS session', 'session.id', '=', 'replay.session_id')
            ->whereBetween('replay.started_at', [$from, $to])
            ->select(['replay.id', 'replay.session_id', 'session.entry_path', 'session.exit_path', 'replay.events_count', 'replay.bytes', 'replay.started_at', 'replay.last_seen_at'])
            ->orderByDesc('replay.started_at')->limit(100)->get()->all();
    }

    public function heatmaps(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return $this->connection()->table('analytics_heatmaps AS heatmap')
            ->leftJoin('analytics_heatmap_points AS point', function ($join) use ($from, $to): void {
                $join->on('point.heatmap_key', '=', 'heatmap.key')->whereBetween('point.day', [$from->toDateString(), $to->toDateString()]);
            })
            ->leftJoin('analytics_heatmap_snapshots AS snapshot', 'snapshot.heatmap_key', '=', 'heatmap.key')
            ->selectRaw('heatmap.key, heatmap.name, heatmap.path, snapshot.device_type, snapshot.viewport_width, COALESCE(SUM(point.events), 0) AS events')
            ->groupBy('heatmap.key', 'heatmap.name', 'heatmap.path', 'snapshot.device_type', 'snapshot.viewport_width')
            ->orderByDesc('events')->get()->all();
    }

    public function goals(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return $this->connection()->table('analytics_goals AS goal')
            ->leftJoin('analytics_goal_conversions AS conversion', function ($join) use ($from, $to): void {
                $join->on('conversion.goal_id', '=', 'goal.id')->whereBetween('conversion.occurred_at', [$from, $to]);
            })
            ->selectRaw('goal.key, goal.name, COUNT(conversion.event_id) AS conversions, COUNT(DISTINCT conversion.visitor_id) AS visitors, COALESCE(SUM(conversion.value), 0) AS value')
            ->groupBy('goal.key', 'goal.name')->orderByDesc('conversions')->get()->all();
    }

    private function connection(): ConnectionInterface
    {
        return $this->database->connection(config('personal-analytics.connection'));
    }
}
