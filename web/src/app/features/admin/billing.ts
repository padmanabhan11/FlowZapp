import { DatePipe, DecimalPipe } from '@angular/common';
import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import { ButtonModule } from 'primeng/button';
import { FormsModule } from '@angular/forms';
import { InputNumberModule } from 'primeng/inputnumber';
import { BillingApi, BillingState, CounterKey, Tier, UsageSummary } from '../../core/billing.api';

/**
 * S22 — Billing & usage. Counts against caps as plain bars; unlimited shows the
 * count and the word, never an empty bar; over-seat and over-limit are plain
 * statements with the action. No colour — a seat count is not a document state.
 */
@Component({
  selector: 'app-billing',
  imports: [DatePipe, DecimalPipe, FormsModule, RouterLink, ButtonModule, InputNumberModule],
  templateUrl: './billing.html',
  styleUrl: './billing.scss',
})
export class Billing implements OnInit {
  private readonly api = inject(BillingApi);

  readonly usage = signal<UsageSummary | null>(null);
  readonly error = signal<string | null>(null);
  readonly portalMissing = signal(false);
  readonly compare = signal(false);
  /** K2: the billed plan; null until usage loads. */
  readonly billing = signal<BillingState | null>(null);
  readonly teamSeats = signal(10);
  readonly busy = signal<Tier | 'keep' | null>(null);
  readonly notice = signal<string | null>(null);

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
      const u = await this.api.usage();
      this.usage.set(u);
      this.billing.set(u.billing);
      this.teamSeats.set(u.billing.seats ?? 10);
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

  /** Choose a plan: first payment goes to the provider's checkout; otherwise the change applies (up) or is scheduled (down). */
  async choose(tier: Tier): Promise<void> {
    const b = this.billing();
    if (!b) return;
    if (b.provider === null) {
      this.portalMissing.set(true);
      return;
    }
    this.busy.set(tier);
    this.notice.set(null);
    this.error.set(null);
    try {
      const r = await this.api.change(tier, tier === 'team' ? this.teamSeats() : null);
      if (r.checkout_url) {
        window.location.assign(r.checkout_url);
        return;
      }
      this.billing.set(r);
      this.usage.set(await this.api.usage());
      this.notice.set(
        r.scheduled
          ? `Your plan changes to ${this.planNames[r.scheduled.tier]} at the end of the current period.`
          : `You are now on ${this.planNames[r.tier]}.`,
      );
    } catch (e: unknown) {
      const err = e as { status?: number; error?: { error?: { message?: string } } };
      if (err.status === 501) this.portalMissing.set(true);
      else this.error.set(err.error?.error?.message ?? 'The plan could not be changed.');
    } finally {
      this.busy.set(null);
    }
  }

  async keepCurrent(): Promise<void> {
    this.busy.set('keep');
    try {
      this.billing.set(await this.api.cancelScheduled());
      this.notice.set('Your current plan stays as it is.');
    } catch {
      this.error.set('The scheduled change could not be cancelled.');
    } finally {
      this.busy.set(null);
    }
  }

  isCurrent(tier: Tier): boolean {
    const b = this.billing();
    return !!b && b.tier === tier && (tier !== 'team' || b.seats === this.teamSeats());
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
