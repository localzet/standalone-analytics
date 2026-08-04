// SPDX-FileCopyrightText: 2025-2026 Zorin Projects S.P. <creator@localzet.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { PersonalAnalytics } from './tracker';
import type { AnalyticsOptions } from './types';

export { isDoNotTrackEnabled } from './privacy';
export { PersonalAnalytics } from './tracker';
export type { AnalyticsEvent, AnalyticsOptions, GoalDefinition, HeatmapDefinition } from './types';

export function createAnalytics(options: AnalyticsOptions): PersonalAnalytics {
  return new PersonalAnalytics(options);
}
