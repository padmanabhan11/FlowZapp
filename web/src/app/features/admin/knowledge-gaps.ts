import { DatePipe } from '@angular/common';
import { Component, OnInit, inject, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import { ButtonModule } from 'primeng/button';
import { KnowledgeGap } from '../../core/api.types';
import { ChatApi } from '../../core/retrieval.api';

/**
 * S20 — Knowledge gaps: questions the assistant refused, ranked by how often
 * they were asked (H6). Each row is a prompt to record the missing procedure.
 * Grouping is by normalised text; semantic grouping is FR-614 (could).
 */
@Component({
  selector: 'app-knowledge-gaps',
  imports: [DatePipe, RouterLink, ButtonModule],
  templateUrl: './knowledge-gaps.html',
  styleUrl: './admin.scss',
})
export class KnowledgeGaps implements OnInit {
  private readonly api = inject(ChatApi);
  readonly gaps = signal<KnowledgeGap[]>([]);
  readonly loading = signal(true);
  readonly error = signal<string | null>(null);

  async ngOnInit(): Promise<void> {
    try {
      this.gaps.set(await this.api.knowledgeGaps());
    } catch {
      this.error.set('Knowledge gaps could not be loaded.');
    } finally {
      this.loading.set(false);
    }
  }
}
