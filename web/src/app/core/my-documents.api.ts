import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import { DocState, DocType } from './api.types';

export type DocumentNeed =
  | 'review_overdue'
  | 'review_soon'
  | 'draft'
  | 'revision_in_draft'
  | 'in_review'
  | 'translation_stale'
  | 'acknowledgements_outstanding';

/** L2-T2: one owned document and what it needs from its owner. */
export interface OwnedDocument {
  id: string;
  title: string;
  doc_type: DocType;
  state: DocState;
  space: { id: string; name: string } | null;
  review_due_at: string | null;
  approved_at: string | null;
  version_number: number | null;
  translation_stale: boolean;
  acknowledgements_outstanding: number;
  needs: DocumentNeed[];
  updated_at: string;
}

export interface OwnedDocuments {
  documents: OwnedDocument[];
  summary: {
    total: number;
    needing_attention: number;
    review_overdue: number;
    review_soon: number;
  };
}

@Injectable({ providedIn: 'root' })
export class MyDocumentsApi {
  private readonly http = inject(HttpClient);

  async list(): Promise<OwnedDocuments> {
    return (await firstValueFrom(this.http.get<{ data: OwnedDocuments }>('/api/v1/me/documents')))
      .data;
  }
}
