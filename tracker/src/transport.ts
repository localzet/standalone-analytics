// SPDX-FileCopyrightText: 2025-2026 Zorin Projects S.P. <creator@localzet.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import type { BatchEnvelope, CollectorResponse, EventEnvelope } from './types';

const MAX_KEEPALIVE_BYTES = 60_000;

interface EncodedBody {
  body: BodyInit;
  headers: Record<string, string>;
  size: number;
  originalSize: number;
  compressed: boolean;
}

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
  private flushing = false;

  public async send(event: Omit<EventEnvelope, 'schemaVersion' | 'projectToken' | 'url'>): Promise<CollectorResponse | null> {
    const envelope = this.envelope(event);
    let encoded: EncodedBody | undefined;
    try {
      encoded = await this.encodeBody(JSON.stringify(envelope));
      const response = await fetch(this.endpoint, {
        method: 'POST',
        headers: encoded.headers,
        body: encoded.body,
        credentials: 'omit',
        keepalive: encoded.size <= MAX_KEEPALIVE_BYTES,
      });
      if (!response.ok) {
        throw await this.responseError(response, encoded);
      }

      return (await response.json()) as CollectorResponse;
    } catch (error) {
      this.onError(this.transportError(error, encoded));
      return null;
    }
  }

  public beacon(event: Omit<EventEnvelope, 'schemaVersion' | 'projectToken' | 'url'>): boolean {
    try {
      const body = new Blob([JSON.stringify(this.envelope(event))], { type: 'text/plain;charset=UTF-8' });
      if (body.size > MAX_KEEPALIVE_BYTES) return false;
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
    if (this.flushing || this.queue.length === 0) return;
    this.flushing = true;
    const payload: BatchEnvelope = {
      schemaVersion: 2,
      projectToken: this.projectToken,
      sentAt: new Date().toISOString(),
      events: this.queue.splice(0),
    };
    clearTimeout(this.timer);
    this.timer = undefined;
    let encoded: EncodedBody | undefined;
    try {
      const body = JSON.stringify(payload);
      const bodySize = this.byteLength(body);
      if (useBeacon && bodySize <= MAX_KEEPALIVE_BYTES && navigator.sendBeacon(this.endpoint, new Blob([body], { type: 'text/plain;charset=UTF-8' }))) return;
      encoded = await this.encodeBody(body);
      const response = await fetch(this.endpoint, {
        method: 'POST',
        headers: encoded.headers,
        body: encoded.body,
        credentials: 'omit',
        keepalive: encoded.size <= MAX_KEEPALIVE_BYTES,
      });
      if (!response.ok) throw await this.responseError(response, encoded);
    } catch (error) {
      this.queue.unshift(...payload.events);
      if (this.queue.length > 1_000) this.queue.length = 1_000;
      this.timer = setTimeout(() => void this.flush(), Math.max(this.flushMs, 5_000));
      this.onError(this.transportError(error, encoded));
    } finally {
      this.flushing = false;
      if (this.queue.length > 0 && !this.timer) this.timer = setTimeout(() => void this.flush(), this.flushMs);
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

  private byteLength(value: string): number {
    return new Blob([value]).size;
  }

  private async encodeBody(value: string): Promise<EncodedBody> {
    const source = new Blob([value], { type: 'text/plain;charset=UTF-8' });
    if (typeof CompressionStream !== 'function' || source.size < MAX_KEEPALIVE_BYTES) {
      return { body: value, headers: { 'Content-Type': 'text/plain;charset=UTF-8' }, size: source.size, originalSize: source.size, compressed: false };
    }

    const stream = source.stream().pipeThrough(new CompressionStream('gzip'));
    const compressed = await new Response(stream).blob();
    const body = new Blob(['PA1GZIP\n', compressed], { type: 'text/plain;charset=UTF-8' });
    return {
      body,
      headers: { 'Content-Type': 'text/plain;charset=UTF-8' },
      size: body.size,
      originalSize: source.size,
      compressed: true,
    };
  }

  private async responseError(response: Response, encoded: EncodedBody): Promise<Error> {
    let message = '';
    try {
      const text = (await response.text()).slice(0, 2_048);
      const payload = JSON.parse(text) as { message?: unknown };
      message = typeof payload.message === 'string' ? payload.message : text;
    } catch {
      message = response.statusText;
    }
    const details = message ? `: ${message.slice(0, 512)}` : '';
    return new Error(`Collector returned HTTP ${response.status} for ${this.endpoint} (${this.payloadDescription(encoded)})${details}`);
  }

  private transportError(error: unknown, encoded?: EncodedBody): Error {
    if (error instanceof Error && error.message.startsWith('Collector returned HTTP')) return error;
    const reason = error instanceof Error ? error.message : String(error);
    const payload = encoded ? `, ${this.payloadDescription(encoded)}` : '';
    return new Error(`Analytics request to ${this.endpoint} failed${payload}: ${reason}`, { cause: error });
  }

  private payloadDescription(encoded: EncodedBody): string {
    return encoded.compressed
      ? `gzip ${encoded.size} bytes, decoded ${encoded.originalSize} bytes`
      : `${encoded.size} bytes`;
  }
}
