import { DatePipe, DecimalPipe } from '@angular/common';
import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import { ButtonModule } from 'primeng/button';
import { BillingApi, CounterKey, UsageSummary } from '../../core/billing.api';

/**
 * S22 — Billing & usage. Counts against caps as plain bars; unlimited shows the
 * count and the word, never an empty bar; over-seat and over-limit are plain
 * statements with the action. No colour — a seat count is not a document state.
 */
@Component({
  selector: 'app-billing',
  imports: [DatePipe, DecimalPipe, RouterLink, ButtonModule],
  templateUrl: './billing.html',
  styleUrl: './billing.scss',
})
export class Billing implements OnInit {
  private readonly api = inject(BillingApi);

  readonly usage = signal<UsageSummary | null>(null);
  readonly error = signal<string | null>(null);
  readonly portalMissing = signal(false);
  readonly compare = signal(false);

  readonly rows: { key: CounterKey; label: string; period: 'month' | 'day' | 'total' }[] = [
    { key: 'documents', label: 'Documents', period: 'total' },
    { key: 'sop_generations', label: 'SOP generations', period: 'month' },
    { key: 'recording_minutes', label: 'Recording minutes', period: 'month' },
    { key: 'seats', label: 'Seats', period: 'total' },
    { key: 'chat_queries_per_day', label: 'Assistant questions', period: 'day' },
  ];

  readonly plans: ('free' | 'pro' | 'team')[] = ['free', 'pro', 'team'];
  readonly planNames: Record<'free' | 'pro' | 'team', string> = {
    free: 'Free',
    pro: 'Pro',
    team: 'Team',
  };
  readonly prices: Record<'free' | 'pro' | 'team', string> = {
    free: '€0',
    pro: '€29 / month',
    team: '€99 / month',
  };
  readonly over = computed(() => {
    const u = this.usage();
    if (!u) return [];
    return this.rows.filter(
      (r) =>
        u.counters[r.key].over &&
        !(r.key === 'chat_queries_per_day' && u.counters[r.key].max === 0),
    );
  });

  async ngOnInit(): Promise<void> {
    try {
      this.usage.set(await this.api.usage());
    } catch {
      this.error.set('Usage could not be loaded.');
    }
  }

  pct(key: CounterKey): number {
    const c = this.usage()?.counters[key];
    if (!c || c.max === null || c.max === 0) return 0;
    return Math.min(100, Math.round((c.used / c.max) * 100));
  }

  limitText(v: number | null, key: string): string {
    if (v === null) return 'Unlimited';
    if (key === 'chat_queries_per_day') return v === 0 ? 'Not included' : `${v} / day`;
    return String(v);
  }

  async changePlan(): Promise<void> {
    try {
      const url = await this.api.portal();
      if (url) window.open(url, '_blank', 'noopener');
      else this.portalMissing.set(true);
    } catch {
      this.error.set('The billing portal could not be opened.');
    }
  }
}
