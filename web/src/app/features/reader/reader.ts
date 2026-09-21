import { DatePipe } from '@angular/common';
import { Component, OnInit, computed, inject, input, signal } from '@angular/core';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { ButtonModule } from 'primeng/button';
import { PublishedDocument, Step } from '../../core/api.types';
import { DocumentApi, GovernanceApi } from '../../core/document.api';
import { AssetApi } from '../../core/recording.api';
import { SessionStore } from '../../core/session.store';

/**
 * S7 — Document reader: the approved artifact. Control block above the title,
 * mirroring a controlled document; the state pill is the first coloured thing
 * on the page. Critical steps carry an amber left rule (the one permitted
 * non-state colour meaning). Checkpoints are local, never persisted.
 */
@Component({
  selector: 'app-reader',
  imports: [DatePipe, ButtonModule, RouterLink],
  templateUrl: './reader.html',
  styleUrl: './reader.scss',
})
export class Reader implements OnInit {
  private readonly gov = inject(GovernanceApi);
  private readonly docs = inject(DocumentApi);
  private readonly assets = inject(AssetApi);
  private readonly session = inject(SessionStore);
  private readonly router = inject(Router);
  private readonly route = inject(ActivatedRoute);

  readonly id = input.required<string>();
  readonly doc = signal<PublishedDocument | null>(null);
  readonly frames = signal<Record<string, string>>({});
  readonly checked = signal<Set<string>>(new Set());
  readonly notPublished = signal(false);
  readonly error = signal<string | null>(null);
  readonly canEdit = computed(() => ['admin', 'approver', 'editor'].includes(this.session.current()?.role ?? ''));
  readonly hasDraft = computed(() => this.doc()?.state === 'draft' || this.doc()?.state === 'in_review');

  async ngOnInit(): Promise<void> {
    try {
      const d = await this.gov.published(this.id());
      this.doc.set(d);
      for (const s of d.steps) {
        if (s.media_asset_id) this.assets.url(s.media_asset_id).then((u) => this.frames.update((f) => ({ ...f, [s.media_asset_id!]: u }))).catch(() => undefined);
      }
      this.scrollToCitation();
    } catch (e: unknown) {
      const err = e as { status?: number };
      if (err.status === 409) {
        // No approved version yet: editors go to the draft, readers see a plain message.
        if (this.canEdit()) void this.router.navigate(['/d', this.id(), 'edit']);
        else this.notPublished.set(true);
      } else {
        this.error.set('This document could not be loaded.');
      }
    }
  }

  /** Citation deep links (S14/S15) arrive as #step-N / #section-x; the content renders after load, so scroll once it exists. */
  private scrollToCitation(): void {
    const fragment = this.route.snapshot.fragment;
    if (!fragment) return;
    setTimeout(() => {
      const el = document.getElementById(fragment);
      if (el) {
        el.scrollIntoView({ block: 'start' });
        el.classList.add('is-cited');
      }
    });
  }

  toggle(s: Step): void {
    this.checked.update((set) => {
      const n = new Set(set);
      if (n.has(s.id)) n.delete(s.id);
      else n.add(s.id);
      return n;
    });
  }

  stateLabel(state: string): string {
    return { draft: 'Draft', in_review: 'In review', approved: 'Approved', archived: 'Archived' }[state] ?? state;
  }
}
