import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import { BillingApi, CounterKey, UsageSummary } from '../../core/billing.api';
import { SessionStore } from '../../core/session.store';

/** S22 "Approaching a limit — surfaced in the app shell, not as a modal." One line, ink only. */
@Component({
  selector: 'app-limit-notice',
  imports: [RouterLink],
  template: `
    @if (line(); as l) {
      <p class="limit-notice" role="status">
        {{ l }}
        @if (isAdmin()) {
          <a routerLink="/admin/billing">See usage</a>
        }
      </p>
    }
  `,
  styles: `
    .limit-notice {
      margin: 0;
      padding: 6px 16px;
      background: var(--paper);
      border-bottom: 1px solid var(--line);
      color: var(--ink-soft);
      font-size: 0.9rem;
      a {
        margin-left: 6px;
      }
    }
  `,
})
export class LimitNotice implements OnInit {
  private readonly api = inject(BillingApi);
  private readonly session = inject(SessionStore);
  readonly usage = signal<UsageSummary | null>(null);
  readonly isAdmin = computed(() => this.session.current()?.role === 'admin');

  private static readonly LABELS: Record<CounterKey, string> = {
    documents: 'documents',
    sop_generations: 'SOP generations this month',
    recording_minutes: 'recording minutes this month',
    seats: 'seats',
    chat_queries_per_day: 'assistant questions today',
  };

  readonly line = computed(() => {
    const u = this.usage();
    if (!u) return null;
    for (const key of [
      'sop_generations',
      'recording_minutes',
      'documents',
      'seats',
      'chat_queries_per_day',
    ] as CounterKey[]) {
      const c = u.counters[key];
      if (c.max === null || c.max === 0) continue;
      if (c.over)
        return `You have used all ${c.max} ${LimitNotice.LABELS[key]} on the ${u.plan} plan.`;
      if (c.near) return `${Math.round(c.used)} of ${c.max} ${LimitNotice.LABELS[key]} used.`;
    }
    return null;
  });

  async ngOnInit(): Promise<void> {
    try {
      this.usage.set(await this.api.usage());
    } catch {
      this.usage.set(null);
    }
  }
}
