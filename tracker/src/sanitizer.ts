// SPDX-FileCopyrightText: 2025-2026 Zorin Projects S.P. <creator@localzet.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

const SENSITIVE_KEY = /pass|secret|token|authorization|cookie|credit.?card|card.?number|cvv|cvc|iban|account.?number|email|phone|address/i;

export function sanitizeProperties(value: unknown, depth = 0): unknown {
  if (depth > 4) return '[truncated]';
  if (value === null || typeof value === 'boolean') return value;
  if (typeof value === 'number') return Number.isFinite(value) ? value : null;
  if (typeof value === 'string') return value.slice(0, 512);
  if (Array.isArray(value)) return value.slice(0, 50).map((item) => sanitizeProperties(item, depth + 1));
  if (typeof value !== 'object') return String(value).slice(0, 512);

  const result: Record<string, unknown> = {};
  for (const [key, item] of Object.entries(value as Record<string, unknown>).slice(0, 50)) {
    result[key.slice(0, 64)] = SENSITIVE_KEY.test(key) ? '[redacted]' : sanitizeProperties(item, depth + 1);
  }
  return result;
}

export function safeUrl(value: string, keepMarketing = false): string {
  try {
    const url = new URL(value, window.location.href);
    if (!keepMarketing) url.search = '';
    url.hash = '';
    return `${url.origin}${url.pathname}`.slice(0, 2048);
  } catch {
    return '';
  }
}
