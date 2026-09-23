import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import { SessionStore } from './session.store';

export type CounterKey =
  'seats' | 'documents' | 'sop_generations' | 'recording_minutes' | 'chat_queries_per_day';
export interface Counter {
  used: number;
  max: number | null;
  unlimited: boolean;
  near: boolean;
  over: boolean;
}
export type Tier = 'free' | 'pro' | 'team';

/** K2: the billed plan, as GET /billing returns it (also embedded in /usage as `billing`). */
export interface BillingState {
  tier: Tier;
  seats: number | null;
  status: 'active' | 'trialing' | 'past_due' | 'canceled';
  current_period_end: string | null;
  cancel_at_period_end: boolean;
  scheduled: { tier: Tier; seats: number | null; at: string | null } | null;
  /** null when no payment provider is connected: changes go through support (501). */
  provider: string | null;
  has_payment_method: boolean;
  max_team_seats: number | null;
}

export interface UsageSummary {
  plan: 'free' | 'pro' | 'team';
  billing: BillingState;
  period_start: string;
  period_end: string;
  renews_at: string;
  counters: Record<CounterKey, Counter>;
  plans: Record<'free' | 'pro' | 'team', Record<CounterKey | 'api_per_min', number | null>>;
}

@Injectable({ providedIn: 'root' })
export class BillingApi {
  private readonly http = inject(HttpClient);
  private readonly session = inject(SessionStore);

  async usage(): Promise<UsageSummary> {
    return (await firstValueFrom(this.http.get<{ data: UsageSummary }>('/api/v1/usage'))).data;
  }

  /** Upgrades apply at once; downgrades are scheduled; a first payment returns a checkout URL. */
  async change(
    tier: Tier,
    seats: number | null,
  ): Promise<BillingState & { checkout_url: string | null }> {
    await this.session.ensureCsrf();
    return (
      await firstValueFrom(
        this.http.post<{ data: BillingState & { checkout_url: string | null } }>(
          '/api/v1/billing/change',
          { tier, seats },
        ),
      )
    ).data;
  }

  async cancelScheduled(): Promise<BillingState> {
    await this.session.ensureCsrf();
    return (
      await firstValueFrom(this.http.delete<{ data: BillingState }>('/api/v1/billing/scheduled'))
    ).data;
  }

  /** Resolves to the provider-hosted portal URL, or null when billing is not connected (501). */
  async portal(): Promise<string | null> {
    try {
      return (
        await firstValueFrom(this.http.get<{ data: { url: string } }>('/api/v1/billing/portal'))
      ).data.url;
    } catch (e: unknown) {
      if ((e as { status?: number }).status === 501) return null;
      throw e;
    }
  }
}
