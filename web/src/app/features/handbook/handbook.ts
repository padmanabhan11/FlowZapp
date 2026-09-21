import { DatePipe } from '@angular/common';
import { Component, OnInit, computed, effect, inject, input, signal } from '@angular/core';
import { Router, RouterLink } from '@angular/router';
import { ButtonModule } from 'primeng/button';
import { AckReceipt, HandbookApi, HandbookEntry, HandbookView } from '../../core/handbook.api';
import { SessionStore } from '../../core/session.store';
import { Reader } from '../reader/reader';

/**
 * S16 — Handbook reader. A persistent, ordered contents rail; the document
 * body is the same reader as S7; a sticky acknowledgement bar appears only
 * for people assigned to acknowledge and only until they have acknowledged
 * the current version. After acknowledging, a quiet control line. No
 * celebration (S16 rules).
 */
@Component({
  selector: 'app-handbook',
  imports: [DatePipe, RouterLink, ButtonModule, Reader],
  templateUrl: './handbook.html',
  styleUrl: './handbook.scss',
})
export class Handbook implements OnInit {
  private readonly api = inject(HandbookApi);
  private readonly session = inject(SessionStore);
  private readonly router = inject(Router);

  /** Route param /handbook/:docId (optional). */
  readonly docId = input<string>('');
  readonly view = signal<HandbookView | null>(null);
  readonly error = signal<string | null>(null);
  readonly busy = signal(false);
  readonly receipt = signal<AckReceipt | null>(null);
  readonly reordering = signal(false);
  readonly current = computed<HandbookEntry | null>(() => {
    const v = this.view();
    if (!v || v.documents.length === 0) return null;
    return v.documents.find((d) => d.id === this.docId()) ?? v.documents[0];
  });
  readonly index = computed(() => {
    const c = this.current();
    return c ? (this.view()?.documents.findIndex((d) => d.id === c.id) ?? -1) : -1;
  });
  readonly next = computed(() => this.view()?.documents[this.index() + 1] ?? null);
  readonly prev = computed(() =>
    this.index() > 0 ? (this.view()?.documents[this.index() - 1] ?? null) : null,
  );
  readonly outstanding = computed(
    () =>
      this.view()?.documents.filter((d) => d.ack_required_from_me && !d.acknowledged_at).length ??
      0,
  );
  readonly user = this.session.user;

  constructor() {
    effect(() => {
      this.docId();
      this.receipt.set(null);
    });
  }

  async ngOnInit(): Promise<void> {
    await this.load();
  }

  async load(): Promise<void> {
    try {
      this.view.set(await this.api.view());
    } catch {
      this.error.set('The handbook could not be loaded.');
    }
  }

  async acknowledge(entry: HandbookEntry): Promise<void> {
    this.busy.set(true);
    try {
      const r = await this.api.acknowledge(entry.id);
      this.receipt.set(r);
      this.view.update((v) =>
        v
          ? {
              ...v,
              documents: v.documents.map((d) =>
                d.id === entry.id ? { ...d, acknowledged_at: r.acknowledged_at } : d,
              ),
            }
          : v,
      );
    } catch {
      this.error.set('Your acknowledgement could not be recorded. Try again.');
    } finally {
      this.busy.set(false);
    }
  }

  async move(entry: HandbookEntry, delta: -1 | 1): Promise<void> {
    const v = this.view();
    if (!v?.space) return;
    const ids = v.documents.map((d) => d.id);
    const i = ids.indexOf(entry.id);
    const j = i + delta;
    if (j < 0 || j >= ids.length) return;
    [ids[i], ids[j]] = [ids[j], ids[i]];
    try {
      this.view.set(await this.api.reorder(v.space.id, ids));
    } catch {
      this.error.set('The order could not be saved.');
    }
  }

  open(entry: HandbookEntry): void {
    void this.router.navigate(['/handbook', entry.id]);
  }
}
