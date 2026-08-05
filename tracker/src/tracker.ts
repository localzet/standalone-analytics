// SPDX-FileCopyrightText: 2025-2026 Zorin Projects S.P. <creator@localzet.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { record } from '@rrweb/record';
import type { eventWithTime } from '@rrweb/types';
import { installAutocapture } from './autocapture';
import { AttributionTracker } from './attribution';
import { observePerformance } from './performance';
import { isDoNotTrackEnabled } from './privacy';
import { sanitizeProperties, safeUrl } from './sanitizer';
import { SafeStorage } from './storage';
import { Transport } from './transport';
import type { AnalyticsEvent, AnalyticsOptions, CollectorResponse, EventEnvelope, HeatmapDefinition } from './types';

const DEFAULT_SESSION_TIMEOUT = 30 * 60 * 1000;
const MAX_REPLAY_RETRY_MS = 60_000;

type PendingEvent = Omit<EventEnvelope, 'schemaVersion' | 'projectToken' | 'url'>;

export class PersonalAnalytics {
  private readonly options: Required<Pick<AnalyticsOptions,
    'ignoreDnt' | 'trackQueryParameters' | 'trackActions' | 'recordReplays' | 'trackSpa' |
    'autocapture' | 'trackPerformance' | 'trackErrors' | 'trackNetwork' | 'trackDomMutations' |
    'captureText' | 'replaySampleRate' | 'telemetryBatchSize' | 'telemetryFlushMs' |
    'sessionTimeoutMs' | 'replayBatchSize' | 'replayFlushMs'>> & AnalyticsOptions;
  private readonly storage = new SafeStorage();
  private readonly transport: Transport;
  private visitorId = '';
  private sessionId = '';
  private eventId = '';
  private replaySequence = 0;
  private replayEvents: eventWithTime[] = [];
  private replayChunks: PendingEvent[] = [];
  private replayTimer: ReturnType<typeof setTimeout> | undefined;
  private replayRetryTimer: ReturnType<typeof setTimeout> | undefined;
  private replaySending = false;
  private replayRetryAttempt = 0;
  private stopRecorder: (() => void) | undefined;
  private cleanups: Array<() => void> = [];
  private started = false;
  private readonly attribution: AttributionTracker;
  private identifiedUserId: string | null = null;

  public constructor(options: AnalyticsOptions) {
    this.options = {
      ...options,
      ignoreDnt: options.ignoreDnt ?? false,
      trackQueryParameters: options.trackQueryParameters ?? false,
      trackActions: options.trackActions ?? true,
      recordReplays: options.recordReplays ?? false,
      trackSpa: options.trackSpa ?? true,
      autocapture: options.autocapture ?? true,
      trackPerformance: options.trackPerformance ?? true,
      trackErrors: options.trackErrors ?? true,
      trackNetwork: options.trackNetwork ?? true,
      trackDomMutations: options.trackDomMutations ?? true,
      captureText: options.captureText ?? false,
      replaySampleRate: options.replaySampleRate ?? 1,
      telemetryBatchSize: options.telemetryBatchSize ?? 20,
      telemetryFlushMs: options.telemetryFlushMs ?? 2_000,
      sessionTimeoutMs: options.sessionTimeoutMs ?? DEFAULT_SESSION_TIMEOUT,
      replayBatchSize: options.replayBatchSize ?? 30,
      replayFlushMs: options.replayFlushMs ?? 3_000,
    };
    this.validateOptions();
    this.transport = new Transport(
      options.endpoint, options.projectToken, options.onError ?? (() => undefined),
      this.options.telemetryBatchSize, this.options.telemetryFlushMs,
    );
    this.attribution = new AttributionTracker(this.storage);
  }

  public async start(): Promise<boolean> {
    if (this.started) return true;
    if (this.storage.get('optout') === 'true') return false;
    if (isDoNotTrackEnabled() && !this.options.ignoreDnt) return false;

    this.started = true;
    await this.ensureVisitor();
    const landing = this.ensureSession();
    await this.page(landing ? 'landing_page' : 'pageview');
    if (this.options.autocapture) this.installAdvancedTracking();
    else if (this.options.trackActions) this.installActionTracking();
    if (this.options.trackSpa) this.installSpaTracking();
    if (this.options.trackPerformance) this.cleanups.push(observePerformance((event) => this.emitTelemetry(event)));
    if (this.options.trackErrors) this.installErrorTracking();
    this.installEngagementTracking();
    this.startRecorder();
    this.installFlushHandlers();

    return true;
  }

