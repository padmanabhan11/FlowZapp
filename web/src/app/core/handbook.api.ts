import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import { DocType, Space } from './api.types';
import { SessionStore } from './session.store';

export interface HandbookEntry {
  id: string;
  title: string;
  doc_type: DocType;
  handbook_position: number | null;
  version_number: number | null;
  approved_at: string | null;
  requires_ack: boolean;
  ack_required_from_me: boolean;
  acknowledged_at: string | null;
}

export interface HandbookView {
  space: Space | null;
  can_reorder: boolean;
  documents: HandbookEntry[];
}

export interface AckRow {
  document_id: string;
  title: string;
  space_id: string;
  version_id: string;
  version_number: number | null;
  user: { id: string; name: string; email: string } | null;
  status: 'outstanding' | 'done';
  acknowledged_at: string | null;
  assigned_at: string;
}

export interface AckReceipt {
  document_id: string;
  version_id: string;
  version_number: number | null;
  user: { id: string; name: string };
  acknowledged_at: string;
}

@Injectable({ providedIn: 'root' })
export class HandbookApi {
  private readonly http = inject(HttpClient);
  private readonly session = inject(SessionStore);

  async view(spaceId?: string): Promise<HandbookView> {
    const params: Record<string, string> = spaceId ? { space_id: spaceId } : {};
    return (
      await firstValueFrom(this.http.get<{ data: HandbookView }>('/api/v1/handbook', { params }))
    ).data;
  }

  async reorder(spaceId: string, order: string[]): Promise<HandbookView> {
    await this.session.ensureCsrf();
    return (
      await firstValueFrom(
        this.http.put<{ data: HandbookView }>('/api/v1/handbook/order', {
          space_id: spaceId,
          order,
        }),
      )
    ).data;
  }

  async acknowledge(documentId: string): Promise<AckReceipt> {
    await this.session.ensureCsrf();
    return (
      await firstValueFrom(
        this.http.post<{ data: AckReceipt }>(`/api/v1/documents/${documentId}/acknowledge`, {}),
      )
    ).data;
  }

  async setTargets(
    documentId: string,
    userIds: string[],
  ): Promise<{ user_ids: string[]; requires_ack: boolean }> {
    await this.session.ensureCsrf();
    return (
      await firstValueFrom(
        this.http.post<{ data: { user_ids: string[]; requires_ack: boolean } }>(
          `/api/v1/documents/${documentId}/acknowledgement-targets`,
          { user_ids: userIds },
        ),
      )
    ).data;
  }

  async rows(
    filter: {
      document_id?: string;
      user_id?: string;
      status?: 'outstanding' | 'done';
      space_id?: string;
    } = {},
  ): Promise<AckRow[]> {
    const params = Object.fromEntries(
      Object.entries(filter).filter(([, v]) => v !== undefined && v !== null && v !== ''),
    ) as Record<string, string>;
    return (
      await firstValueFrom(
        this.http.get<{ data: AckRow[] }>('/api/v1/acknowledgements', { params }),
      )
    ).data;
  }

  async remind(documentId: string, userIds?: string[]): Promise<number> {
    await this.session.ensureCsrf();
    const res = await firstValueFrom(
      this.http.post<{ data: { reminded: number } }>('/api/v1/acknowledgements/remind', {
        document_id: documentId,
        user_ids: userIds,
      }),
    );
    return res.data.reminded;
  }

  /** CSV export goes through the interceptor for credentials, then is handed to the browser as a download. */
  async exportCsv(spaceId?: string): Promise<Blob> {
    const params: Record<string, string> = spaceId ? { space_id: spaceId } : {};
    return firstValueFrom(
      this.http.get('/api/v1/acknowledgements/export', { params, responseType: 'blob' as const }),
    );
  }
}
