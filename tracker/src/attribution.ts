// SPDX-FileCopyrightText: 2025-2026 Zorin Projects S.P. <creator@localzet.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { SafeStorage } from './storage';

const CLICK_IDS = ['gclid', 'dclid', 'gbraid', 'wbraid', 'yclid', 'fbclid', 'ttclid', 'msclkid', 'vk_click_id'];

export interface Attribution {
  source: string | null;
  medium: string | null;
  campaign: string | null;
  term: string | null;
  content: string | null;
  referrer: string | null;
  landingPage: string;
  clickIds: Record<string, string>;
  capturedAt: string;
}

export class AttributionTracker {
  public constructor(private readonly storage: SafeStorage) {}

  public capture(): { first: Attribution; last: Attribution } {
    const current = this.current();
    const first = this.read('attribution_first') ?? current;
    this.storage.set('attribution_first', JSON.stringify(first));
    if (this.hasCampaign(current) || !this.read('attribution_last')) {
      this.storage.set('attribution_last', JSON.stringify(current));
    }

    return { first, last: this.read('attribution_last') ?? current };
  }

  private current(): Attribution {
    const params = new URLSearchParams(window.location.search);
    const clickIds: Record<string, string> = {};
    for (const key of CLICK_IDS) {
      const value = params.get(key);
      if (value) clickIds[key] = value.slice(0, 256);
    }
    return {
      source: params.get('utm_source'), medium: params.get('utm_medium'), campaign: params.get('utm_campaign'),
      term: params.get('utm_term'), content: params.get('utm_content'), referrer: document.referrer || null,
      landingPage: window.location.pathname.slice(0, 2048), clickIds,
      capturedAt: new Date().toISOString(),
    };
  }

  private read(key: string): Attribution | null {
    try { return JSON.parse(this.storage.get(key) ?? 'null') as Attribution | null; } catch { return null; }
  }

  private hasCampaign(value: Attribution): boolean {
    return Boolean(value.source || value.referrer || Object.keys(value.clickIds).length);
  }
}
