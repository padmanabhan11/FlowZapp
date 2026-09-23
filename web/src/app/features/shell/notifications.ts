import { DatePipe } from '@angular/common';
import { Component, OnDestroy, OnInit, inject, signal } from '@angular/core';
import { Router } from '@angular/router';
import { ButtonModule } from 'primeng/button';
import { AccountApi, AppNotification } from '../../core/account.api';

/** In-app notification list for the shell (doc 11 triggers). Polls quietly; a count, not a badge colour. */
@Component({
  selector: 'app-notifications',
  imports: [DatePipe, ButtonModule],
  templateUrl: './notifications.html',
  styleUrl: './notifications.scss',
})
export class Notifications implements OnInit, OnDestroy {
  private readonly api = inject(AccountApi);
  private readonly router = inject(Router);
  private timer: ReturnType<typeof setInterval> | null = null;

  readonly open = signal(false);
  readonly items = signal<AppNotification[]>([]);
  readonly unread = signal(0);

  async ngOnInit(): Promise<void> {
    await this.refresh();
    this.timer = setInterval(() => void this.refresh(), 60_000);
  }

  ngOnDestroy(): void {
    if (this.timer) clearInterval(this.timer);
  }

  async refresh(): Promise<void> {
    try {
      const r = await this.api.notifications();
      this.items.set(r.items);
      this.unread.set(r.unread);
    } catch {
      /* the bell is never load-bearing */
    }
  }

  toggle(): void {
    this.open.update((o) => !o);
  }

  async openItem(n: AppNotification): Promise<void> {
    if (!n.read_at) {
      try {
        this.unread.set(await this.api.markRead([n.id]));
        this.items.update((list) =>
          list.map((x) => (x.id === n.id ? { ...x, read_at: new Date().toISOString() } : x)),
        );
      } catch {
        /* ignore */
      }
    }
    this.open.set(false);
    const target = Notifications.route(n);
    if (target) await this.router.navigate(target.path, target.extras);
  }

  async markAll(): Promise<void> {
    try {
      this.unread.set(await this.api.markRead());
      this.items.update((list) =>
        list.map((x) => ({ ...x, read_at: x.read_at ?? new Date().toISOString() })),
      );
    } catch {
      /* ignore */
    }
  }

  label(n: AppNotification): string {
    const d = n.data;
    switch (d.type) {
      case 'review_requested':
        return `${d.by ?? 'Someone'} submitted “${d.title}” for review`;
      case 'changes_requested':
        return `Changes requested on “${d.title}”`;
      case 'document_approved':
        return `“${d.title}” was approved (v${d.version})`;
      case 'acknowledgement_due':
        return `Please read and acknowledge “${d.title}” (v${d.version})`;
      case 'acknowledgement_reminder':
        return `Reminder: acknowledge “${d.title}” (v${d.version})`;
      case 'review_due':
        return `“${d.title}” is due for review`;
      case 'recording_draft_ready':
        return `Draft ready: “${d.title}”`;
      case 'recording_failed':
        return `Recording failed: ${d.reason ?? d.title}`;
      case 'index_unhealthy':
        return d.count && d.count > 1
          ? `${d.count} approved documents are missing from search`
          : `“${d.title}” is approved but missing from search`;
      default:
        return d.title ?? d.type;
    }
  }

  static route(n: AppNotification): { path: unknown[]; extras?: Record<string, unknown> } | null {
    const d = n.data;
    switch (d.type) {
      case 'review_requested':
        return { path: ['/d', d.document_id, 'review'] };
      case 'changes_requested':
        return { path: ['/d', d.document_id, 'edit'] };
      case 'document_approved':
      case 'review_due':
        return { path: ['/d', d.document_id] };
      case 'acknowledgement_due':
      case 'acknowledgement_reminder':
        return { path: ['/handbook', d.document_id] };
      case 'index_unhealthy':
        return { path: ['/admin/insights'] };
      case 'recording_draft_ready':
        return { path: ['/r', d.recording_id, 'draft'] };
      case 'recording_failed':
        return { path: ['/recordings'] };
      default:
        return null;
    }
  }
}
