import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import { DocState } from './api.types';

export interface AuditRow {
  id: number;
  at: string;
  actor: { id: string; name: string; email: string } | null;
  action: string;
  entity_type: string;
  entity_id: string | null;
  metadata: Record<string, unknown> | null;
  ip: string | null;
}
export interface AuditFilter {
  entity_type?: string;
  actor_id?: string;
  action?: string;
  from?: string;
  to?: string;
}
export interface AuditPage {
  data: AuditRow[];
  next_cursor: number | null;
  actions: string[];
}

export interface Overview {
  window_days: number;
  documents_by_state: Record<DocState, number>;
  past_review_by_space: { space_id: string; space: string; count: number }[];
  approvals_30d: number;
  chat: {
    questions_30d: number;
    unique_askers_30d: number;
    helpful_rate_pct: number | null;
    refused_rate_pct: number | null;
  };
  most_read: { document_id: string; title: string; reads: number }[];
  /** G1-T5: approved documents not yet in the search index. */
  unindexed: { document_id: string; title: string; approved_at: string | null }[];
}

@Injectable({ providedIn: 'root' })
export class AdminInsightsApi {
  private readonly http = inject(HttpClient);

  private static clean(f: Record<string, unknown>): Record<string, string> {
    return Object.fromEntries(
      Object.entries(f)
        .filter(([, v]) => v !== undefined && v !== null && v !== '')
        .map(([k, v]) => [k, String(v)]),
    );
  }

  async auditLog(filter: AuditFilter, cursor?: number | null, limit = 50): Promise<AuditPage> {
    return firstValueFrom(
      this.http.get<AuditPage>('/api/v1/audit-log', {
        params: AdminInsightsApi.clean({ ...filter, cursor, limit }),
      }),
    );
  }

  async auditExport(filter: AuditFilter): Promise<Blob> {
    return firstValueFrom(
      this.http.get('/api/v1/audit-log/export', {
        params: AdminInsightsApi.clean({ ...filter }),
        responseType: 'blob' as const,
      }),
    );
  }

  async overview(): Promise<Overview> {
    return (await firstValueFrom(this.http.get<{ data: Overview }>('/api/v1/analytics/overview')))
      .data;
  }
}
