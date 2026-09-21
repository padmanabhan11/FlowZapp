import { DatePipe } from '@angular/common';
import { Component, OnDestroy, OnInit, computed, inject, signal } from '@angular/core';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { ButtonModule } from 'primeng/button';
import { Recording, RecordingState } from '../../core/api.types';
import { RecordingApi } from '../../core/recording.api';
import { RecordModal } from './record-modal';

/**
 * S10 — Recordings. Pipeline states are operational, not document states, so
 * they use ink and weight, never the state palette. Progress is determinate
 * with an honest estimate; failures state the cause and offer Retry. In-flight
 * rows are polled every 3 s (03 §7: polling for M1, Reverb from M3).
 */
@Component({
  selector: 'app-recordings',
  imports: [DatePipe, ButtonModule, RouterLink, RecordModal],
  templateUrl: './recordings.html',
  styleUrl: './recordings.scss',
})
export class Recordings implements OnInit, OnDestroy {
  private readonly api = inject(RecordingApi);
  private readonly route = inject(ActivatedRoute);

  readonly rows = signal<Recording[]>([]);
  readonly error = signal<string | null>(null);
  readonly modalOpen = signal(false);
  readonly modalMode = signal<'record' | 'upload'>('record');
  readonly modalTitle = signal('');
  readonly inFlight = computed(() => this.rows().filter((r) => ['uploaded', 'transcribing', 'segmenting', 'generating'].includes(r.state)));

  private timer: ReturnType<typeof setInterval> | null = null;

  static readonly STAGES: { key: RecordingState; label: string; pct: number }[] = [
    { key: 'pending_upload', label: 'Uploading', pct: 5 },
    { key: 'uploaded', label: 'Uploaded', pct: 15 },
    { key: 'transcribing', label: 'Transcribing', pct: 40 },
    { key: 'segmenting', label: 'Segmenting', pct: 60 },
    { key: 'generating', label: 'Generating', pct: 85 },
    { key: 'draft_ready', label: 'Draft ready', pct: 100 },
    { key: 'failed', label: 'Failed', pct: 0 },
  ];

  async ngOnInit(): Promise<void> {
    await this.load();
    const q = this.route.snapshot.queryParamMap;
    if (q.get('record') !== null) this.openModal('record', q.get('title') ?? '');
    this.timer = setInterval(() => void this.poll(), 3000);
  }

  ngOnDestroy(): void {
    if (this.timer) clearInterval(this.timer);
  }

  async load(): Promise<void> {
    try {
      this.rows.set(await this.api.list());
    } catch {
      this.error.set('Recordings could not be loaded.');
    }
  }

  private async poll(): Promise<void> {
    const pending = this.inFlight();
    if (!pending.length) return;
    for (const r of pending) {
      try {
        const fresh = await this.api.get(r.id);
        this.rows.update((list) => list.map((x) => (x.id === r.id ? fresh : x)));
      } catch {
        /* next tick */
      }
    }
  }

  openModal(mode: 'record' | 'upload', title = ''): void {
    this.modalMode.set(mode);
    this.modalTitle.set(title);
    this.modalOpen.set(true);
  }

  onModalClosed(rec: Recording | null): void {
    this.modalOpen.set(false);
    if (rec) this.rows.update((list) => [rec, ...list.filter((r) => r.id !== rec.id)]);
  }

  stage(r: Recording) {
    return Recordings.STAGES.find((s) => s.key === r.state) ?? Recordings.STAGES[0];
  }

  async retry(r: Recording): Promise<void> {
    try {
      const fresh = await this.api.retry(r.id);
      this.rows.update((list) => list.map((x) => (x.id === r.id ? fresh : x)));
    } catch {
      this.error.set('Retry could not be started.');
    }
  }

  async generateAgain(r: Recording): Promise<void> {
    try {
      await this.api.generate(r.id);
      await this.load();
    } catch {
      this.error.set('Generation could not be started.');
    }
  }

  async remove(r: Recording): Promise<void> {
    if (!confirm(`Delete “${r.title}”? This also removes its transcript, screenshots and any answers drawn from it. Documents already generated from it are kept.`)) return;
    try {
      await this.api.delete(r.id);
      this.rows.update((list) => list.filter((x) => x.id !== r.id));
    } catch {
      this.error.set('The recording could not be deleted.');
    }
  }

  fmt(sec: number | null): string {
    if (sec === null) return '—';
    const m = Math.floor(sec / 60);
    return `${m}:${Math.round(sec % 60).toString().padStart(2, '0')}`;
  }
}
