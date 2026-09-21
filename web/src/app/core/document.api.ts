import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import { Content, DocumentFull, DocumentSummary, Step, Template } from './api.types';
import { SessionStore } from './session.store';

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

  async list(params: { space_id?: string; folder_id?: string; state?: string; q?: string }): Promise<DocumentSummary[]> {
    const clean = Object.fromEntries(Object.entries(params).filter(([, v]) => v !== undefined && v !== null && v !== ''));
    const res = await firstValueFrom(this.http.get<{ data: DocumentSummary[] }>('/api/v1/documents', { params: clean as Record<string, string> }));
    return res.data;
  }

  async templates(): Promise<Template[]> {
    const res = await firstValueFrom(this.http.get<{ data: Template[] }>('/api/v1/templates'));
    return res.data;
  }

  async create(body: { space_id: string; folder_id?: string | null; title: string; template_id?: string; doc_type?: string }): Promise<DocumentFull> {
    await this.session.ensureCsrf();
    const res = await firstValueFrom(this.http.post<{ data: DocumentFull }>('/api/v1/documents', body));
    return res.data;
  }

  async get(id: string): Promise<DocumentFull> {
    const res = await firstValueFrom(this.http.get<{ data: DocumentFull }>(`/api/v1/documents/${id}`));
    return res.data;
  }

  async patch(id: string, body: DocumentPatch): Promise<DocumentFull> {
    await this.session.ensureCsrf();
    const res = await firstValueFrom(this.http.patch<{ data: DocumentFull }>(`/api/v1/documents/${id}`, body));
    return res.data;
  }

  async addStep(id: string, body: Partial<Step> & { instruction: string }): Promise<Step> {
    await this.session.ensureCsrf();
    const res = await firstValueFrom(this.http.post<{ data: Step }>(`/api/v1/documents/${id}/steps`, body));
    return res.data;
  }

  async patchStep(id: string, stepId: string, body: Partial<Step>): Promise<Step> {
    await this.session.ensureCsrf();
    const res = await firstValueFrom(this.http.patch<{ data: Step }>(`/api/v1/documents/${id}/steps/${stepId}`, body));
    return res.data;
  }

  async reorderSteps(id: string, order: string[]): Promise<Step[]> {
    await this.session.ensureCsrf();
    const res = await firstValueFrom(this.http.post<{ data: Step[] }>(`/api/v1/documents/${id}/steps/reorder`, { order }));
    return res.data;
  }

  async deleteStep(id: string, stepId: string): Promise<void> {
    await this.session.ensureCsrf();
    await firstValueFrom(this.http.delete(`/api/v1/documents/${id}/steps/${stepId}`));
  }
}