  public stop(): void {
    this.flushReplay(true);
    void this.transport.flush(true);
    this.stopRecorder?.();
    this.stopRecorder = undefined;
    for (const cleanup of this.cleanups.splice(0)) cleanup();
    this.started = false;
  }

  public optOut(): void {
    this.stop();
    this.storage.set('optout', 'true');
    this.storage.clearIdentity();
  }

  public optIn(): void {
    this.storage.remove('optout');
  }

  public async goal(key: string): Promise<boolean> {
    if (!this.started || !/^[a-zA-Z0-9._-]{1,64}$/.test(key)) return false;
    if (this.storage.get(`goal_${key}`) === 'true') return true;
    const response = await this.transport.send({
      type: 'goal_conversion',
      visitorId: this.visitorId,
      sessionId: this.sessionId,
      eventId: this.eventId,
      goalKey: key,
    });
    if (response?.accepted) this.storage.set(`goal_${key}`, 'true');

    return response?.accepted ?? false;
  }

  public track(name: string, properties: Record<string, unknown> = {}, metrics: Record<string, number> = {}): void {
    if (!this.started || !/^[a-zA-Z0-9][a-zA-Z0-9._:-]{0,127}$/.test(name)) return;
    this.emitTelemetry({ name, category: 'custom', properties, metrics });
  }

  public identify(userId: string, traits: Record<string, unknown> = {}): void {
    if (!this.started || userId.trim() === '') return;
    this.identifiedUserId = userId.slice(0, 256);
    this.emitTelemetry({ name: 'identify', category: 'custom', properties: { userId: this.identifiedUserId, traits } });
  }

  public ecommerce(
    action: 'product_view' | 'search' | 'add_to_cart' | 'remove_from_cart' | 'view_cart' | 'begin_checkout' | 'add_payment_info' | 'purchase' | 'refund',
    data: Record<string, unknown>,
  ): void {
    if (!this.started) return;
    const revenue = typeof data.revenue === 'number' ? data.revenue : typeof data.value === 'number' ? data.value : undefined;
    this.emitTelemetry({
      name: action,
      category: 'ecommerce',
      properties: data,
      ...(revenue === undefined ? {} : { metrics: { revenue } }),
    });
  }

  private async ensureVisitor(): Promise<void> {
    this.visitorId = this.storage.get('visitor_id') ?? crypto.randomUUID();
    this.storage.set('visitor_id', this.visitorId);
    const response = await this.transport.send({
      type: 'visitor',
      visitorId: this.visitorId,
      data: {
        screen: { width: window.screen.width, height: window.screen.height },
        timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,
        theme: window.matchMedia?.('(prefers-color-scheme: dark)').matches ? 'dark' : 'light',
        deviceType: this.deviceType(),
        customParameters: this.options.customParameters ?? {},
        attribution: this.attribution.capture(),
      },
    });
    if (response?.refresh === 'visitor') {
      this.storage.remove('visitor_id');
      this.visitorId = crypto.randomUUID();
      this.storage.set('visitor_id', this.visitorId);
    }
  }

  private ensureSession(): boolean {
    const lastSeen = Number(this.storage.get('session_seen_at') ?? 0);
    const expired = !lastSeen || Date.now() - lastSeen > this.options.sessionTimeoutMs;
    this.sessionId = expired ? crypto.randomUUID() : (this.storage.get('session_id') ?? crypto.randomUUID());
    this.storage.set('session_id', this.sessionId);
    this.touchSession();

    return expired;
  }

  private async page(type: 'landing_page' | 'pageview', retry = true): Promise<void> {
    this.eventId = crypto.randomUUID();
    const url = new URL(window.location.href);
    const path = this.options.trackQueryParameters ? `${url.pathname}${url.search}` : url.pathname;
    const response = await this.transport.send({
      type,
      visitorId: this.visitorId,
      sessionId: this.sessionId,
      eventId: this.eventId,
      data: {
        path: path || '/',
        title: document.title,
        referrer: this.externalReferrer(),
        utm: this.utm(url.searchParams),
        viewport: this.viewport(),
        attribution: this.attribution.capture(),
      },
    });
    if (response?.refresh && retry) {
      await this.handleRefresh(response);
      await this.page(type, false);
      return;
    }
    this.touchSession();
    await this.matchPageGoals(url.pathname || '/');
    if (!this.stopRecorder) this.startRecorder();
  }

