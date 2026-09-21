import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import { Role } from './api.types';
import { SessionStore } from './session.store';

export interface Member { user_id: string; name: string | null; email: string | null; role: Role; joined_at: string | null }
export interface PendingInvite { id: string; email: string; role: Role; expires_at: string; status: string }
export interface SpaceMemberRow { user_id: string; name: string | null; email: string | null; role: Role; source: 'space' | 'workspace' }
export interface ChainEntry { level: 'workspace' | 'space' | 'folder' | 'result'; id: string | null; name: string | null; role: string | null; note?: string | null }
export interface Resolution { user: { id: string; name: string; email: string }; role: string | null; chain: ChainEntry[]; space_member?: boolean; folder_overrides?: { folder: { id: string; name: string } | null; role: string }[] }
export interface FolderAcl {
  folder: { id: string; name: string; space_id: string; parent_id: string | null; depth: number };
  overrides: { user: { id: string; name: string; email: string } | null; role: string; set_by: { id: string; name: string } | null; set_at: string }[];
  effective: { user: { id: string; name: string; email: string }; role: string; source: string | null }[];
}

export const SEATS: Record<string, number | null> = { free: 3, pro: null, team: 10 };

@Injectable({ providedIn: 'root' })
export class AdminApi {
  private readonly http = inject(HttpClient);
  private readonly session = inject(SessionStore);

  async members(wsId: string): Promise<Member[]> {
    return (await firstValueFrom(this.http.get<{ data: Member[] }>(`/api/v1/workspaces/${wsId}/members`))).data;
  }

  async setRole(wsId: string, userId: string, role: Role): Promise<void> {
    await this.session.ensureCsrf();
    await firstValueFrom(this.http.patch(`/api/v1/workspaces/${wsId}/members/${userId}`, { role }));
  }

  async removeMember(wsId: string, userId: string): Promise<void> {
    await this.session.ensureCsrf();
    await firstValueFrom(this.http.delete(`/api/v1/workspaces/${wsId}/members/${userId}`));
  }

  async invites(wsId: string): Promise<PendingInvite[]> {
    return (await firstValueFrom(this.http.get<{ data: PendingInvite[] }>(`/api/v1/workspaces/${wsId}/invites`))).data;
  }

  async resendInvite(wsId: string, inviteId: string): Promise<void> {
    await this.session.ensureCsrf();
    await firstValueFrom(this.http.post(`/api/v1/workspaces/${wsId}/invites/${inviteId}/resend`, {}));
  }

  async revokeInvite(wsId: string, inviteId: string): Promise<void> {
    await this.session.ensureCsrf();
    await firstValueFrom(this.http.delete(`/api/v1/workspaces/${wsId}/invites/${inviteId}`));
  }

  async spaceMembers(spaceId: string): Promise<SpaceMemberRow[]> {
    return (await firstValueFrom(this.http.get<{ data: SpaceMemberRow[] }>(`/api/v1/spaces/${spaceId}/members`))).data;
  }

  async grantSpace(spaceId: string, userId: string, role: Role): Promise<void> {
    await this.session.ensureCsrf();
    await firstValueFrom(this.http.post(`/api/v1/spaces/${spaceId}/members`, { user_id: userId, role }));
  }

  async revokeSpace(spaceId: string, userId: string): Promise<void> {
    await this.session.ensureCsrf();
    await firstValueFrom(this.http.delete(`/api/v1/spaces/${spaceId}/members/${userId}`));
  }

  async spaceAccess(spaceId: string, userId: string): Promise<Resolution> {
    return (await firstValueFrom(this.http.get<{ data: Resolution }>(`/api/v1/spaces/${spaceId}/access`, { params: { user_id: userId } }))).data;
  }

  async folderAcl(folderId: string): Promise<FolderAcl> {
    return (await firstValueFrom(this.http.get<{ data: FolderAcl }>(`/api/v1/folders/${folderId}/permissions`))).data;
  }

  async folderChain(folderId: string, userId: string): Promise<Resolution> {
    return (await firstValueFrom(this.http.get<{ data: Resolution }>(`/api/v1/folders/${folderId}/permissions`, { params: { user_id: userId } }))).data;
  }

  async setFolderOverride(folderId: string, userId: string, role: string): Promise<void> {
    await this.session.ensureCsrf();
    await firstValueFrom(this.http.put(`/api/v1/folders/${folderId}/permissions/${userId}`, { role }));
  }

  async removeFolderOverride(folderId: string, userId: string): Promise<void> {
    await this.session.ensureCsrf();
    await firstValueFrom(this.http.delete(`/api/v1/folders/${folderId}/permissions/${userId}`));
  }
}
