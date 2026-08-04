// SPDX-FileCopyrightText: 2025-2026 Zorin Projects S.P. <creator@localzet.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import type { AnalyticsEvent } from './types';

export function observePerformance(emit: (event: AnalyticsEvent) => void): () => void {
  const observers: PerformanceObserver[] = [];
  const observe = (type: string, handler: (entries: PerformanceEntry[]) => void): void => {
    try {
      const observer = new PerformanceObserver((list) => handler(list.getEntries()));
      observer.observe({ type, buffered: true });
      observers.push(observer);
    } catch { /* Unsupported performance entry type. */ }
  };

  observe('largest-contentful-paint', (entries) => {
    const last = entries.at(-1);
    if (last) emit({ name: 'web_vital', category: 'performance', properties: { metric: 'LCP' }, metrics: { value: last.startTime } });
  });
  let cls = 0;
  observe('layout-shift', (entries) => {
    for (const entry of entries as Array<PerformanceEntry & { value?: number; hadRecentInput?: boolean }>) {
      if (!entry.hadRecentInput) cls += entry.value ?? 0;
    }
    emit({ name: 'web_vital', category: 'performance', properties: { metric: 'CLS' }, metrics: { value: cls } });
  });
  observe('event', (entries) => {
    const inp = Math.max(0, ...entries.map((entry) => entry.duration));
    if (inp) emit({ name: 'web_vital', category: 'performance', properties: { metric: 'INP' }, metrics: { value: inp } });
  });
  observe('longtask', (entries) => {
    for (const entry of entries) emit({ name: 'long_task', category: 'performance', metrics: { duration: entry.duration } });
  });
  observe('resource', (entries) => {
    const resources = entries as PerformanceResourceTiming[];
    const summary = resources.reduce((acc, entry) => {
      acc.count += 1; acc.duration += entry.duration; acc.bytes += entry.transferSize || 0;
      return acc;
    }, { count: 0, duration: 0, bytes: 0 });
    if (summary.count) emit({ name: 'resource_summary', category: 'performance', metrics: summary });
  });

  const navigation = performance.getEntriesByType('navigation')[0] as PerformanceNavigationTiming | undefined;
  if (navigation) emit({
    name: 'navigation_timing', category: 'performance',
    metrics: {
      ttfb: navigation.responseStart - navigation.requestStart,
      domInteractive: navigation.domInteractive - navigation.startTime,
      domComplete: navigation.domComplete - navigation.startTime,
      load: navigation.loadEventEnd - navigation.startTime,
      transferSize: navigation.transferSize,
    },
  });

  return () => observers.forEach((observer) => observer.disconnect());
}
