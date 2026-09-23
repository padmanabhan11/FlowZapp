import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import {
  Change,
  Content,
  DocumentFull,
  DocumentSummary,
  PublishedDocument,
  ReviewPayload,
  Step,
  Template,
  VersionMeta,
} from './api.types';
import { SessionStore } from './session.store';
import { WorkspaceStore } from './workspace.store';

export interface DocumentPatch {
  title?: string;
  content?: Partial<Content>;
  owner_id?: string | null;
  folder_id?: string | null;
  requires_ack?: boolean;
  expected_updated_at?: string;
}

@Injectable({ providedIn: 'root' })
export class DocumentApi {
  private readonly http = inject(HttpClient);
  private readonly session = inject(SessionStore);
  private readonly workspace = inject(WorkspaceStore);

  async list(params: {
    space_id?: string;
    folder_id?: string;
    state?: string;
    q?: string;
  }): Promise<DocumentSummary[]> {
    const clean = Object.fromEntries(
      Object.entries(params).filter(([, v]) => v !== undefined && v !== null && v !== ''),
    );
    const res = await firstValueFrom(
      this.http.get<{ data: DocumentSummary[] }>('/api/v1/documents', {
        params: clean as Record<string, string>,
      }),
    );
    return res.data;
  }

  async templates(): Promise<Template[]> {
    const res = await firstValueFrom(this.http.get<{ data: Template[] }>('/api/v1/templates'));
    return res.data;
  }

  async create(body: {
    space_id: string;
    folder_id?: string | null;
    title: string;
    template_id?: string;
    doc_type?: string;
  }): Promise<DocumentFull> {
    await this.session.ensureCsrf();
    const res = await firstValueFrom(
      this.http.post<{ data: DocumentFull }>('/api/v1/documents', body),
    );
    return res.data;
  }

  async get(id: string): Promise<DocumentFull> {
    const res = await firstValueFrom(
      this.http.get<{ data: DocumentFull }>(`/api/v1/documents/${id}`),
    );
    return res.data;
  }

  /**
   * Last-chance save while the page is being hidden or closed (B2-T3). fetch
   * with keepalive survives unload where HttpClient does not; the interceptor
   * is bypassed, so cookies, the XSRF token and X-Workspace-Id are set here.
   * Best-effort: the local backup covers the case where it does not land.
   */
  patchKeepalive(id: string, body: DocumentPatch): void {
    try {
      const xsrf = /(?:^|;\s*)XSRF-TOKEN=([^;]+)/.exec(document.cookie)?.[1];
      const ws = this.workspace.id();
      void fetch(`/api/v1/documents/${id}`, {
        method: 'PATCH',
        keepalive: true,
        credentials: 'include',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
          ...(xsrf ? { 'X-XSRF-TOKEN': decodeURIComponent(xsrf) } : {}),
          ...(ws ? { 'X-Workspace-Id': ws } : {}),
        },
        body: JSON.stringify(body),
      }).catch(() => undefined);
    } catch {
      /* ignore */
    }
  }

  async patch(id: string, body: DocumentPatch): Promise<DocumentFull> {
    await this.session.ensureCsrf();
    const res = await firstValueFrom(
      this.http.patch<{ data: DocumentFull }>(`/api/v1/documents/${id}`, body),
    );
    return res.data;
  }

  async addStep(id: string, body: Partial<Step> & { instruction: string }): Promise<Step> {
    await this.session.ensureCsrf();
    const res = await firstValueFrom(
      this.http.post<{ data: Step }>(`/api/v1/documents/${id}/steps`, body),
    );
    return res.data;
  }

  async patchStep(id: string, stepId: string, body: Partial<Step>): Promise<Step> {
    await this.session.ensureCsrf();
    const res = await firstValueFrom(
      this.http.patch<{ data: Step }>(`/api/v1/documents/${id}/steps/${stepId}`, body),
    );
    return res.data;
  }

  async reorderSteps(id: string, order: string[]): Promise<Step[]> {
    await this.session.ensureCsrf();
    const res = await firstValueFrom(
      this.http.post<{ data: Step[] }>(`/api/v1/documents/${id}/steps/reorder`, { order }),
    );
    return res.data;
  }

  async deleteStep(id: string, stepId: string): Promise<void> {
    await this.session.ensureCsrf();
    await firstValueFrom(this.http.delete(`/api/v1/documents/${id}/steps/${stepId}`));
  }
}

@Injectable({ providedIn: 'root' })
export class GovernanceApi {
  private readonly http = inject(HttpClient);
  private readonly session = inject(SessionStore);

  async published(id: string): Promise<PublishedDocument> {
    const res = await firstValueFrom(
      this.http.get<{ data: PublishedDocument }>(`/api/v1/documents/${id}/published`),
    );
    return res.data;
  }

  async submitCheck(id: string): Promise<string[]> {
    const res = await firstValueFrom(
      this.http.get<{ data: { blockers: string[] } }>(`/api/v1/documents/${id}/submit-check`),
    );
    return res.data.blockers;
  }

