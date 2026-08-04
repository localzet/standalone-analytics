// SPDX-FileCopyrightText: 2025-2026 Zorin Projects S.P. <creator@localzet.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

const memory = new Map<string, string>();

export class SafeStorage {
  public constructor(private readonly namespace = 'localzet_pa') {}

  public get(key: string): string | null {
    const namespaced = this.key(key);
    try {
      return window.localStorage.getItem(namespaced);
    } catch {
      return memory.get(namespaced) ?? null;
    }
  }

  public set(key: string, value: string): void {
    const namespaced = this.key(key);
    try {
      window.localStorage.setItem(namespaced, value);
    } catch {
      memory.set(namespaced, value);
    }
  }

  public remove(key: string): void {
    const namespaced = this.key(key);
    try {
      window.localStorage.removeItem(namespaced);
    } catch {
      memory.delete(namespaced);
    }
  }

  public clearIdentity(): void {
    for (const key of ['visitor_id', 'session_id', 'session_seen_at']) {
      this.remove(key);
    }
  }

  private key(key: string): string {
    return `__${this.namespace}_${key}`;
  }
}
