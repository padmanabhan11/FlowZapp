import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { firstValueFrom } from 'rxjs';

export type CounterKey =
  'seats' | 'documents' | 'sop_generations' | 'recording_minutes' | 'chat_queries_per_day';
export interface Counter {
  used: number;
  max: number | null;
  unlimited: boolean;
  near: boolean;
  over: boolean;
}
export interface UsageSummary {
  plan: 'free' | 'pro' | 'team';
  period_start: string;
  period_end: string;
  renews_at: string;
  counters: Record<CounterKey, Counter>;
  plans: Record<'free' | 'pro' | 'team', Record<CounterKey | 'api_per_min', number | null>>;
}

@Injectable({ providedIn: 'root' })
export class BillingApi {
  private readonly http = inject(HttpClient);

  async usage(): Promise<UsageSummary> {
    return (await firstValueFrom(this.http.get<{ data: UsageSummary }>('/api/v1/usage'))).data;
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
