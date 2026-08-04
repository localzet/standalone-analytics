-- SPDX-FileCopyrightText: 2025-2026 Zorin Projects S.P. <creator@localzet.com>
-- SPDX-License-Identifier: AGPL-3.0-or-later

CREATE TABLE pa_visitors (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid BINARY(16) NOT NULL,
    ip VARCHAR(45) NULL,
    custom_parameters JSON NULL,
    screen_width SMALLINT UNSIGNED NULL,
    screen_height SMALLINT UNSIGNED NULL,
    timezone VARCHAR(64) NULL,
    language VARCHAR(16) NULL,
    country_code CHAR(2) NULL,
    city_name VARCHAR(128) NULL,
    os_name VARCHAR(64) NULL,
    browser_name VARCHAR(64) NULL,
    device_type ENUM('desktop', 'tablet', 'mobile') NULL,
    theme ENUM('light', 'dark') NULL,
    user_agent VARCHAR(512) NULL,
    first_seen_at DATETIME(3) NOT NULL,
    last_seen_at DATETIME(3) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY pa_visitors_uuid_uq (uuid),
    KEY pa_visitors_last_seen_idx (last_seen_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE pa_sessions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid BINARY(16) NOT NULL,
    visitor_id BIGINT UNSIGNED NOT NULL,
    started_at DATETIME(3) NOT NULL,
    last_seen_at DATETIME(3) NOT NULL,
    event_count INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY pa_sessions_uuid_uq (uuid),
    KEY pa_sessions_visitor_idx (visitor_id),
    KEY pa_sessions_started_idx (started_at),
    CONSTRAINT pa_sessions_visitor_fk FOREIGN KEY (visitor_id) REFERENCES pa_visitors (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE pa_page_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid BINARY(16) NOT NULL,
    session_id BIGINT UNSIGNED NOT NULL,
    visitor_id BIGINT UNSIGNED NOT NULL,
    type ENUM('landing_page', 'pageview') NOT NULL,
    path VARCHAR(2048) NOT NULL,
    title VARCHAR(512) NULL,
    referrer_host VARCHAR(255) NULL,
    referrer_path VARCHAR(2048) NULL,
    utm_source VARCHAR(255) NULL,
    utm_medium VARCHAR(255) NULL,
    utm_campaign VARCHAR(255) NULL,
    utm_term VARCHAR(255) NULL,
    utm_content VARCHAR(255) NULL,
    viewport_width SMALLINT UNSIGNED NULL,
    viewport_height SMALLINT UNSIGNED NULL,
    bounced TINYINT(1) NULL,
    occurred_at DATETIME(3) NOT NULL,
    expires_at DATE NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY pa_page_events_uuid_uq (uuid),
    KEY pa_page_events_session_idx (session_id),
    KEY pa_page_events_visitor_idx (visitor_id),
    KEY pa_page_events_occurred_idx (occurred_at),
    KEY pa_page_events_path_idx (path(191)),
    KEY pa_page_events_utm_source_idx (utm_source),
    KEY pa_page_events_expires_idx (expires_at),
    CONSTRAINT pa_page_events_session_fk FOREIGN KEY (session_id) REFERENCES pa_sessions (id) ON DELETE CASCADE,
    CONSTRAINT pa_page_events_visitor_fk FOREIGN KEY (visitor_id) REFERENCES pa_visitors (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE pa_action_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id BIGINT UNSIGNED NOT NULL,
    session_id BIGINT UNSIGNED NOT NULL,
    visitor_id BIGINT UNSIGNED NOT NULL,
    heatmap_id BIGINT UNSIGNED NULL,
    type ENUM('click', 'scroll', 'form', 'resize') NOT NULL,
    data JSON NOT NULL,
    occurrence_count SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    occurred_at DATETIME(3) NOT NULL,
    expires_at DATE NULL,
    PRIMARY KEY (id),
    KEY pa_action_events_event_idx (event_id),
    KEY pa_action_events_session_idx (session_id),
    KEY pa_action_events_heatmap_idx (heatmap_id),
    KEY pa_action_events_expires_idx (expires_at),
    CONSTRAINT pa_action_events_event_fk FOREIGN KEY (event_id) REFERENCES pa_page_events (id) ON DELETE CASCADE,
    CONSTRAINT pa_action_events_session_fk FOREIGN KEY (session_id) REFERENCES pa_sessions (id) ON DELETE CASCADE,
    CONSTRAINT pa_action_events_visitor_fk FOREIGN KEY (visitor_id) REFERENCES pa_visitors (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE pa_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid BINARY(16) NOT NULL,
    event_id BIGINT UNSIGNED NOT NULL,
    session_id BIGINT UNSIGNED NOT NULL,
    visitor_id BIGINT UNSIGNED NOT NULL,
    category VARCHAR(32) NOT NULL,
    name VARCHAR(128) NOT NULL,
    path VARCHAR(2048) NOT NULL,
    properties JSON NOT NULL,
    metrics JSON NOT NULL,
    occurred_at DATETIME(3) NOT NULL,
    expires_at DATE NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY pa_events_uuid_uq (uuid),
    KEY pa_events_name_date_idx (name, occurred_at),
    KEY pa_events_category_date_idx (category, occurred_at),
    KEY pa_events_session_idx (session_id),
    KEY pa_events_visitor_idx (visitor_id),
    KEY pa_events_expires_idx (expires_at),
    CONSTRAINT pa_events_page_event_fk FOREIGN KEY (event_id) REFERENCES pa_page_events (id) ON DELETE CASCADE,
    CONSTRAINT pa_events_session_fk FOREIGN KEY (session_id) REFERENCES pa_sessions (id) ON DELETE CASCADE,
    CONSTRAINT pa_events_visitor_fk FOREIGN KEY (visitor_id) REFERENCES pa_visitors (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE pa_goals (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    goal_key VARCHAR(64) NOT NULL,
    name VARCHAR(128) NOT NULL,
    type ENUM('pageview', 'custom') NOT NULL,
    path VARCHAR(2048) NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME(3) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY pa_goals_key_uq (goal_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE pa_goal_conversions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    goal_id BIGINT UNSIGNED NOT NULL,
    event_id BIGINT UNSIGNED NOT NULL,
    session_id BIGINT UNSIGNED NOT NULL,
    visitor_id BIGINT UNSIGNED NOT NULL,
    occurred_at DATETIME(3) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY pa_goal_conversions_visitor_goal_uq (visitor_id, goal_id),
    KEY pa_goal_conversions_occurred_idx (occurred_at),
    CONSTRAINT pa_goal_conversions_goal_fk FOREIGN KEY (goal_id) REFERENCES pa_goals (id) ON DELETE CASCADE,
    CONSTRAINT pa_goal_conversions_event_fk FOREIGN KEY (event_id) REFERENCES pa_page_events (id) ON DELETE CASCADE,
    CONSTRAINT pa_goal_conversions_session_fk FOREIGN KEY (session_id) REFERENCES pa_sessions (id) ON DELETE CASCADE,
    CONSTRAINT pa_goal_conversions_visitor_fk FOREIGN KEY (visitor_id) REFERENCES pa_visitors (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE pa_heatmaps (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    heatmap_key VARCHAR(64) NOT NULL,
    name VARCHAR(128) NOT NULL,
    path VARCHAR(2048) NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME(3) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY pa_heatmaps_key_uq (heatmap_key),
    KEY pa_heatmaps_path_idx (path(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE pa_heatmap_snapshots (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    heatmap_id BIGINT UNSIGNED NOT NULL,
    device_type ENUM('desktop', 'tablet', 'mobile') NOT NULL,
    viewport_width SMALLINT UNSIGNED NOT NULL,
    payload LONGBLOB NOT NULL,
    created_at DATETIME(3) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY pa_heatmap_snapshots_device_uq (heatmap_id, device_type),
    CONSTRAINT pa_heatmap_snapshots_heatmap_fk FOREIGN KEY (heatmap_id) REFERENCES pa_heatmaps (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE pa_action_events
    ADD CONSTRAINT pa_action_events_heatmap_fk FOREIGN KEY (heatmap_id) REFERENCES pa_heatmaps (id) ON DELETE SET NULL;

CREATE TABLE pa_replay_sessions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    session_id BIGINT UNSIGNED NOT NULL,
    visitor_id BIGINT UNSIGNED NOT NULL,
    event_count INT UNSIGNED NOT NULL DEFAULT 0,
    byte_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
    started_at DATETIME(3) NOT NULL,
    last_seen_at DATETIME(3) NOT NULL,
    expires_at DATE NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY pa_replay_sessions_session_uq (session_id),
    KEY pa_replay_sessions_started_idx (started_at),
    KEY pa_replay_sessions_expires_idx (expires_at),
    CONSTRAINT pa_replay_sessions_session_fk FOREIGN KEY (session_id) REFERENCES pa_sessions (id) ON DELETE CASCADE,
    CONSTRAINT pa_replay_sessions_visitor_fk FOREIGN KEY (visitor_id) REFERENCES pa_visitors (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE pa_replay_chunks (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    replay_id BIGINT UNSIGNED NOT NULL,
    chunk_uuid BINARY(16) NOT NULL,
    sequence_no INT UNSIGNED NOT NULL,
    event_count SMALLINT UNSIGNED NOT NULL,
    payload LONGBLOB NOT NULL,
    created_at DATETIME(3) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY pa_replay_chunks_uuid_uq (chunk_uuid),
    KEY pa_replay_chunks_sequence_idx (replay_id, sequence_no),
    CONSTRAINT pa_replay_chunks_replay_fk FOREIGN KEY (replay_id) REFERENCES pa_replay_sessions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