  private installActionTracking(): void {
    let clickTimer: ReturnType<typeof setTimeout> | undefined;
    let scrollTimer: ReturnType<typeof setTimeout> | undefined;
    let resizeTimer: ReturnType<typeof setTimeout> | undefined;

    const onClick = (event: MouseEvent): void => {
      if (!event.isTrusted || !(event.target instanceof Element)) return;
      const target = event.target;
      const heatmap = this.activeHeatmap();
      clearTimeout(clickTimer);
      clickTimer = setTimeout(() => {
        this.action('click', {
          x: Math.round(event.pageX),
          y: Math.round(event.pageY),
          viewport: this.viewport(),
          element: target.tagName.toLowerCase(),
          selector: this.selector(target),
          text: (target.textContent ?? '').trim().slice(0, 64),
        }, heatmap?.key);
      }, target.closest('a[href]') ? 100 : 300);
    };
    const onScroll = (): void => {
      clearTimeout(scrollTimer);
      scrollTimer = setTimeout(() => {
        const height = Math.max(document.documentElement.scrollHeight - window.innerHeight, 1);
        this.action('scroll', {
          percentage: Math.max(0, Math.min(100, Math.round(window.scrollY / height * 100))),
          viewport: this.viewport(),
        }, this.activeHeatmap()?.key);
      }, 400);
    };
    const onSubmit = (event: SubmitEvent): void => {
      const form = event.target instanceof HTMLFormElement ? event.target : null;
      this.action('form', { selector: form ? this.selector(form) : 'form' });
    };
    const onResize = (): void => {
      clearTimeout(resizeTimer);
      resizeTimer = setTimeout(() => this.action('resize', { viewport: this.viewport() }), 400);
    };

    document.addEventListener('click', onClick, { capture: true });
    document.addEventListener('scroll', onScroll, { passive: true });
    document.addEventListener('submit', onSubmit, { capture: true });
    window.addEventListener('resize', onResize, { passive: true });
    this.cleanups.push(() => {
      document.removeEventListener('click', onClick, { capture: true });
      document.removeEventListener('scroll', onScroll);
      document.removeEventListener('submit', onSubmit, { capture: true });
      window.removeEventListener('resize', onResize);
      clearTimeout(clickTimer);
      clearTimeout(scrollTimer);
      clearTimeout(resizeTimer);
    });
  }

  private installAdvancedTracking(): void {
    this.cleanups.push(installAutocapture((event, heatmap) => {
      this.emitTelemetry({
        ...event,
        properties: { ...(event.properties ?? {}), ...(heatmap ? { heatmap: { ...heatmap, key: this.activeHeatmap()?.key } } : {}) },
      });
    }, {
      endpoint: this.options.endpoint,
      trackNetwork: this.options.trackNetwork,
      trackDomMutations: this.options.trackDomMutations,
      captureText: this.options.captureText,
      sensitiveSelectors: this.options.sensitiveSelectors ?? [],
      selector: (element) => this.selector(element),
    }));
  }

  private installErrorTracking(): void {
    const onError = (event: ErrorEvent): void => {
      this.emitTelemetry({ name: 'javascript_error', category: 'error', properties: {
        message: event.message.slice(0, 512), source: safeUrl(event.filename), line: event.lineno,
        column: event.colno, stack: event.error instanceof Error ? event.error.stack?.slice(0, 4096) : undefined,
      } });
    };
    const onRejection = (event: PromiseRejectionEvent): void => {
      const reason = event.reason;
      this.emitTelemetry({ name: 'unhandled_rejection', category: 'error', properties: {
        message: reason instanceof Error ? reason.message : String(reason),
        stack: reason instanceof Error ? reason.stack?.slice(0, 4096) : undefined,
      } });
    };
    const onResourceError = (event: Event): void => {
      const target = event.target;
      if (!(target instanceof HTMLScriptElement || target instanceof HTMLImageElement || target instanceof HTMLLinkElement)) return;
      this.emitTelemetry({ name: 'resource_error', category: 'error', properties: {
        tag: target.tagName.toLowerCase(), url: safeUrl(target instanceof HTMLLinkElement ? target.href : target.src),
      } });
    };
    window.addEventListener('error', onError);
    window.addEventListener('error', onResourceError, true);
    window.addEventListener('unhandledrejection', onRejection);
    this.cleanups.push(() => {
      window.removeEventListener('error', onError);
      window.removeEventListener('error', onResourceError, true);
      window.removeEventListener('unhandledrejection', onRejection);
    });
  }

  private installEngagementTracking(): void {
    const started = performance.now();
    let visibleStarted = document.visibilityState === 'visible' ? performance.now() : 0;
    let visibleMs = 0;
    const report = (): void => {
      if (visibleStarted) { visibleMs += performance.now() - visibleStarted; visibleStarted = performance.now(); }
      this.emitTelemetry({ name: 'engagement', category: 'engagement', metrics: {
        elapsed: performance.now() - started, visible: visibleMs,
      } });
    };
    const onVisibility = (): void => {
      if (document.visibilityState === 'hidden') {
        if (visibleStarted) visibleMs += performance.now() - visibleStarted;
        visibleStarted = 0; report();
      } else visibleStarted = performance.now();
    };
    const interval = setInterval(report, 15_000);
    document.addEventListener('visibilitychange', onVisibility);
    this.cleanups.push(() => { clearInterval(interval); document.removeEventListener('visibilitychange', onVisibility); });
  }

