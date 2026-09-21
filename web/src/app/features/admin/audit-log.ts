import { DatePipe } from '@angular/common';
import { Component, OnInit, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { ButtonModule } from 'primeng/button';
import { InputTextModule } from 'primeng/inputtext';
import { SelectModule } from 'primeng/select';
import { AdminApi, Member } from '../../core/admin.api';
import { AdminInsightsApi, AuditFilter, AuditRow } from '../../core/admin-insights.api';
import { SessionStore } from '../../core/session.store';

/**
 * S21 — Audit log. Exists to be shown to an auditor: filter bar, a table with
 * timestamps in mono, export of the current filter. No edit or delete control
 * exists anywhere here because none exists in the database. Prints cleanly.
 */
@Component({
  selector: 'app-audit-log',
  imports: [DatePipe, FormsModule, RouterLink, ButtonModule, InputTextModule, SelectModule],
  templateUrl: './audit-log.html',
  styleUrl: './audit-log.scss',
})
export class AuditLog implements OnInit {
  private readonly api = inject(AdminInsightsApi);
  private readonly admin = inject(AdminApi);
  private readonly session = inject(SessionStore);

  readonly rows = signal<AuditRow[]>([]);
  readonly cursor = signal<number | null>(null);
  readonly actions = signal<string[]>([]);
  readonly members = signal<Member[]>([]);
  readonly error = signal<string | null>(null);
  readonly loading = signal(false);

  readonly entityType = signal<string | null>(null);
  readonly actorId = signal<string | null>(null);
  readonly action = signal<string | null>(null);
  readonly from = signal('');
  readonly to = signal('');

  readonly entityTypes = [
    'document',
    'user',
    'space',
    'folder',
    'recording',
    'workspace',
    'chat_message',
    'invite',
  ].map((v) => ({ label: v, value: v }));

  async ngOnInit(): Promise<void> {
    const wsId = this.session.current()?.id;
    try {
      if (wsId) this.members.set(await this.admin.members(wsId));
    } catch {
      this.members.set([]);
    }
    await this.load(true);
  }

  filter(): AuditFilter {
    return {
      entity_type: this.entityType() ?? undefined,
      actor_id: this.actorId() ?? undefined,
      action: this.action() ?? undefined,
      from: this.from() || undefined,
      to: this.to() || undefined,
    };
  }

  async load(reset: boolean): Promise<void> {
    this.loading.set(true);
    this.error.set(null);
    try {
      const page = await this.api.auditLog(this.filter(), reset ? null : this.cursor());
      this.rows.set(reset ? page.data : [...this.rows(), ...page.data]);
      this.cursor.set(page.next_cursor);
      if (page.actions.length) this.actions.set(page.actions);
    } catch {
      this.error.set('The audit log could not be loaded.');
    } finally {
      this.loading.set(false);
    }
  }

  async exportCsv(): Promise<void> {
    try {
      const blob = await this.api.auditExport(this.filter());
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = `audit-log-${new Date().toISOString().slice(0, 10)}.csv`;
      a.click();
      URL.revokeObjectURL(url);
    } catch {
      this.error.set('The export could not be produced.');
    }
  }

  detail(r: AuditRow): string {
    if (!r.metadata) return '';
    return Object.entries(r.metadata)
      .map(([k, v]) => `${k}: ${typeof v === 'object' ? JSON.stringify(v) : String(v)}`)
      .join(' · ');
  }

  print(): void {
    window.print();
  }
}
