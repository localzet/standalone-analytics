// SPDX-FileCopyrightText: 2025-2026 Zorin Projects S.P. <creator@localzet.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { safeUrl } from './sanitizer';
import type { AnalyticsEvent } from './types';

interface AutocaptureOptions {
  endpoint: string;
  trackNetwork: boolean;
  trackDomMutations: boolean;
  captureText: boolean;
  sensitiveSelectors: string[];
  selector: (element: Element) => string;
}

export function installAutocapture(
  emit: (event: AnalyticsEvent, heatmap?: Record<string, unknown>) => void,
  options: AutocaptureOptions,
): () => void {
  const cleanups: Array<() => void> = [];
  const focusStarted = new WeakMap<Element, number>();
  const touchedForms = new Set<HTMLFormElement>();
  const recentClicks: Array<{ x: number; y: number; at: number }> = [];
  let maxScroll = 0;
  let lastMoveAt = 0;
  let mutationTimer: ReturnType<typeof setTimeout> | undefined;
  let mutations = { added: 0, removed: 0, attributes: 0, text: 0 };

  const sensitive = (element: Element): boolean => {
    if (element.matches('input[type=password], input[type=email], input[type=tel], [autocomplete*=cc-], [data-pa-private], .pa-block')) return true;
    return options.sensitiveSelectors.some((selector) => {
      try { return element.matches(selector) || Boolean(element.closest(selector)); } catch { return false; }
    });
  };
  const elementData = (element: Element): Record<string, unknown> => ({
    selector: options.selector(element),
    tag: element.tagName.toLowerCase(),
    role: element.getAttribute('role'),
    ariaLabel: element.getAttribute('aria-label')?.slice(0, 128),
    testId: element.getAttribute('data-testid')?.slice(0, 128),
    text: options.captureText && !sensitive(element) ? (element.textContent ?? '').trim().slice(0, 128) : undefined,
  });
  const listen = <K extends keyof WindowEventMap>(target: Window | Document, name: K, handler: EventListenerOrEventListenerObject, options?: AddEventListenerOptions): void => {
    target.addEventListener(name, handler, options);
    cleanups.push(() => target.removeEventListener(name, handler, options));
  };

  listen(document, 'click', ((event: MouseEvent) => {
    if (!event.isTrusted || !(event.target instanceof Element) || sensitive(event.target)) return;
    const now = Date.now();
    recentClicks.push({ x: event.clientX, y: event.clientY, at: now });
    while ((recentClicks[0]?.at ?? now) < now - 1_000) recentClicks.shift();
    const nearby = recentClicks.filter((click) => Math.hypot(click.x - event.clientX, click.y - event.clientY) < 40).length;
    const link = event.target.closest('a[href]') as HTMLAnchorElement | null;
    const properties = {
      ...elementData(event.target), button: event.button, x: event.pageX, y: event.pageY,
      outbound: Boolean(link && link.host !== window.location.host),
      download: link?.hasAttribute('download') ?? false,
      href: link ? safeUrl(link.href) : undefined,
    };
    emit({ name: 'click', category: 'interaction', properties }, { type: 'click', ...properties });
    if (nearby >= 3) emit({ name: 'rage_click', category: 'interaction', properties: { ...properties, clicks: nearby } });
  }) as EventListener, { capture: true });

  listen(document, 'dblclick', ((event: MouseEvent) => {
    if (event.target instanceof Element && !sensitive(event.target)) emit({ name: 'double_click', category: 'interaction', properties: elementData(event.target) });
  }) as EventListener, { capture: true });
  listen(document, 'contextmenu', ((event: MouseEvent) => {
    if (event.target instanceof Element && !sensitive(event.target)) emit({ name: 'context_menu', category: 'interaction', properties: elementData(event.target) });
  }) as EventListener, { capture: true });
  listen(document, 'pointermove', ((event: PointerEvent) => {
    if (!event.isTrusted || Date.now() - lastMoveAt < 750) return;
    lastMoveAt = Date.now();
    emit({ name: 'pointer_sample', category: 'interaction', properties: { pointerType: event.pointerType } }, { type: 'move', x: event.pageX, y: event.pageY });
  }) as EventListener, { passive: true });

  listen(document, 'scroll', (() => {
    const height = Math.max(document.documentElement.scrollHeight - window.innerHeight, 1);
    const percentage = Math.max(0, Math.min(100, Math.round(window.scrollY / height * 100)));
    if (percentage <= maxScroll) return;
    maxScroll = percentage;
    if (percentage % 10 === 0 || percentage >= 95) {
      emit({ name: 'scroll_depth', category: 'engagement', metrics: { percentage } }, { type: 'scroll', percentage });
    }
  }) as EventListener, { passive: true });

  listen(document, 'focusin', ((event: FocusEvent) => {
    if (!(event.target instanceof Element) || sensitive(event.target)) return;
    focusStarted.set(event.target, performance.now());
    const form = event.target.closest('form');
    if (form) touchedForms.add(form);
    emit({ name: 'field_focus', category: 'interaction', properties: elementData(event.target) });
  }) as EventListener, { capture: true });
  listen(document, 'focusout', ((event: FocusEvent) => {
    if (!(event.target instanceof Element) || sensitive(event.target)) return;
    const started = focusStarted.get(event.target);
    emit({ name: 'field_blur', category: 'interaction', properties: elementData(event.target), metrics: { duration: started ? performance.now() - started : 0 } });
  }) as EventListener, { capture: true });
  listen(document, 'change', ((event: Event) => {
    if (!(event.target instanceof Element) || sensitive(event.target)) return;
    const field = event.target as HTMLInputElement | HTMLSelectElement;
    emit({ name: 'field_change', category: 'interaction', properties: {
      ...elementData(field), fieldType: field.getAttribute('type') ?? field.tagName.toLowerCase(),
      checked: 'checked' in field ? field.checked : undefined,
      selectedIndex: field instanceof HTMLSelectElement ? field.selectedIndex : undefined,
    } });
  }) as EventListener, { capture: true });
  listen(document, 'submit', ((event: SubmitEvent) => {
    const form = event.target instanceof HTMLFormElement ? event.target : null;
    if (!form) return;
    touchedForms.delete(form);
    emit({ name: 'form_submit', category: 'interaction', properties: { ...elementData(form), fields: form.elements.length } });
  }) as EventListener, { capture: true });

  for (const name of ['copy', 'cut', 'paste'] as const) {
    listen(document, name, ((event: ClipboardEvent) => {
      if (event.target instanceof Element && !sensitive(event.target)) emit({ name, category: 'interaction', properties: elementData(event.target) });
    }) as EventListener, { capture: true });
  }
  listen(document, 'selectionchange', (() => {
    const length = document.getSelection()?.toString().length ?? 0;
    if (length) emit({ name: 'text_selection', category: 'interaction', metrics: { characters: Math.min(length, 10_000) } });
  }) as EventListener);

  for (const name of ['play', 'pause', 'ended', 'seeking', 'volumechange'] as const) {
    listen(document, name, ((event: Event) => {
      if (!(event.target instanceof HTMLMediaElement)) return;
      emit({ name: `media_${name}`, category: 'engagement', properties: elementData(event.target), metrics: { currentTime: event.target.currentTime, duration: Number.isFinite(event.target.duration) ? event.target.duration : 0 } });
    }) as EventListener, { capture: true });
  }

  if (options.trackDomMutations && 'MutationObserver' in window) {
    const observer = new MutationObserver((records) => {
      for (const record of records) {
        mutations.added += record.addedNodes.length; mutations.removed += record.removedNodes.length;
        if (record.type === 'attributes') mutations.attributes += 1;
        if (record.type === 'characterData') mutations.text += 1;
      }
      clearTimeout(mutationTimer);
      mutationTimer = setTimeout(() => {
        emit({ name: 'dom_mutation', category: 'interaction', metrics: { ...mutations } });
        mutations = { added: 0, removed: 0, attributes: 0, text: 0 };
      }, 1_000);
    });
    observer.observe(document.documentElement, { subtree: true, childList: true, attributes: true, characterData: true });
    cleanups.push(() => observer.disconnect());
  }

  if (options.trackNetwork) installNetworkCapture(emit, options.endpoint, cleanups);

  const abandon = (): void => {
    for (const form of touchedForms) emit({ name: 'form_abandon', category: 'interaction', properties: elementData(form) });
  };
  listen(window, 'pagehide', abandon as EventListener, { capture: true });

  return () => { clearTimeout(mutationTimer); cleanups.forEach((cleanup) => cleanup()); };
}

