// SPDX-FileCopyrightText: 2025-2026 Zorin Projects S.P. <creator@localzet.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { afterEach, describe, expect, it, vi } from 'vitest';
import { isDoNotTrackEnabled } from '../src/privacy';

describe('isDoNotTrackEnabled', () => {
  afterEach(() => vi.unstubAllGlobals());

  it.each(['1', 'yes'])('detects %s', (value) => {
    const source = { doNotTrack: value, navigator: {} } as unknown as Window & typeof globalThis;
    expect(isDoNotTrackEnabled(source)).toBe(true);
  });

  it('does not treat zero as DNT', () => {
    const source = { doNotTrack: '0', navigator: { doNotTrack: '0' } } as unknown as Window & typeof globalThis;
    expect(isDoNotTrackEnabled(source)).toBe(false);
  });
});