  private installSpaTracking(): void {
    let previous = window.location.href;
    const navigate = (): void => {
      if (previous === window.location.href) return;
      previous = window.location.href;
      void this.page('pageview');
    };
    const pushState = history.pushState.bind(history);
    const replaceState = history.replaceState.bind(history);
    history.pushState = (...args): void => { pushState(...args); queueMicrotask(navigate); };
    history.replaceState = (...args): void => { replaceState(...args); queueMicrotask(navigate); };
    window.addEventListener('popstate', navigate);
    this.cleanups.push(() => {
      history.pushState = pushState;
      history.replaceState = replaceState;
      window.removeEventListener('popstate', navigate);
    });
  }

  private startRecorder(): void {
    if (this.stopRecorder) return;
    const heatmap = this.activeHeatmap();
    if (!this.options.recordReplays && !heatmap) return;
    const recordReplay = this.options.recordReplays && this.isReplaySampled();
    if (!recordReplay && !heatmap) return;
    const snapshot: eventWithTime[] = [];
    let snapshotSent = false;
    const stop = record({
      maskAllInputs: true,
      blockClass: 'pa-block',
      inlineStylesheet: true,
      slimDOMOptions: true,
      emit: (event: eventWithTime): void => {
        if (recordReplay) {
          this.replayEvents.push(event);
          if (this.replayEvents.length >= this.options.replayBatchSize) this.flushReplay();
          else this.scheduleReplayFlush();
        }
        if (heatmap && !snapshotSent) {
          snapshot.push(event);
          if (snapshot.length >= 2 && snapshot[0]?.type === 4 && snapshot[1]?.type === 2) {
            snapshotSent = true;
            void this.transport.send({
              type: 'heatmap_snapshot',
              heatmapKey: heatmap.key,
              deviceType: this.deviceType(),
              viewportWidth: window.innerWidth,
              data: snapshot,
            });
          }
        }
      },
    });
    if (typeof stop === 'function') this.stopRecorder = stop;
  }

  private action(type: 'click' | 'scroll' | 'form' | 'resize', data: Record<string, unknown>, heatmapKey?: string): void {
    this.touchSession();
    this.transport.beacon({
      type,
      visitorId: this.visitorId,
      sessionId: this.sessionId,
      eventId: this.eventId,
      data,
      ...(heatmapKey ? { heatmapKey } : {}),
    });
  }

  private flushReplay(beacon = false): void {
    if (this.replayEvents.length === 0) return;
    const data = this.replayEvents.splice(0);
    const event = {
      type: 'replay',
      visitorId: this.visitorId,
      sessionId: this.sessionId,
      chunkId: crypto.randomUUID(),
      sequence: this.replaySequence++,
      data,
    } as const;
    clearTimeout(this.replayTimer);
    this.replayTimer = undefined;
    if (beacon && this.transport.beacon(event)) return;
    this.replayChunks.push(event);
    void this.sendReplayChunks();
  }

  private async sendReplayChunks(): Promise<void> {
    if (this.replaySending || this.replayRetryTimer || this.replayChunks.length === 0) return;
    this.replaySending = true;
    const event = this.replayChunks[0];
    try {
      const response = event ? await this.transport.send(event) : null;
      if (response?.accepted) {
        this.replayChunks.shift();
        this.replayRetryAttempt = 0;
      } else {
        this.replayRetryAttempt += 1;
        const retryMs = Math.min(MAX_REPLAY_RETRY_MS, 5_000 * 2 ** Math.min(this.replayRetryAttempt - 1, 4));
        this.replayRetryTimer = setTimeout(() => {
          this.replayRetryTimer = undefined;
          void this.sendReplayChunks();
        }, retryMs);
      }
    } finally {
      this.replaySending = false;
      if (this.replayChunks.length > 0 && !this.replayRetryTimer) void this.sendReplayChunks();
    }
  }

  private scheduleReplayFlush(): void {
    clearTimeout(this.replayTimer);
    this.replayTimer = setTimeout(() => this.flushReplay(), this.options.replayFlushMs);
  }

