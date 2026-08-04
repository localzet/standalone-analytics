// SPDX-FileCopyrightText: 2025-2026 Zorin Projects S.P. <creator@localzet.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

type DoNotTrackSource = Pick<Window, 'navigator'> & { doNotTrack?: string | null };

export function isDoNotTrackEnabled(source: DoNotTrackSource = window): boolean {
  const values = [
    source.doNotTrack,
    source.navigator.doNotTrack,
    (source.navigator as Navigator & { msDoNotTrack?: string }).msDoNotTrack,
  ];

  return values.some((value) => value === '1' || value === 'yes');
}
