import { Component, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Router, RouterLink, RouterLinkActive, RouterOutlet } from '@angular/router';
import { ButtonModule } from 'primeng/button';
import { InputTextModule } from 'primeng/inputtext';
import { SelectModule } from 'primeng/select';
import { Space } from '../../core/api.types';
import { SessionStore } from '../../core/session.store';
import { SpaceApi } from '../../core/workspace.api';
import { WorkspaceStore } from '../../core/workspace.store';
import { LimitNotice } from './limit-notice';
import { Notifications } from './notifications';

/**
 * S5 — App shell. One omnibox for search and chat (question-shaped input →
 * Ask, otherwise Search); Ask above the tree; the sidebar renders only the
 * spaces GET /spaces returns, which is already permission-scoped (A5).
 */
@Component({
  selector: 'app-shell',
  imports: [
    RouterOutlet,
    RouterLink,
    RouterLinkActive,
    FormsModule,
    ButtonModule,
    InputTextModule,
    SelectModule,
    Notifications,
    LimitNotice,
  ],
  templateUrl: './shell.html',
  styleUrl: './shell.scss',
})
export class Shell {
  private readonly session = inject(SessionStore);
  private readonly workspace = inject(WorkspaceStore);
  private readonly spaceApi = inject(SpaceApi);
  private readonly router = inject(Router);

  readonly user = this.session.user;
  readonly workspaces = this.session.workspaces;
  readonly current = this.session.current;
  readonly isAdmin = computed(() => this.current()?.role === 'admin');
  readonly canRecord = computed(() =>
    ['admin', 'approver', 'editor'].includes(this.current()?.role ?? ''),
  );
  readonly spaces = signal<Space[]>([]);
  readonly handbook = computed(() => this.spaces().find((s) => s.is_handbook) ?? null);
  readonly query = signal('');

  constructor() {
    void this.loadSpaces();
  }

  async loadSpaces(): Promise<void> {
    try {
      this.spaces.set(await this.spaceApi.list());
    } catch {
      this.spaces.set([]);
    }
  }

  async switchWorkspace(id: string): Promise<void> {
    this.workspace.select(id);
    this.spaces.set([]); // clear scope from the previous workspace before reloading
    await this.loadSpaces();
    await this.router.navigate(['/']);
  }

  /** Routing heuristic (S5 rules): ends in ? or starts with an interrogative → Ask; otherwise Search. */
  static isQuestion(q: string): boolean {
    const t = q.trim().toLowerCase();
    return (
      t.endsWith('?') || /^(how|what|when|where|who|why|which|can|do|does|is|are|should)\b/.test(t)
    );
  }

  async submitOmnibox(): Promise<void> {
    const q = this.query().trim();
    if (!q) return;
    await this.router.navigate([Shell.isQuestion(q) ? '/ask' : '/search'], { queryParams: { q } });
  }

  async signOut(): Promise<void> {
    await this.session.logout();
    await this.router.navigate(['/auth']);
  }
}
