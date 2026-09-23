import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import { Folder, InviteResult, InviteRow, Space, WorkspaceSummary } from './api.types';
import { SessionStore } from './session.store';

@Injectable({ providedIn: 'root' })
export class WorkspaceApi {
  private readonly http = inject(HttpClient);
  private readonly session = inject(SessionStore);

  async slugAvailable(slug: string): Promise<{ slug: string; available: boolean }> {
    return firstValueFrom(
      this.http.get<{ slug: string; available: boolean }>('/api/v1/workspaces/slug-available', {
        params: { slug },
      }),
    );
  }

  async create(name: string, slug?: string): Promise<WorkspaceSummary> {
    await this.session.ensureCsrf();
    const res = await firstValueFrom(
      this.http.post<{ data: WorkspaceSummary }>('/api/v1/workspaces', { name, slug }),
    );
    return res.data;
  }

  async spaces(): Promise<Space[]> {
    const res = await firstValueFrom(this.http.get<{ data: Space[] }>('/api/v1/spaces'));
    return res.data;
  }

  async invite(workspaceId: string, invites: InviteRow[]): Promise<InviteResult[]> {
    await this.session.ensureCsrf();
    const res = await firstValueFrom(
      this.http.post<{ data: InviteResult[] }>(`/api/v1/workspaces/${workspaceId}/invites`, {
        invites,
      }),
    );
    return res.data;
  }
}

@Injectable({ providedIn: 'root' })
export class SpaceApi {
  private readonly http = inject(HttpClient);
  private readonly session = inject(SessionStore);

  async list(): Promise<Space[]> {
    const res = await firstValueFrom(this.http.get<{ data: Space[] }>('/api/v1/spaces'));
    return res.data;
  }

  async get(id: string): Promise<Space> {
    const res = await firstValueFrom(this.http.get<{ data: Space }>(`/api/v1/spaces/${id}`));
    return res.data;
  }

  async folders(spaceId: string): Promise<Folder[]> {
    const res = await firstValueFrom(
      this.http.get<{ data: Folder[] }>('/api/v1/folders', { params: { space_id: spaceId } }),
    );
    return res.data;
  }

  async createFolder(spaceId: string, name: string, parentId: string | null): Promise<Folder> {
    await this.session.ensureCsrf();
    const res = await firstValueFrom(
      this.http.post<{ data: Folder }>('/api/v1/folders', {
        space_id: spaceId,
        name,
        parent_id: parentId,
      }),
    );
    return res.data;
  }

  async renameFolder(id: string, name: string): Promise<Folder> {
    await this.session.ensureCsrf();
    const res = await firstValueFrom(
      this.http.patch<{ data: Folder }>(`/api/v1/folders/${id}`, { name }),
    );
    return res.data;
  }

  async deleteFolder(
    id: string,
    strategy: 'archive' | 'move',
    targetFolderId?: string | null,
  ): Promise<void> {
    await this.session.ensureCsrf();
    const params: Record<string, string> = { strategy };
    if (targetFolderId) params['target_folder_id'] = targetFolderId;
    await firstValueFrom(this.http.delete(`/api/v1/folders/${id}`, { params }));
  }
}