  async submit(id: string): Promise<void> {
    await this.session.ensureCsrf();
    await firstValueFrom(this.http.post(`/api/v1/documents/${id}/submit`, {}));
  }

  async review(id: string): Promise<ReviewPayload> {
    const res = await firstValueFrom(
      this.http.get<{ data: ReviewPayload }>(`/api/v1/documents/${id}/review`),
    );
    return res.data;
  }

  async approve(
    id: string,
    changeSummary: string | null,
    expectedUpdatedAt: string,
  ): Promise<{ version_number: number }> {
    await this.session.ensureCsrf();
    const res = await firstValueFrom(
      this.http.post<{ data: { version_number: number } }>(`/api/v1/documents/${id}/approve`, {
        change_summary: changeSummary,
        expected_updated_at: expectedUpdatedAt,
      }),
    );
    return res.data;
  }

  async requestChanges(id: string, comment: string): Promise<void> {
    await this.session.ensureCsrf();
    await firstValueFrom(this.http.post(`/api/v1/documents/${id}/request-changes`, { comment }));
  }

  async archive(id: string): Promise<void> {
    await this.session.ensureCsrf();
    await firstValueFrom(this.http.post(`/api/v1/documents/${id}/archive`, {}));
  }

  async versions(id: string): Promise<VersionMeta[]> {
    const res = await firstValueFrom(
      this.http.get<{ data: VersionMeta[] }>(`/api/v1/documents/${id}/versions`),
    );
    return res.data;
  }

  async diff(id: string, from: string, to: string): Promise<Change[]> {
    const res = await firstValueFrom(
      this.http.get<{ data: { changes: Change[] } }>(`/api/v1/documents/${id}/diff`, {
        params: { from, to },
      }),
    );
    return res.data.changes;
  }

  async restore(id: string, versionId: string): Promise<void> {
    await this.session.ensureCsrf();
    await firstValueFrom(
      this.http.post(`/api/v1/documents/${id}/versions/${versionId}/restore`, {}),
    );
  }
}

export interface LinkTarget {
  id: string;
  title: string;
  state: string;
  doc_type: string;
  published?: boolean;
}

export interface UploadTicket {
  asset_id: string;
  kind: 'image' | 'attachment';
  filename: string;
  upload_url: string;
  method: 'PUT';
  headers: Record<string, string>;
}

/** Epic B completion: images/attachments (B1-T3), workspace templates (B5-T3), internal links and backlinks (B7). */
@Injectable({ providedIn: 'root' })
export class EditorApi {
  private readonly http = inject(HttpClient);
  private readonly session = inject(SessionStore);

  /** Register, PUT directly to storage, then confirm. The API never sees the bytes. */
  async upload(
    documentId: string,
    file: File,
  ): Promise<{ asset_id: string; kind: 'image' | 'attachment'; url: string; filename: string }> {
    await this.session.ensureCsrf();
    const t = (
      await firstValueFrom(
        this.http.post<{ data: UploadTicket }>(`/api/v1/documents/${documentId}/assets`, {
          filename: file.name,
          mime_type: file.type,
          size_bytes: file.size,
        }),
      )
    ).data;
    const put = await fetch(t.upload_url, { method: 'PUT', headers: t.headers, body: file });
    if (!put.ok) throw Object.assign(new Error('upload failed'), { status: put.status });
    const done = (
      await firstValueFrom(
        this.http.post<{ data: { asset_id: string; kind: 'image' | 'attachment'; url: string } }>(
          `/api/v1/documents/${documentId}/assets/${t.asset_id}/complete`,
          {},
        ),
      )
    ).data;
    return { ...done, filename: t.filename };
  }

  async saveTemplate(documentId: string, name: string, description?: string): Promise<Template> {
    await this.session.ensureCsrf();
    return (
      await firstValueFrom(
        this.http.post<{ data: Template }>('/api/v1/templates', {
          document_id: documentId,
          name,
          description,
        }),
      )
    ).data;
  }

  async deleteTemplate(id: string): Promise<void> {
    await this.session.ensureCsrf();
    await firstValueFrom(this.http.delete(`/api/v1/templates/${id}`));
  }

  /** The subset of ids the caller can open. Anything missing is shown as unavailable — deleted and forbidden look the same. */
  async resolveLinks(ids: string[]): Promise<LinkTarget[]> {
    if (!ids.length) return [];
    await this.session.ensureCsrf();
    return (
      await firstValueFrom(
        this.http.post<{ data: LinkTarget[] }>('/api/v1/document-links/resolve', { ids }),
      )
    ).data;
  }

  async backlinks(documentId: string): Promise<LinkTarget[]> {
    return (
      await firstValueFrom(
        this.http.get<{ data: LinkTarget[] }>(`/api/v1/documents/${documentId}/backlinks`),
      )
    ).data;
  }
}
