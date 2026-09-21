import { DatePipe } from '@angular/common';
import { Component, OnInit, computed, effect, inject, input, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Router, RouterLink } from '@angular/router';
import { ButtonModule } from 'primeng/button';
import { InputTextModule } from 'primeng/inputtext';
import { SelectModule } from 'primeng/select';
import { Citation, DocType, SearchResponse, Space } from '../../core/api.types';
import { SearchApi, citationFragment } from '../../core/retrieval.api';
import { SessionStore } from '../../core/session.store';
import { SpaceApi } from '../../core/workspace.api';

/**
 * S14 — Search results. Instant answer (with citations) above the grouped
 * results when confidence allows; filters by space and document type; the
 * zero-result state points at Ask, and at Record for people who can record.
 * Everything shown is approved content the caller can access — the server
 * scopes, the UI only renders (non-negotiable 3 and 4).
 */
@Component({
  selector: 'app-search',
  imports: [DatePipe, FormsModule, RouterLink, ButtonModule, InputTextModule, SelectModule],
  templateUrl: './search.html',
  styleUrl: './search.scss',
})
export class Search implements OnInit {
  private readonly api = inject(SearchApi);
  private readonly spaceApi = inject(SpaceApi);
  private readonly session = inject(SessionStore);
  private readonly router = inject(Router);

  /** Bound from ?q= by withComponentInputBinding. */
  readonly q = input<string>('');
  readonly query = signal('');
  readonly spaceId = signal<string | null>(null);
  readonly docType = signal<DocType | null>(null);
  readonly spaces = signal<Space[]>([]);
  readonly result = signal<SearchResponse | null>(null);
  readonly loading = signal(false);
  readonly error = signal<string | null>(null);
  readonly canRecord = computed(() =>
    ['admin', 'approver', 'editor'].includes(this.session.current()?.role ?? ''),
  );

  readonly docTypes: { label: string; value: DocType | null }[] = [
    { label: 'All types', value: null },
    { label: 'SOP', value: 'sop' },
    { label: 'Policy', value: 'policy' },
    { label: 'Handbook page', value: 'handbook_page' },
    { label: 'Note', value: 'note' },
  ];

  constructor() {
    effect(() => {
      const q = this.q();
      if (q && q !== this.query()) {
        this.query.set(q);
        void this.run();
      }
    });
  }

  async ngOnInit(): Promise<void> {
    try {
      this.spaces.set(await this.spaceApi.list());
    } catch {
      this.spaces.set([]);
    }
  }

  async submit(): Promise<void> {
    const q = this.query().trim();
    if (q.length < 2) return;
    await this.router.navigate(['/search'], { queryParams: { q } });
    await this.run();
  }

  async run(): Promise<void> {
    const q = this.query().trim();
    if (q.length < 2) return;
    this.loading.set(true);
    this.error.set(null);
    try {
      this.result.set(
        await this.api.search({
          query: q,
          space_ids: this.spaceId() ? [this.spaceId()!] : undefined,
          filters: { doc_type: this.docType() },
        }),
      );
    } catch {
      this.error.set('Search is unavailable right now.');
    } finally {
      this.loading.set(false);
    }
  }

  fragment(c: Citation | { section_ref: string }): string | undefined {
    return citationFragment(c);
  }

  typeLabel(t: DocType): string {
    return this.docTypes.find((d) => d.value === t)?.label ?? t;
  }
}
