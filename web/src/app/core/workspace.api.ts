import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import { InviteResult, InviteRow, Space, WorkspaceSummary } from './api.types';
import { SessionStore } from './session.store';

@Injectable({ providedIn: 'root' })
export class WorkspaceApi {
  private readonly http = inject(HttpClient);
  private readonly session = inject(SessionStore);

  async slugAvailable(slug: string): Promise<{ slug: string; available: boolean }> {
    return firstValueFrom(
      this.http.get<{ slug: string; available: boolean }>('/api/v1/workspaces/slug-available', { params: { slug } }),
    );
  }

  async create(name: string, slug?: string): Promise<WorkspaceSummary> {
    await this.session.ensureCsrf();
    const res = await firstValueFrom(this.http.post<{ data: WorkspaceSummary }>('/api/v1/workspaces', { name, slug }));
    return res.data;
  }

  async spaces(): Promise<Space[]> {
    const res = await firstValueFrom(this.http.get<{ data: Space[] }>('/api/v1/spaces'));
    return res.data;
  }

  async invite(workspaceId: string, invites: InviteRow[]): Promise<InviteResult[]> {
    await this.session.ensureCsrf();
    const res = await firstValueFrom(
      this.http.post<{ data: InviteResult[] }>(`/api/v1/workspaces/${workspaceId}/invites`, { invites }),
    );
    return res.data;
  }
}
