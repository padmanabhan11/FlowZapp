import { DatePipe, DecimalPipe } from '@angular/common';
import { Component, OnDestroy, OnInit, computed, inject, signal } from '@angular/core';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { ButtonModule } from 'primeng/button';
import { Recording, RecordingState } from '../../core/api.types';
import { RecordingApi } from '../../core/recording.api';
import { WorkspaceStore } from '../../core/workspace.store';
import { UploadProgress } from './multipart-upload';
import { RecordModal } from './record-modal';
import { FileMismatchError, ResumableUpload } from './resumable-upload';
import { IdbUploadStore, UploadSession } from './upload-store';

/**
 * S10 — Recordings. Pipeline states are operational, not document states, so
 * they use ink and weight, never the state palette. Progress is determinate
 * with an honest estimate; failures state the cause and offer Retry. In-flight
 * rows are polled every 3 s (03 §7: polling for M1, Reverb from M3).
 */
@Component({
  selector: 'app-recordings',
  imports: [DatePipe, DecimalPipe, ButtonModule, RouterLink, RecordModal],
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
  readonly inFlight = computed(() =>
    this.rows().filter((r) =>
      ['uploaded', 'transcribing', 'segmenting', 'generating'].includes(r.state),
    ),
  );

  private timer: ReturnType<typeof setInterval> | null = null;

  // ---- C3: uploads interrupted by a reload, a closed tab or a lost connection ----
  private readonly store = new IdbUploadStore();
  private readonly uploader = new ResumableUpload(this.api, this.store);
  private readonly workspace = inject(WorkspaceStore);
  readonly unfinished = signal<UploadSession[]>([]);
  readonly resuming = signal<string | null>(null);
  readonly resumeProgress = signal<UploadProgress | null>(null);
  readonly resumeError = signal<Record<string, string>>({});

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
    await this.loadUnfinished();
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

  /** Local sessions whose recording is still pending_upload on the server; anything else is stale and dropped. */
  async loadUnfinished(): Promise<void> {
    const ws = this.workspace.id();
    if (!ws) return;
    const sessions = await this.store.list(ws);
    const pending = new Set(
      this.rows()
        .filter((r) => r.state === 'pending_upload')
        .map((r) => r.id),
    );
    for (const s of sessions)
      if (!pending.has(s.recording_id)) await this.store.delete(s.recording_id);
    this.unfinished.set(sessions.filter((s) => pending.has(s.recording_id)));
  }

  sentPct(s: UploadSession): number {
    const partsDone = Object.keys(s.done).length;
    const total = Math.max(1, Math.ceil(s.size_bytes / (16 * 1024 * 1024)));
    return Math.round((partsDone / total) * 100);
  }

  /** Browser recordings resume straight away (the video is stored locally); file uploads ask for the same file. */
  async resume(s: UploadSession, ev?: Event): Promise<void> {
    const file = ev ? ((ev.target as HTMLInputElement).files?.[0] ?? undefined) : undefined;
    if (s.source === 'file' && !file) return;
    this.resuming.set(s.recording_id);
    this.resumeError.update((m) => ({ ...m, [s.recording_id]: '' }));
    try {
      const rec = await this.uploader.resume(
        s.recording_id,
        (p) => this.resumeProgress.set(p),
        file,
      );
      this.unfinished.update((list) => list.filter((x) => x.recording_id !== s.recording_id));
      this.rows.update((list) => list.map((r) => (r.id === rec.id ? rec : r)));
    } catch (e: unknown) {
      const msg =
        e instanceof FileMismatchError
          ? e.message
          : 'The upload could not continue. Check the connection and try again.';
      this.resumeError.update((m) => ({ ...m, [s.recording_id]: msg }));
    } finally {
      this.resuming.set(null);
      this.resumeProgress.set(null);
    }
  }

  async discardUnfinished(s: UploadSession): Promise<void> {
    if (!confirm(`Discard the unfinished upload “${s.title}”? What was already sent is deleted.`))
      return;
    try {
      await this.uploader.discard(s.recording_id, (id) => this.api.delete(id));
    } catch {
      /* already gone on the server */
    }
    this.unfinished.update((list) => list.filter((x) => x.recording_id !== s.recording_id));
    this.rows.update((list) => list.filter((r) => r.id !== s.recording_id));
  }

  openModal(mode: 'record' | 'upload', title = ''): void {
    this.modalMode.set(mode);
    this.modalTitle.set(title);
    this.modalOpen.set(true);
  }

  onModalClosed(rec: Recording | null): void {
    this.modalOpen.set(false);
    void this.load().then(() => this.loadUnfinished());
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
    if (
      !confirm(
        `Delete “${r.title}”? This also removes its transcript, screenshots and any answers drawn from it. Documents already generated from it are kept.`,
      )
    )
      return;
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
    return `${m}:${Math.round(sec % 60)
      .toString()
      .padStart(2, '0')}`;
  }
}
