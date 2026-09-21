import { DatePipe, DecimalPipe } from '@angular/common';
import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import { ButtonModule } from 'primeng/button';
import { DocState, KnowledgeGap } from '../../core/api.types';
import { AdminInsightsApi, Overview } from '../../core/admin-insights.api';
import { ChatApi } from '../../core/retrieval.api';

/**
 * S20 — Knowledge gaps & analytics. Gaps lead; analytics sit below — that
 * ordering is the point of the screen. Charts use ink and the state palette
 * only; counts are plain numbers. Grouping is by normalised text; semantic
 * grouping is FR-614 (could).
 */
@Component({
  selector: 'app-knowledge-gaps',
  imports: [DatePipe, DecimalPipe, RouterLink, ButtonModule],
  templateUrl: './knowledge-gaps.html',
  styleUrl: './insights.scss',
})
export class KnowledgeGaps implements OnInit {
  private readonly api = inject(ChatApi);
  private readonly insights = inject(AdminInsightsApi);
  readonly gaps = signal<KnowledgeGap[]>([]);
  readonly overview = signal<Overview | null>(null);
  readonly loading = signal(true);
  readonly error = signal<string | null>(null);

  readonly states: { key: DocState; label: string }[] = [
    { key: 'draft', label: 'Draft' },
    { key: 'in_review', label: 'In review' },
    { key: 'approved', label: 'Approved' },
    { key: 'archived', label: 'Archived' },
  ];
  readonly stateMax = computed(() =>
    Math.max(1, ...Object.values(this.overview()?.documents_by_state ?? { x: 0 })),
  );
  readonly overdueMax = computed(() =>
    Math.max(1, ...(this.overview()?.past_review_by_space.map((s) => s.count) ?? [0])),
  );
  readonly totalDocs = computed(() =>
    Object.values(this.overview()?.documents_by_state ?? {}).reduce((a, b) => a + b, 0),
  );

  async ngOnInit(): Promise<void> {
    try {
      this.gaps.set(await this.api.knowledgeGaps());
    } catch {
      this.error.set('Knowledge gaps could not be loaded.');
    } finally {
      this.loading.set(false);
    }
    try {
      this.overview.set(await this.insights.overview());
    } catch {
      /* analytics are secondary; the gaps still show */
    }
  }

  pct(n: number, max: number): number {
    return Math.round((n / max) * 100);
  }
}
