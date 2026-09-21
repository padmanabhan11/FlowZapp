import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import { SessionStore } from './session.store';

export type PrefKey =
  | 'review_requested'
  | 'changes_requested'
  | 'document_approved'
  | 'acknowledgement_due'
  | 'recording_failed'
  | 'weekly_digest';
export interface NotificationPrefs {
  prefs: Record<PrefKey, boolean>;
  locked: PrefKey[];
}
export interface ActiveSession {
  id: string;
  ip: string | null;
  device: string;
  last_seen_at: string;
  current: boolean;
}
export interface AppNotification {
  id: string;
  data: {
    type: string;
    title?: string;
    document_id?: string;
    recording_id?: string;
    reason?: string;
    comment?: string;
    by?: string;
    version?: number;
    due_at?: string;
  };
  read_at: string | null;
  created_at: string;
}
export interface WorkspaceDetail {
  id: string;
  name: string;
  slug: string;
  plan: 'free' | 'pro' | 'team';
  settings: {
    self_approval?: boolean;
    review_cadence_months?: number | null;
    retain_recordings?: boolean;
    default_language?: string;
  };
  deletion_scheduled_at: string | null;
}

@Injectable({ providedIn: 'root' })
export class AccountApi {
  private readonly http = inject(HttpClient);
  private readonly session = inject(SessionStore);

  async updateProfile(body: { name?: string; locale?: string }): Promise<void> {
    await this.session.ensureCsrf();
    await firstValueFrom(this.http.patch('/api/v1/account', body));
  }

  async notificationPrefs(): Promise<NotificationPrefs> {
    return (
      await firstValueFrom(
        this.http.get<{ data: NotificationPrefs }>('/api/v1/account/notifications'),
      )
    ).data;
  }

  async updateNotificationPrefs(
    body: Partial<Record<PrefKey, boolean>>,
  ): Promise<NotificationPrefs> {
    await this.session.ensureCsrf();
    return (
      await firstValueFrom(
        this.http.patch<{ data: NotificationPrefs }>('/api/v1/account/notifications', body),
      )
    ).data;
  }

  async sessions(): Promise<ActiveSession[]> {
    return (await firstValueFrom(this.http.get<{ data: ActiveSession[] }>('/api/v1/auth/sessions')))
      .data;
  }

  async revokeSession(id: string): Promise<void> {
    await this.session.ensureCsrf();
    await firstValueFrom(this.http.delete(`/api/v1/auth/sessions/${id}`));
  }

  async notifications(unreadOnly = false): Promise<{ items: AppNotification[]; unread: number }> {
    const res = await firstValueFrom(
      this.http.get<{ data: AppNotification[]; unread_count: number }>('/api/v1/notifications', {
        params: unreadOnly ? { unread: 1 } : {},
      }),
    );
    return { items: res.data, unread: res.unread_count };
  }

  async markRead(ids?: string[]): Promise<number> {
    await this.session.ensureCsrf();
    return (
      await firstValueFrom(
        this.http.post<{ data: { unread_count: number } }>('/api/v1/notifications/read', { ids }),
      )
    ).data.unread_count;
  }

  async workspace(id: string): Promise<WorkspaceDetail> {
    return (
      await firstValueFrom(this.http.get<{ data: WorkspaceDetail }>(`/api/v1/workspaces/${id}`))
    ).data;
  }

  async updateWorkspace(
    id: string,
    body: { name?: string; settings?: WorkspaceDetail['settings'] },
  ): Promise<WorkspaceDetail> {
    await this.session.ensureCsrf();
    return (
      await firstValueFrom(
        this.http.patch<{ data: WorkspaceDetail }>(`/api/v1/workspaces/${id}`, body),
      )
    ).data;
  }

  async scheduleDeletion(
    id: string,
    confirmName: string,
  ): Promise<{ deletion_scheduled_at: string; grace_days: number }> {
    await this.session.ensureCsrf();
    return (
      await firstValueFrom(
        this.http.delete<{ data: { deletion_scheduled_at: string; grace_days: number } }>(
          `/api/v1/workspaces/${id}`,
          { body: { confirm_name: confirmName } },
        ),
      )
    ).data;
  }

  async cancelDeletion(id: string): Promise<void> {
    await this.session.ensureCsrf();
    await firstValueFrom(this.http.post(`/api/v1/workspaces/${id}/cancel-deletion`, {}));
  }
}
