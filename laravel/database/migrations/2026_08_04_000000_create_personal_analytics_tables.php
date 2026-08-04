<?php

/*
 * SPDX-FileCopyrightText: 2025-2026 Zorin Projects S.P. <creator@localzet.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection(config('personal-analytics.connection'))->getDriverName() !== 'pgsql') {
            throw new RuntimeException('The Laravel analytics package requires PostgreSQL.');
        }

        DB::connection(config('personal-analytics.connection'))->unprepared(<<<'SQL'
            CREATE TABLE analytics_visitors (
                id uuid PRIMARY KEY,
                identified_user_id varchar(256),
                traits jsonb NOT NULL DEFAULT '{}'::jsonb,
                acquisition jsonb NOT NULL DEFAULT '{}'::jsonb,
                technology jsonb NOT NULL DEFAULT '{}'::jsonb,
                geography jsonb NOT NULL DEFAULT '{}'::jsonb,
                first_seen_at timestamptz NOT NULL,
                last_seen_at timestamptz NOT NULL,
                sessions_count bigint NOT NULL DEFAULT 0,
                events_count bigint NOT NULL DEFAULT 0
            );
            CREATE INDEX analytics_visitors_identified_idx ON analytics_visitors (identified_user_id) WHERE identified_user_id IS NOT NULL;
            CREATE INDEX analytics_visitors_last_seen_idx ON analytics_visitors (last_seen_at DESC);
            CREATE INDEX analytics_visitors_traits_gin ON analytics_visitors USING gin (traits jsonb_path_ops);

            CREATE TABLE analytics_sessions (
                id uuid PRIMARY KEY,
                visitor_id uuid NOT NULL REFERENCES analytics_visitors(id) ON DELETE CASCADE,
                started_at timestamptz NOT NULL,
                last_seen_at timestamptz NOT NULL,
                entry_path text,
                exit_path text,
                attribution jsonb NOT NULL DEFAULT '{}'::jsonb,
                pageviews integer NOT NULL DEFAULT 0,
                events_count bigint NOT NULL DEFAULT 0,
                engagement_ms bigint NOT NULL DEFAULT 0,
                is_bounced boolean NOT NULL DEFAULT true
            );
            CREATE INDEX analytics_sessions_visitor_idx ON analytics_sessions (visitor_id, started_at DESC);
            CREATE INDEX analytics_sessions_started_brin ON analytics_sessions USING brin (started_at);

            CREATE TABLE analytics_events (
                id uuid NOT NULL,
                visitor_id uuid NOT NULL,
                session_id uuid,
                page_event_id uuid,
                name varchar(128) NOT NULL,
                category varchar(32) NOT NULL,
                path text,
                title varchar(512),
                properties jsonb NOT NULL DEFAULT '{}'::jsonb,
                metrics jsonb NOT NULL DEFAULT '{}'::jsonb,
                context jsonb NOT NULL DEFAULT '{}'::jsonb,
                occurred_at timestamptz NOT NULL,
                received_at timestamptz NOT NULL DEFAULT now(),
                PRIMARY KEY (id, occurred_at)
            ) PARTITION BY RANGE (occurred_at);
            DO $$
            DECLARE
                month_start date;
                partition_name text;
            BEGIN
                FOR offset_month IN 0..3 LOOP
                    month_start := (date_trunc('month', current_date) + make_interval(months => offset_month))::date;
                    partition_name := 'analytics_events_' || to_char(month_start, 'YYYYMM');
                    EXECUTE format(
                        'CREATE TABLE %I PARTITION OF analytics_events FOR VALUES FROM (%L) TO (%L)',
                        partition_name, month_start, month_start + interval '1 month'
                    );
                END LOOP;
            END $$;
            CREATE TABLE analytics_events_default PARTITION OF analytics_events DEFAULT;
            CREATE INDEX analytics_events_occurred_brin ON analytics_events USING brin (occurred_at);
            CREATE INDEX analytics_events_name_time_idx ON analytics_events (name, occurred_at DESC);
            CREATE INDEX analytics_events_category_time_idx ON analytics_events (category, occurred_at DESC);
            CREATE INDEX analytics_events_session_time_idx ON analytics_events (session_id, occurred_at) WHERE session_id IS NOT NULL;
            CREATE INDEX analytics_events_visitor_time_idx ON analytics_events (visitor_id, occurred_at DESC);
            CREATE INDEX analytics_events_path_time_idx ON analytics_events (path, occurred_at DESC) WHERE path IS NOT NULL;
            CREATE INDEX analytics_events_properties_gin ON analytics_events USING gin (properties jsonb_path_ops);

            CREATE TABLE analytics_daily_rollups (
                day date NOT NULL,
                dimension varchar(64) NOT NULL,
                dimension_key varchar(512) NOT NULL,
                visitors bigint NOT NULL DEFAULT 0,
                sessions bigint NOT NULL DEFAULT 0,
                events bigint NOT NULL DEFAULT 0,
                conversions bigint NOT NULL DEFAULT 0,
                revenue numeric(20,4) NOT NULL DEFAULT 0,
                metric_sum numeric(30,6) NOT NULL DEFAULT 0,
                metric_count bigint NOT NULL DEFAULT 0,
                updated_at timestamptz NOT NULL DEFAULT now(),
                PRIMARY KEY (day, dimension, dimension_key)
            );
            CREATE INDEX analytics_rollups_dimension_day_idx ON analytics_daily_rollups (dimension, day DESC);

            CREATE TABLE analytics_replays (
                id uuid PRIMARY KEY,
                session_id uuid NOT NULL UNIQUE REFERENCES analytics_sessions(id) ON DELETE CASCADE,
                visitor_id uuid NOT NULL REFERENCES analytics_visitors(id) ON DELETE CASCADE,
                events_count bigint NOT NULL DEFAULT 0,
                bytes bigint NOT NULL DEFAULT 0,
                started_at timestamptz NOT NULL,
                last_seen_at timestamptz NOT NULL,
                expires_at timestamptz NOT NULL
            );
            CREATE INDEX analytics_replays_started_idx ON analytics_replays (started_at DESC);
            CREATE INDEX analytics_replays_expires_idx ON analytics_replays (expires_at);

            CREATE TABLE analytics_replay_chunks (
                id uuid PRIMARY KEY,
                replay_id uuid NOT NULL REFERENCES analytics_replays(id) ON DELETE CASCADE,
                sequence_no integer NOT NULL,
                event_count integer NOT NULL,
                storage_path text NOT NULL,
                bytes bigint NOT NULL,
                created_at timestamptz NOT NULL,
                UNIQUE (replay_id, sequence_no, id)
            );
            CREATE INDEX analytics_replay_chunks_replay_idx ON analytics_replay_chunks (replay_id, created_at, sequence_no);

            CREATE TABLE analytics_heatmaps (
                key varchar(64) PRIMARY KEY,
                name varchar(128) NOT NULL,
                path text NOT NULL,
                enabled boolean NOT NULL DEFAULT true,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now()
            );
            CREATE TABLE analytics_heatmap_snapshots (
                id uuid PRIMARY KEY,
                heatmap_key varchar(64) NOT NULL REFERENCES analytics_heatmaps(key) ON DELETE CASCADE,
                device_type varchar(16) NOT NULL,
                viewport_width integer NOT NULL,
                storage_path text NOT NULL,
                created_at timestamptz NOT NULL,
                UNIQUE (heatmap_key, device_type)
            );
            CREATE TABLE analytics_heatmap_points (
                day date NOT NULL,
                heatmap_key varchar(64) NOT NULL,
                path text NOT NULL,
                device_type varchar(16) NOT NULL,
                point_type varchar(16) NOT NULL,
                x_bucket integer NOT NULL DEFAULT 0,
                y_bucket integer NOT NULL DEFAULT 0,
                value_bucket integer NOT NULL DEFAULT 0,
                events bigint NOT NULL DEFAULT 0,
                PRIMARY KEY (day, heatmap_key, device_type, point_type, x_bucket, y_bucket, value_bucket)
            );

            CREATE TABLE analytics_goals (
                id uuid PRIMARY KEY,
                key varchar(64) NOT NULL UNIQUE,
                name varchar(128) NOT NULL,
                event_name varchar(128),
                path text,
                conditions jsonb NOT NULL DEFAULT '{}'::jsonb,
                enabled boolean NOT NULL DEFAULT true,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now()
            );
            CREATE TABLE analytics_goal_conversions (
                goal_id uuid NOT NULL REFERENCES analytics_goals(id) ON DELETE CASCADE,
                visitor_id uuid NOT NULL,
                session_id uuid,
                event_id uuid NOT NULL,
                value numeric(20,4) NOT NULL DEFAULT 0,
                occurred_at timestamptz NOT NULL,
                PRIMARY KEY (goal_id, visitor_id, event_id)
            );
            CREATE INDEX analytics_goal_conversions_time_idx ON analytics_goal_conversions (occurred_at DESC);

            CREATE TABLE analytics_funnels (
                id uuid PRIMARY KEY,
                name varchar(128) NOT NULL,
                steps jsonb NOT NULL,
                conversion_window_hours integer NOT NULL DEFAULT 168,
                enabled boolean NOT NULL DEFAULT true,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now()
            );
            SQL);
    }

    public function down(): void
    {
        DB::connection(config('personal-analytics.connection'))->unprepared(<<<'SQL'
            DROP TABLE IF EXISTS analytics_funnels CASCADE;
            DROP TABLE IF EXISTS analytics_goal_conversions CASCADE;
            DROP TABLE IF EXISTS analytics_goals CASCADE;
            DROP TABLE IF EXISTS analytics_heatmap_points CASCADE;
            DROP TABLE IF EXISTS analytics_heatmap_snapshots CASCADE;
            DROP TABLE IF EXISTS analytics_heatmaps CASCADE;
            DROP TABLE IF EXISTS analytics_replay_chunks CASCADE;
            DROP TABLE IF EXISTS analytics_replays CASCADE;
            DROP TABLE IF EXISTS analytics_daily_rollups CASCADE;
            DROP TABLE IF EXISTS analytics_events CASCADE;
            DROP TABLE IF EXISTS analytics_sessions CASCADE;
            DROP TABLE IF EXISTS analytics_visitors CASCADE;
            SQL);
    }
};