  private installFlushHandlers(): void {
    const flush = (): void => { this.flushReplay(true); void this.transport.flush(true); };
    window.addEventListener('pagehide', flush, { capture: true });
    document.addEventListener('visibilitychange', flush);
    this.cleanups.push(() => {
      window.removeEventListener('pagehide', flush, { capture: true });
      document.removeEventListener('visibilitychange', flush);
    });
  }

  private async handleRefresh(response: CollectorResponse | null): Promise<void> {
    if (response?.refresh === 'visitor') {
      this.storage.clearIdentity();
      await this.ensureVisitor();
      this.ensureSession();
    } else if (response?.refresh === 'session') {
      this.storage.remove('session_id');
      this.storage.remove('session_seen_at');
      this.ensureSession();
    }
  }

  private async matchPageGoals(path: string): Promise<void> {
    for (const goal of this.options.goals ?? []) {
      if (goal.type === 'pageview' && goal.path === path) await this.goal(goal.key);
    }
  }

  private activeHeatmap(): HeatmapDefinition | undefined {
    return (this.options.heatmaps ?? []).find((heatmap) => heatmap.path === (window.location.pathname || '/'));
  }

  private externalReferrer(): string | null {
    if (!document.referrer) return null;
    try {
      return new URL(document.referrer).host === window.location.host ? null : document.referrer;
    } catch {
      return null;
    }
  }

  private utm(params: URLSearchParams): Record<string, string | null> {
    return {
      source: params.get('utm_source'),
      medium: params.get('utm_medium'),
      campaign: params.get('utm_campaign'),
      term: params.get('utm_term'),
      content: params.get('utm_content'),
    };
  }

  private viewport(): { width: number; height: number } {
    return { width: Math.max(1, window.innerWidth), height: Math.max(1, window.innerHeight) };
  }

  private deviceType(): 'desktop' | 'tablet' | 'mobile' {
    if (window.innerWidth <= 767) return 'mobile';
    if (window.innerWidth <= 1024) return 'tablet';
    return 'desktop';
  }

  private selector(element: Element): string {
    if (element.id) return `#${CSS.escape(element.id)}`.slice(0, 512);
    const parts: string[] = [];
    let current: Element | null = element;
    while (current && parts.length < 5) {
      let part = current.tagName.toLowerCase();
      const stableClass = [...current.classList].find((name) => /^[a-zA-Z][\w-]{0,63}$/.test(name));
      if (stableClass) part += `.${CSS.escape(stableClass)}`;
      const parent: Element | null = current.parentElement;
      if (parent) {
        const siblings = [...parent.children].filter((child) => child.tagName === current?.tagName);
        if (siblings.length > 1) part += `:nth-of-type(${siblings.indexOf(current) + 1})`;
      }
      parts.unshift(part);
      current = parent;
    }

    return parts.join(' > ').slice(0, 512);
  }

  private touchSession(): void {
    this.storage.set('session_seen_at', String(Date.now()));
  }

  private emitTelemetry(event: AnalyticsEvent): void {
    if (!this.started) return;
    this.touchSession();
    this.transport.enqueue({
      type: 'event', visitorId: this.visitorId, sessionId: this.sessionId, eventId: this.eventId,
      telemetryId: crypto.randomUUID(),
      data: {
        name: event.name.slice(0, 128), category: event.category,
        properties: sanitizeProperties(event.properties ?? {}), metrics: sanitizeProperties(event.metrics ?? {}),
        occurredAt: event.occurredAt ?? new Date().toISOString(), path: window.location.pathname || '/',
        title: document.title.slice(0, 512), identifiedUserId: this.identifiedUserId,
        viewport: this.viewport(), deviceType: this.deviceType(), attribution: this.attribution.capture(),
      },
    });
  }

  private isReplaySampled(): boolean {
    const sampledSessionId = this.storage.get('replay_sampled_session_id');
    const stored = this.storage.get('replay_sampled');
    if (sampledSessionId === this.sessionId && stored !== null) return stored === 'true';
    const sampled = Math.random() < this.options.replaySampleRate;
    this.storage.set('replay_sampled_session_id', this.sessionId);
    this.storage.set('replay_sampled', String(sampled));
    return sampled;
  }

  private validateOptions(): void {
    if (!this.options.endpoint || !this.options.projectToken) throw new Error('endpoint and projectToken are required.');
    if (this.options.replayBatchSize < 1 || this.options.replayBatchSize > 100) throw new Error('replayBatchSize must be between 1 and 100.');
    if (this.options.sessionTimeoutMs < 60_000) throw new Error('sessionTimeoutMs must be at least one minute.');
    if (this.options.replaySampleRate < 0 || this.options.replaySampleRate > 1) throw new Error('replaySampleRate must be between 0 and 1.');
  }
}
