// SPDX-FileCopyrightText: 2025-2026 Zorin Projects S.P. <creator@localzet.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import type { eventWithTime } from '@rrweb/types';

export interface GoalDefinition {
  key: string;
  type: 'pageview' | 'custom';
  path?: string;
}

export interface HeatmapDefinition {
  key: string;
  path: string;
}

export interface AnalyticsOptions {
  endpoint: string;
  projectToken: string;
  ignoreDnt?: boolean;
  trackQueryParameters?: boolean;
  trackActions?: boolean;
  recordReplays?: boolean;
  trackSpa?: boolean;
  autocapture?: boolean;
  trackPerformance?: boolean;
  trackErrors?: boolean;
  trackNetwork?: boolean;
  trackDomMutations?: boolean;
  captureText?: boolean;
  replaySampleRate?: number;
  telemetryBatchSize?: number;
  telemetryFlushMs?: number;
  sensitiveSelectors?: string[];
  sessionTimeoutMs?: number;
  replayBatchSize?: number;
  replayFlushMs?: number;
  customParameters?: Record<string, string | number | boolean>;
  goals?: GoalDefinition[];
  heatmaps?: HeatmapDefinition[];
  onError?: (error: unknown) => void;
}

export interface EventEnvelope {
  schemaVersion: 1;
  projectToken: string;
  type: string;
  url: string;
  visitorId?: string;
  sessionId?: string;
  eventId?: string;
  telemetryId?: string;
  chunkId?: string;
  sequence?: number;
  count?: number;
  heatmapKey?: string;
  goalKey?: string;
  deviceType?: 'desktop' | 'tablet' | 'mobile';
  viewportWidth?: number;
  data?: Record<string, unknown> | eventWithTime[];
}

export interface AnalyticsEvent {
  name: string;
  category: 'interaction' | 'navigation' | 'engagement' | 'performance' | 'error' | 'network' | 'ecommerce' | 'custom';
  properties?: Record<string, unknown>;
  metrics?: Record<string, number>;
  occurredAt?: string;
}

export interface BatchEnvelope {
  schemaVersion: 2;
  projectToken: string;
  sentAt: string;
  events: EventEnvelope[];
}

export interface CollectorResponse {
  accepted: boolean;
  refresh?: 'visitor' | 'session';
}
