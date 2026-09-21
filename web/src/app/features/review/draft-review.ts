import { Component, ElementRef, OnDestroy, OnInit, computed, inject, input, signal, viewChild } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Router, RouterLink } from '@angular/router';
import { ButtonModule } from 'primeng/button';
import { TextareaModule } from 'primeng/textarea';
import { DocumentFull, Recording, Step } from '../../core/api.types';
import { DocumentApi, GovernanceApi } from '../../core/document.api';
import { AssetApi, RecordingApi } from '../../core/recording.api';

/**
 * S9 — Draft review: the wedge screen. Video left, draft right, bound both
 * ways through a scrubber segmented by generated step. Each step shows its
 * source time in mono and a verify control; editing in place counts as
 * verification. Submit stays disabled until every step is verified or edited
 * (FR-315) — a generated SOP nobody checked is the failure mode that would
 * poison every downstream answer.
 */
@Component({
  selector: 'app-draft-review',
  imports: [FormsModule, ButtonModule, TextareaModule, RouterLink],
  templateUrl: './draft-review.html',
  styleUrl: './draft-review.scss',
})
export class DraftReview implements OnInit, OnDestroy {
  private readonly recApi = inject(RecordingApi);
  private readonly docApi = inject(DocumentApi);
  private readonly gov = inject(GovernanceApi);
  private readonly assets = inject(AssetApi);
  private readonly router = inject(Router);

  readonly id = input.required<string>(); // recording id
  readonly video = viewChild<ElementRef<HTMLVideoElement>>('video');

  readonly rec = signal<Recording | null>(null);
  readonly doc = signal<DocumentFull | null>(null);
  readonly steps = signal<Step[]>([]);
  readonly playbackUrl = signal<string | null>(null);
  readonly frames = signal<Record<string, string>>({});
  readonly current = signal(0);          // playhead seconds
  readonly activeStep = signal<string | null>(null);
  readonly error = signal<string | null>(null);
  readonly submitting = signal(false);

  readonly duration = computed(() => this.rec()?.duration_sec || Math.max(...this.steps().map((s) => s.source_ts_end ?? 0), 1));
  readonly verified = computed(() => this.steps().filter((s) => s.verified_at !== null).length);
  readonly allVerified = computed(() => this.steps().length > 0 && this.verified() === this.steps().length);
  readonly lowConfidence = computed(() => this.steps().filter((s) => (s.note ?? '').includes('Low confidence')).map((s) => s.id));
  readonly inFlight = computed(() => ['uploaded', 'transcribing', 'segmenting', 'generating'].includes(this.rec()?.state ?? ''));

  private poll: ReturnType<typeof setInterval> | null = null;

  async ngOnInit(): Promise<void> {
    await this.load();
    this.poll = setInterval(() => {
      if (this.inFlight()) void this.load();
    }, 3000);
  }

  ngOnDestroy(): void {
    if (this.poll) clearInterval(this.poll);
  }

  async load(): Promise<void> {
    try {
      const rec = await this.recApi.get(this.id());
      this.rec.set(rec);
      if (!this.playbackUrl() && rec.state !== 'pending_upload') {
        this.playbackUrl.set(await this.recApi.playbackUrl(rec.id).catch(() => null));
      }
      if (rec.document_id) {
        const doc = await this.docApi.get(rec.document_id);
        this.doc.set(doc);
        this.steps.set(doc.steps);
        void this.loadFrames(doc.steps);
      }
    } catch {
      this.error.set('This recording could not be loaded.');
    }
  }

  private async loadFrames(steps: Step[]): Promise<void> {
    for (const s of steps) {
      if (s.media_asset_id && !this.frames()[s.media_asset_id]) {
        try {
          const url = await this.assets.url(s.media_asset_id);
          this.frames.update((f) => ({ ...f, [s.media_asset_id!]: url }));
        } catch {
          /* frame unavailable; step still reviewable */
        }
      }
    }
  }

  // ---- scrubber ↔ steps -----------------------------------------------
  segmentStyle(s: Step): Record<string, string> {
    const d = this.duration();
    const left = (100 * (s.source_ts_start ?? 0)) / d;
    const width = (100 * ((s.source_ts_end ?? 0) - (s.source_ts_start ?? 0))) / d;
    return { left: `${left}%`, width: `${Math.max(width, 0.5)}%` };
  }

  jumpTo(s: Step): void {
    this.activeStep.set(s.id);
    const v = this.video()?.nativeElement;
    if (v && s.source_ts_start !== null) {
      v.currentTime = s.source_ts_start;
      void v.play().catch(() => undefined);
    }
    document.getElementById(`step-${s.id}`)?.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
  }

  onTimeUpdate(): void {
    const v = this.video()?.nativeElement;
    if (!v) return;
    this.current.set(v.currentTime);
    const hit = this.steps().find((s) => s.source_ts_start !== null && v.currentTime >= s.source_ts_start && v.currentTime < (s.source_ts_end ?? Infinity));
    if (hit && hit.id !== this.activeStep()) this.activeStep.set(hit.id);
  }

  // ---- verification -----------------------------------------------------
  async verify(s: Step): Promise<void> {
    await this.patch(s, {});
  }

  async edit(s: Step, instruction: string): Promise<void> {
    if (instruction.trim() === s.instruction) return;
    await this.patch(s, { instruction: instruction.trim() });
  }

  private async patch(s: Step, body: Partial<Step>): Promise<void> {
    const d = this.doc();
    if (!d) return;
    try {
      const saved = await this.docApi.patchStep(d.id, s.id, body);
      this.steps.update((list) => list.map((x) => (x.id === s.id ? saved : x)));
    } catch {
      this.error.set('The step could not be saved.');
    }
  }

  async regenerate(): Promise<void> {
    const r = this.rec();
    if (!r) return;
    try {
      await this.recApi.generate(r.id);
      await this.load();
    } catch {
      this.error.set('Generation could not be started.');
    }
  }

  async submit(): Promise<void> {
    const d = this.doc();
    if (!d || !this.allVerified()) return;
    this.submitting.set(true);
    try {
      await this.gov.submit(d.id);
      await this.router.navigate(['/d', d.id, 'review']);
    } catch (e: unknown) {
      const err = e as { error?: { error?: { message?: string } } };
      this.error.set(err.error?.error?.message ?? 'The draft could not be submitted.');
    } finally {
      this.submitting.set(false);
    }
  }

  fmt(sec: number | null): string {
    if (sec === null) return '';
    const m = Math.floor(sec / 60);
    return `${m}:${Math.floor(sec % 60).toString().padStart(2, '0')}`;
  }
}
