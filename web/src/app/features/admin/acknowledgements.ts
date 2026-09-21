import { DatePipe } from '@angular/common';
import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { ButtonModule } from 'primeng/button';
import { MultiSelectModule } from 'primeng/multiselect';
import { SelectModule } from 'primeng/select';
import { AdminApi, Member } from '../../core/admin.api';
import { DocumentSummary } from '../../core/api.types';
import { DocumentApi } from '../../core/document.api';
import { AckRow, HandbookApi } from '../../core/handbook.api';
import { SessionStore } from '../../core/session.store';

interface ByDocument {
  document_id: string;
  title: string;
  version_number: number | null;
  assigned: number;
  confirmed: number;
  outstanding: number;
}

interface ByPerson {
  user: { id: string; name: string; email: string };
  outstanding: number;
  oldest: string | null;
  documents: string[];
}

/**
 * S17 — Acknowledgement tracking. By document / by person; outstanding counts
 * are plain numbers (no progress rings); nudge sends a reminder; export is one
 * row per user per document version. Assigning targets lives here too
 * (FR-702) so HR never needs the editor to do it.
 */
@Component({
  selector: 'app-acknowledgements',
  imports: [DatePipe, FormsModule, RouterLink, ButtonModule, MultiSelectModule, SelectModule],
  templateUrl: './acknowledgements.html',
  styleUrl: './admin.scss',
})
export class Acknowledgements implements OnInit {
  private readonly api = inject(HandbookApi);
  private readonly admin = inject(AdminApi);
  private readonly docs = inject(DocumentApi);
  private readonly session = inject(SessionStore);

  readonly rows = signal<AckRow[]>([]);
  readonly tab = signal<'document' | 'person'>('document');
  readonly error = signal<string | null>(null);
  readonly notice = signal<string | null>(null);
  readonly members = signal<Member[]>([]);
  readonly approved = signal<DocumentSummary[]>([]);
  readonly assignDoc = signal<string | null>(null);
  readonly assignUsers = signal<string[]>([]);
  readonly busy = signal(false);

  readonly byDocument = computed<ByDocument[]>(() => {
    const m = new Map<string, ByDocument>();
    for (const r of this.rows()) {
      const d = m.get(r.document_id) ?? {
        document_id: r.document_id,
        title: r.title,
        version_number: r.version_number,
        assigned: 0,
        confirmed: 0,
        outstanding: 0,
      };
      d.assigned++;
      if (r.status === 'done') d.confirmed++;
      else d.outstanding++;
      m.set(r.document_id, d);
    }
    return [...m.values()].sort(
      (a, b) => b.outstanding - a.outstanding || a.title.localeCompare(b.title),
    );
  });

  readonly byPerson = computed<ByPerson[]>(() => {
    const m = new Map<string, ByPerson>();
    for (const r of this.rows()) {
      if (!r.user || r.status !== 'outstanding') continue;
      const p = m.get(r.user.id) ?? { user: r.user, outstanding: 0, oldest: null, documents: [] };
      p.outstanding++;
      p.documents.push(r.title);
      if (!p.oldest || r.assigned_at < p.oldest) p.oldest = r.assigned_at;
      m.set(r.user.id, p);
    }
    return [...m.values()].sort(
      (a, b) => b.outstanding - a.outstanding || a.user.name.localeCompare(b.user.name),
    );
  });

  async ngOnInit(): Promise<void> {
    await this.load();
    const wsId = this.session.current()?.id;
    try {
      if (wsId) this.members.set(await this.admin.members(wsId));
      this.approved.set(await this.docs.list({ state: 'approved' }));
    } catch {
      /* assignment panel degrades to read-only */
    }
  }

  async load(): Promise<void> {
    try {
      this.rows.set(await this.api.rows());
    } catch {
      this.error.set('Acknowledgements could not be loaded.');
    }
  }

  /** Pre-fill the assignment panel with a document's current targets. */
  pickDocument(id: string | null): void {
    this.assignDoc.set(id);
    this.assignUsers.set(
      this.rows()
        .filter((r) => r.document_id === id && r.user)
        .map((r) => r.user!.id),
    );
  }

  async saveTargets(): Promise<void> {
    const id = this.assignDoc();
    if (!id) return;
    this.busy.set(true);
    try {
      const r = await this.api.setTargets(id, this.assignUsers());
      this.notice.set(
        `${r.user_ids.length} ${r.user_ids.length === 1 ? 'person' : 'people'} must acknowledge this document. New assignees have been notified.`,
      );
      await this.load();
    } catch {
      this.error.set('The assignment could not be saved.');
    } finally {
      this.busy.set(false);
    }
  }

  async nudge(d: ByDocument): Promise<void> {
    try {
      const n = await this.api.remind(d.document_id);
      this.notice.set(`Reminder sent to ${n} ${n === 1 ? 'person' : 'people'} for “${d.title}”.`);
    } catch {
      this.error.set('The reminder could not be sent.');
    }
  }

  async nudgePerson(p: ByPerson): Promise<void> {
    try {
      let n = 0;
      for (const r of this.rows()) {
        if (r.user?.id === p.user.id && r.status === 'outstanding')
          n += await this.api.remind(r.document_id, [p.user.id]);
      }
      this.notice.set(`${n} reminder${n === 1 ? '' : 's'} sent to ${p.user.name}.`);
    } catch {
      this.error.set('The reminder could not be sent.');
    }
  }

  async exportCsv(): Promise<void> {
    try {
      const blob = await this.api.exportCsv();
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = `acknowledgements-${new Date().toISOString().slice(0, 10)}.csv`;
      a.click();
      URL.revokeObjectURL(url);
    } catch {
      this.error.set('The export could not be produced.');
    }
  }
}
