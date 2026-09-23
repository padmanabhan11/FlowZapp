import { DatePipe } from '@angular/common';
import { Component, OnInit, inject, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import { ButtonModule } from 'primeng/button';
import { DocState } from '../../core/api.types';
import { DocumentNeed, MyDocumentsApi, OwnedDocuments } from '../../core/my-documents.api';

/**
 * L2-T2 — owner dashboard. The documents I own, most urgent first, each with
 * what it needs from me in words. State pills carry the state colour; the
 * needs are plain text (colour means document state and nothing else).
 */
@Component({
  selector: 'app-my-documents',
  imports: [DatePipe, RouterLink, ButtonModule],
  templateUrl: './my-documents.html',
  styleUrl: './my-documents.scss',
})
export class MyDocuments implements OnInit {
  private readonly api = inject(MyDocumentsApi);

  readonly data = signal<OwnedDocuments | null>(null);
  readonly error = signal<string | null>(null);
  readonly loading = signal(true);

  async ngOnInit(): Promise<void> {
    try {
      this.data.set(await this.api.list());
    } catch {
      this.error.set('Your documents could not be loaded.');
    } finally {
      this.loading.set(false);
    }
  }

  static needText(need: DocumentNeed, d: { acknowledgements_outstanding: number }): string {
    switch (need) {
      case 'review_overdue':
        return 'Review overdue';
      case 'review_soon':
        return 'Review due soon';
      case 'draft':
        return 'Draft, not yet submitted';
      case 'revision_in_draft':
        return 'Revision in draft';
      case 'in_review':
        return 'Waiting for approval';
      case 'translation_stale':
        return 'Source changed — translation may be out of date';
      case 'acknowledgements_outstanding':
        return `${d.acknowledgements_outstanding} still to acknowledge`;
    }
  }

  needText(need: DocumentNeed, d: { acknowledgements_outstanding: number }): string {
    return MyDocuments.needText(need, d);
  }

  /** Where the owner acts on it: the editor for drafts, the reader otherwise. */
  link(d: { id: string; state: DocState }): unknown[] {
    return d.state === 'draft' ? ['/d', d.id, 'edit'] : ['/d', d.id];
  }

  stateLabel(s: DocState): string {
    return { draft: 'Draft', in_review: 'In review', approved: 'Approved', archived: 'Archived' }[
      s
    ];
  }
}
