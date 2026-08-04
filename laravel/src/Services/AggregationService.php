<?php
/*
 * SPDX-FileCopyrightText: 2025-2026 Zorin Projects S.P. <creator@localzet.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Localzet\LaravelAnalytics\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;

final readonly class AggregationService
{
    public function __construct(private DatabaseManager $database)
    {
    }

    public function aggregate(CarbonImmutable $day): void
    {
        $connection = $this->database->connection(config('personal-analytics.connection'));
        $date = $day->toDateString();
        $dimensions = [
            'event' => 'name',
            'category' => 'category',
            'path' => "COALESCE(path, '(unknown)')",
            'country' => "COALESCE(context #>> '{request,geo,country}', '(unknown)')",
            'city' => "COALESCE(context #>> '{request,geo,city}', '(unknown)')",
            'browser' => "COALESCE(context #>> '{request,technology,browser}', '(unknown)')",
            'os' => "COALESCE(context #>> '{request,technology,os}', '(unknown)')",
            'device' => "COALESCE(properties #>> '{deviceType}', context #>> '{request,technology,device}', '(unknown)')",
            'source' => "COALESCE(properties #>> '{attribution,last,source}', '(direct)')",
            'medium' => "COALESCE(properties #>> '{attribution,last,medium}', '(none)')",
            'campaign' => "COALESCE(properties #>> '{attribution,last,campaign}', '(none)')",
            'term' => "COALESCE(properties #>> '{attribution,last,term}', '(none)')",
            'content' => "COALESCE(properties #>> '{attribution,last,content}', '(none)')",
            'referrer' => "COALESCE(properties #>> '{attribution,last,referrer}', '(direct)')",
            'landing_page' => "COALESCE(properties #>> '{attribution,first,landingPage}', '(unknown)')",
            'gclid' => "COALESCE(properties #>> '{attribution,last,clickIds,gclid}', '(none)')",
        ];
        $connection->transaction(function () use ($connection, $dimensions, $date): void {
            $connection->table('analytics_daily_rollups')->where('day', $date)->delete();
            foreach ($dimensions as $dimension => $expression) {
                $connection->statement(<<<SQL
                    INSERT INTO analytics_daily_rollups (
                        day, dimension, dimension_key, visitors, sessions, events, conversions,
                        revenue, metric_sum, metric_count, updated_at
                    )
                    SELECT DATE(occurred_at), ?, LEFT(({$expression})::text, 512),
                           COUNT(DISTINCT visitor_id), COUNT(DISTINCT session_id), COUNT(*),
                           COUNT(*) FILTER (WHERE name IN ('goal_conversion', 'purchase')),
                           COALESCE(SUM(CASE WHEN category = 'ecommerce' THEN COALESCE((metrics->>'revenue')::numeric, 0) ELSE 0 END), 0),
                           COALESCE(SUM(COALESCE((metrics->>'value')::numeric, 0)), 0),
                           COUNT(*) FILTER (WHERE metrics ? 'value'), now()
                    FROM analytics_events
                    WHERE occurred_at >= ?::date AND occurred_at < (?::date + INTERVAL '1 day')
                    GROUP BY DATE(occurred_at), {$expression}
                    ON CONFLICT (day, dimension, dimension_key) DO UPDATE SET
                        visitors = EXCLUDED.visitors, sessions = EXCLUDED.sessions, events = EXCLUDED.events,
                        conversions = EXCLUDED.conversions, revenue = EXCLUDED.revenue,
                        metric_sum = EXCLUDED.metric_sum, metric_count = EXCLUDED.metric_count, updated_at = now()
                    SQL, [$dimension, $date, $date]);
            }

            $connection->statement(<<<'SQL'
                INSERT INTO analytics_heatmap_points (
                    day, heatmap_key, path, device_type, point_type, x_bucket, y_bucket, value_bucket, events
                )
                SELECT DATE(occurred_at), properties #>> '{heatmap,key}', COALESCE(path, '/'),
                       COALESCE(properties->>'deviceType', context #>> '{request,technology,device}', 'desktop'),
                       properties #>> '{heatmap,type}',
                       (COALESCE((properties #>> '{heatmap,x}')::numeric, 0) / 10)::integer,
                       (COALESCE((properties #>> '{heatmap,y}')::numeric, 0) / 10)::integer,
                       (COALESCE((properties #>> '{heatmap,percentage}')::numeric, 0) / 5)::integer,
                       COUNT(*)
                FROM analytics_events
                WHERE occurred_at >= ?::date AND occurred_at < (?::date + INTERVAL '1 day')
                  AND properties #>> '{heatmap,key}' IS NOT NULL
                GROUP BY DATE(occurred_at), properties #>> '{heatmap,key}', path,
                         properties->>'deviceType', context #>> '{request,technology,device}', properties #>> '{heatmap,type}',
                         (COALESCE((properties #>> '{heatmap,x}')::numeric, 0) / 10)::integer,
                         (COALESCE((properties #>> '{heatmap,y}')::numeric, 0) / 10)::integer,
                         (COALESCE((properties #>> '{heatmap,percentage}')::numeric, 0) / 5)::integer
                ON CONFLICT (day, heatmap_key, device_type, point_type, x_bucket, y_bucket, value_bucket)
                DO UPDATE SET events = EXCLUDED.events
                SQL, [$date, $date]);

            $connection->statement(<<<'SQL'
                INSERT INTO analytics_goal_conversions (goal_id, visitor_id, session_id, event_id, value, occurred_at)
                SELECT g.id, e.visitor_id, e.session_id, e.id,
                       COALESCE((e.metrics->>'revenue')::numeric, (e.metrics->>'value')::numeric, 0), e.occurred_at
                FROM analytics_events e
                JOIN analytics_goals g ON g.enabled
                  AND (g.event_name IS NULL OR g.event_name = e.name)
                  AND (g.path IS NULL OR g.path = e.path)
                  AND e.properties @> g.conditions
                WHERE e.occurred_at >= ?::date AND e.occurred_at < (?::date + INTERVAL '1 day')
                ON CONFLICT DO NOTHING
                SQL, [$date, $date]);
        });
    }
}
