// SPDX-FileCopyrightText: 2025-2026 Zorin Projects S.P. <creator@localzet.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import type { BatchEnvelope, CollectorResponse, EventEnvelope } from './types';

export class Transport {
  public constructor(
    private readonly endpoint: string,
    private readonly projectToken: string,
    private readonly onError: (error: unknown) => void,
    private readonly batchSize = 20,
    private readonly flushMs = 2_000,
  ) {}

  private queue: EventEnvelope[] = [];
  private timer: ReturnType<typeof setTimeout> | undefined;

  public async send(event: Omit<EventEnvelope, 'schemaVersion' | 'projectToken' | 'url'>): Promise<CollectorResponse | null> {
    const envelope = this.envelope(event);
    try {
      const response = await fetch(this.endpoint, {
        method: 'POST',
        headers: { 'Content-Type': 'text/plain;charset=UTF-8' },
        body: JSON.stringify(envelope),
        credentials: 'omit',
        keepalive: true,
      });
      if (!response.ok) {
        throw new Error(`Collector returned HTTP ${response.status}.`);
      }

      return (await response.json()) as CollectorResponse;
    } catch (error) {
      this.onError(error);
      return null;
    }
  }

  public beacon(event: Omit<EventEnvelope, 'schemaVersion' | 'projectToken' | 'url'>): boolean {
    try {
      const body = new Blob([JSON.stringify(this.envelope(event))], { type: 'text/plain;charset=UTF-8' });
      return navigator.sendBeacon(this.endpoint, body);
    } catch (error) {
      this.onError(error);
      return false;
    }
  }

  public enqueue(event: Omit<EventEnvelope, 'schemaVersion' | 'projectToken' | 'url'>): void {
    this.queue.push(this.envelope(event));
    if (this.queue.length >= this.batchSize) {
      void this.flush();
      return;
    }
    clearTimeout(this.timer);
    this.timer = setTimeout(() => void this.flush(), this.flushMs);
  }

  public async flush(useBeacon = false): Promise<void> {
    if (this.queue.length === 0) return;
    const payload: BatchEnvelope = {
      schemaVersion: 2,
      projectToken: this.projectToken,
      sentAt: new Date().toISOString(),
      events: this.queue.splice(0),
    };
    clearTimeout(this.timer);
    this.timer = undefined;
    try {
      const body = JSON.stringify(payload);
      if (useBeacon && navigator.sendBeacon(this.endpoint, new Blob([body], { type: 'text/plain;charset=UTF-8' }))) return;
      const response = await fetch(this.endpoint, {
        method: 'POST',
        headers: { 'Content-Type': 'text/plain;charset=UTF-8' },
        body,
        credentials: 'omit',
        keepalive: true,
      });
      if (!response.ok) throw new Error(`Collector returned HTTP ${response.status}.`);
    } catch (error) {
      this.queue.unshift(...payload.events);
      if (this.queue.length > 1_000) this.queue.length = 1_000;
      if (!useBeacon) this.timer = setTimeout(() => void this.flush(), Math.max(this.flushMs, 5_000));
      this.onError(error);
    }
  }

  private envelope(event: Omit<EventEnvelope, 'schemaVersion' | 'projectToken' | 'url'>): EventEnvelope {
    return {
      ...event,
      schemaVersion: 1,
      projectToken: this.projectToken,
      url: window.location.href,
    };
  }
}
