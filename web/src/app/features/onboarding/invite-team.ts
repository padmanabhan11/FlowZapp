import { Component, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Router, RouterLink } from '@angular/router';
import { ButtonModule } from 'primeng/button';
import { InputTextModule } from 'primeng/inputtext';
import { MessageModule } from 'primeng/message';
import { MultiSelectModule } from 'primeng/multiselect';
import { SelectModule } from 'primeng/select';
import { InviteResult, ROLES, Role, Space } from '../../core/api.types';
import { SessionStore } from '../../core/session.store';
import { WorkspaceApi } from '../../core/workspace.api';

interface Row {
  email: string;
  role: Role;
  spaceIds: string[];
}

/**
 * S3 — Invite team. Per-row validation so one bad address never rejects the
 * batch; seat limits are reported per row by the API before anything is sent.
 */
@Component({
  selector: 'app-invite-team',
  imports: [FormsModule, ButtonModule, InputTextModule, MessageModule, MultiSelectModule, SelectModule, RouterLink],
  templateUrl: './invite-team.html',
  styleUrl: './onboarding.scss',
})
export class InviteTeam {
  private readonly api = inject(WorkspaceApi);
  private readonly session = inject(SessionStore);
  private readonly router = inject(Router);

  readonly roles = ROLES.map((r) => ({ label: r.charAt(0).toUpperCase() + r.slice(1), value: r }));
  readonly spaces = signal<Space[]>([]);
  readonly rows = signal<Row[]>([{ email: '', role: 'editor', spaceIds: [] }]);
  readonly results = signal<InviteResult[]>([]);
  readonly sending = signal(false);
  readonly error = signal<string | null>(null);
  readonly workspaceName = computed(() => this.session.current()?.name ?? 'your workspace');

  readonly validRows = computed(() => this.rows().filter((r) => InviteTeam.emailOk(r.email)));
  readonly limitHit = computed(() => this.results().find((r) => r.status === 'plan_limit_exceeded') ?? null);

  constructor() {
    void this.api.spaces().then((s) => this.spaces.set(s)).catch(() => this.spaces.set([]));
  }

  static emailOk(v: string): boolean {
    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v.trim());
  }

  rowInvalid(r: Row): boolean {
    return r.email.trim() !== '' && !InviteTeam.emailOk(r.email);
  }

  addRow(): void {
    this.rows.update((rs) => [...rs, { email: '', role: 'editor', spaceIds: [] }]);
  }

  removeRow(i: number): void {
    this.rows.update((rs) => (rs.length > 1 ? rs.filter((_, k) => k !== i) : rs));
  }

  /** Paste a comma- or newline-separated list into the first empty row. */
  onPaste(i: number, ev: ClipboardEvent): void {
    const text = ev.clipboardData?.getData('text') ?? '';
    const parts = text.split(/[\s,;]+/).filter(Boolean);
    if (parts.length <= 1) return;
    ev.preventDefault();
    this.rows.update((rs) => {
      const out = [...rs];
      out[i] = { ...out[i], email: parts[0] };
      for (const p of parts.slice(1)) out.push({ email: p, role: out[i].role, spaceIds: out[i].spaceIds });
      return out;
    });
  }

  patch(i: number, patch: Partial<Row>): void {
    this.rows.update((rs) => rs.map((r, k) => (k === i ? { ...r, ...patch } : r)));
  }

  async send(): Promise<void> {
    const ws = this.session.current();
    const rows = this.validRows();
    if (!ws || rows.length === 0 || this.sending()) return;
    this.sending.set(true);
    this.error.set(null);
    try {
      const results = await this.api.invite(
        ws.id,
        rows.map((r) => ({ email: r.email.trim(), role: r.role, space_ids: r.spaceIds })),
      );
      this.results.set(results);
      const sent = new Set(results.filter((r) => r.status === 'sent').map((r) => r.email));
      this.rows.update((rs) => {
        const left = rs.filter((r) => !sent.has(r.email.trim().toLowerCase()));
        return left.length ? left : [{ email: '', role: 'editor', spaceIds: [] }];
      });
    } catch (e: unknown) {
      const err = e as { status?: number; error?: { data?: InviteResult[] } };
      if (err.status === 429 && err.error?.data) {
        this.results.set(err.error.data);
      } else {
        this.error.set('Invitations could not be sent. Try again.');
      }
    } finally {
      this.sending.set(false);
    }
  }

  async skip(): Promise<void> {
    await this.router.navigate(['/onboarding/first-recording']);
  }
}
