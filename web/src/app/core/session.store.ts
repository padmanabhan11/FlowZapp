import { HttpClient } from '@angular/common/http';
import { Injectable, computed, inject, signal } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import { Me, WorkspaceSummary } from './api.types';
import { WorkspaceStore } from './workspace.store';

/**
 * Who is signed in and which workspaces they belong to. Loaded once per app
 * start from GET /api/v1/auth/me; the API is the source of truth.
 */
@Injectable({ providedIn: 'root' })
export class SessionStore {
  private readonly http = inject(HttpClient);
  private readonly workspace = inject(WorkspaceStore);

  readonly me = signal<Me | null>(null);
  readonly loaded = signal(false);
  readonly user = computed(() => this.me()?.user ?? null);
  readonly workspaces = computed(() => this.me()?.workspaces ?? []);
  readonly current = computed<WorkspaceSummary | null>(
    () => this.workspaces().find((w) => w.id === this.workspace.id()) ?? null,
  );

  private csrfReady = false;

  /** Sanctum SPA auth: fetch the CSRF cookie once before any mutating call. */
  async ensureCsrf(): Promise<void> {
    if (this.csrfReady) return;
    await firstValueFrom(this.http.get('/sanctum/csrf-cookie', { withCredentials: true }));
    this.csrfReady = true;
  }

  async load(): Promise<Me | null> {
    try {
      const me = await firstValueFrom(this.http.get<Me>('/api/v1/auth/me'));
      this.me.set(me);
      // Keep the selected workspace valid; default to the first one.
      const ids = me.workspaces.map((w) => w.id);
      if (!this.workspace.id() || !ids.includes(this.workspace.id()!)) {
        this.workspace.select(ids[0] ?? null);
      }
      return me;
    } catch {
      this.me.set(null);
      return null;
    } finally {
      this.loaded.set(true);
    }
  }

  async requestMagicLink(email: string, next?: string): Promise<void> {
    await this.ensureCsrf();
    await firstValueFrom(this.http.post('/api/v1/auth/magic-link', { email, next }));
  }

  async acceptInvite(token: string): Promise<WorkspaceSummary> {
    await this.ensureCsrf();
    const res = await firstValueFrom(
      this.http.post<{
        data: { workspace: Omit<WorkspaceSummary, 'role'>; role: WorkspaceSummary['role'] };
      }>('/api/v1/invites/accept', { token }),
    );
    const ws = { ...res.data.workspace, role: res.data.role };
    await this.load();
    this.workspace.select(ws.id);
    return ws;
  }

  async logout(): Promise<void> {
    await this.ensureCsrf();
    await firstValueFrom(this.http.post('/api/v1/auth/logout', {}));
    this.me.set(null);
    this.workspace.select(null);
  }
}
