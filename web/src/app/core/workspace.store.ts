import { Injectable, signal } from '@angular/core';

/**
 * The current workspace on the client. Mirrors server truth (the session +
 * X-Workspace-Id header resolved by ResolveWorkspace on the API) and is never
 * the source of it. Persisted per browser as a convenience only.
 */
@Injectable({ providedIn: 'root' })
export class WorkspaceStore {
  private static readonly KEY = 'flowzapp.workspace';

  readonly id = signal<string | null>(WorkspaceStore.read());

  select(id: string | null): void {
    this.id.set(id);
    try {
      if (id) localStorage.setItem(WorkspaceStore.KEY, id);
      else localStorage.removeItem(WorkspaceStore.KEY);
    } catch {
      /* private mode etc. — in-memory only */
    }
  }

  private static read(): string | null {
    try {
      return localStorage.getItem(WorkspaceStore.KEY);
    } catch {
      return null;
    }
  }
}