function installNetworkCapture(emit: (event: AnalyticsEvent) => void, endpoint: string, cleanups: Array<() => void>): void {
  const originalFetch = window.fetch.bind(window);
  window.fetch = async (input, init) => {
    const url = typeof input === 'string' ? input : input instanceof URL ? input.href : input.url;
    if (safeUrl(url) === safeUrl(endpoint)) return originalFetch(input, init);
    const started = performance.now();
    try {
      const response = await originalFetch(input, init);
      emit({ name: 'http_request', category: 'network', properties: { method: init?.method ?? 'GET', url: safeUrl(url), status: response.status, ok: response.ok }, metrics: { duration: performance.now() - started } });
      return response;
    } catch (error) {
      emit({ name: 'http_error', category: 'error', properties: { method: init?.method ?? 'GET', url: safeUrl(url), errorType: error instanceof Error ? error.name : 'Error' }, metrics: { duration: performance.now() - started } });
      throw error;
    }
  };
  cleanups.push(() => { window.fetch = originalFetch; });

  const open = XMLHttpRequest.prototype.open;
  const send = XMLHttpRequest.prototype.send;
  XMLHttpRequest.prototype.open = function (method: string, url: string | URL, ...rest: unknown[]): void {
    Object.assign(this, { __paMethod: method, __paUrl: String(url) });
    open.call(this, method, url, ...(rest as [boolean, string?, string?]));
  };
  XMLHttpRequest.prototype.send = function (body?: Document | XMLHttpRequestBodyInit | null): void {
    const request = this as XMLHttpRequest & { __paMethod?: string; __paUrl?: string; __paStarted?: number };
    request.__paStarted = performance.now();
    this.addEventListener('loadend', () => {
      if (safeUrl(request.__paUrl ?? '') === safeUrl(endpoint)) return;
      emit({ name: 'xhr_request', category: 'network', properties: { method: request.__paMethod, url: safeUrl(request.__paUrl ?? ''), status: request.status }, metrics: { duration: performance.now() - (request.__paStarted ?? performance.now()) } });
    }, { once: true });
    send.call(this, body);
  };
  cleanups.push(() => { XMLHttpRequest.prototype.open = open; XMLHttpRequest.prototype.send = send; });
}
