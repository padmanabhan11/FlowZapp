import { DecimalPipe } from '@angular/common';
import { Component, computed, inject, input, output, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ButtonModule } from 'primeng/button';
import { DialogModule } from 'primeng/dialog';
import { InputTextModule } from 'primeng/inputtext';
import { SelectModule } from 'primeng/select';
import { Recording, Space } from '../../core/api.types';
import { RecordingApi } from '../../core/recording.api';
import { SpaceApi } from '../../core/workspace.api';
import { uploadParts } from './multipart-upload';

type Mode = 'choose' | 'primer' | 'recording' | 'details' | 'uploading' | 'done';

const LIMITS = { seconds: 60 * 60, bytes: 2 * 1024 * 1024 * 1024 };

/**
 * S11 — Record / upload. Two paths. The permission primer comes before the
 * browser prompt because the browser's own dialog is where most users abandon.
 * During recording only a floating pill: elapsed time, pause, stop. Upload is
 * chunked, resumable, and the tab can be closed once it completes.
 */
@Component({
  selector: 'app-record-modal',
  imports: [DecimalPipe, FormsModule, ButtonModule, DialogModule, InputTextModule, SelectModule],
  templateUrl: './record-modal.html',
  styleUrl: './record-modal.scss',
})
export class RecordModal {
  private readonly api = inject(RecordingApi);
  private readonly spaceApi = inject(SpaceApi);

  readonly visible = input(false);
  readonly initialMode = input<'record' | 'upload'>('record');
  readonly initialTitle = input<string>('');
  readonly defaultSpaceId = input<string | null>(null);
  readonly closed = output<Recording | null>();

  readonly mode = signal<Mode>('choose');
  readonly spaces = signal<Space[]>([]);
  readonly spaceId = signal<string | null>(null);
  readonly title = signal('');
  readonly file = signal<Blob | null>(null);
  readonly fileName = signal('recording.webm');
  readonly mimeType = signal('video/webm');
  readonly durationSec = signal<number | null>(null);
  readonly elapsed = signal(0);
  readonly paused = signal(false);
  readonly progress = signal({ sent: 0, total: 0, retrying: false });
  readonly error = signal<string | null>(null);
  readonly result = signal<Recording | null>(null);
  readonly percent = computed(() => (this.progress().total ? Math.round((100 * this.progress().sent) / this.progress().total) : 0));
  readonly captureSupported = typeof navigator !== 'undefined' && !!navigator.mediaDevices?.getDisplayMedia && typeof MediaRecorder !== 'undefined';

  private recorder: MediaRecorder | null = null;
  private chunks: Blob[] = [];
  private streams: MediaStream[] = [];
  private tick: ReturnType<typeof setInterval> | null = null;
  private startedAt = 0;

  async open(): Promise<void> {
    this.reset();
    this.title.set(this.initialTitle());
    this.spaceId.set(this.defaultSpaceId());
    try {
      this.spaces.set(await this.spaceApi.list());
      if (!this.spaceId() && this.spaces().length) this.spaceId.set(this.spaces()[0].id);
    } catch {
      this.spaces.set([]);
    }
    this.mode.set(this.initialMode() === 'upload' ? 'choose' : 'primer');
  }

  reset(): void {
    this.stopStreams();
    this.mode.set('choose');
    this.file.set(null);
    this.durationSec.set(null);
    this.elapsed.set(0);
    this.paused.set(false);
    this.progress.set({ sent: 0, total: 0, retrying: false });
    this.error.set(null);
    this.result.set(null);
  }

  close(): void {
    if (this.mode() === 'recording') return; // stop first
    const r = this.result();
    this.reset();
    this.closed.emit(r);
  }

  // ---- record path ----------------------------------------------------
  async startRecording(): Promise<void> {
    this.error.set(null);
    try {
      const screen = await navigator.mediaDevices.getDisplayMedia({ video: { frameRate: 10 }, audio: false });
      let mic: MediaStream | null = null;
      try {
        mic = await navigator.mediaDevices.getUserMedia({ audio: { echoCancellation: true, noiseSuppression: true } });
      } catch {
        screen.getTracks().forEach((t) => t.stop());
        this.error.set('Your browser blocked the microphone. Allow it in site settings, or upload a file instead.');
        return;
      }
      const stream = new MediaStream([...screen.getVideoTracks(), ...mic.getAudioTracks()]);
      this.streams = [screen, mic];
      const mime = ['video/webm;codecs=vp9,opus', 'video/webm;codecs=vp8,opus', 'video/webm'].find((m) => MediaRecorder.isTypeSupported(m)) ?? '';
      this.recorder = new MediaRecorder(stream, mime ? { mimeType: mime, videoBitsPerSecond: 2_500_000 } : undefined);
      this.chunks = [];
      this.recorder.ondataavailable = (e) => e.data.size && this.chunks.push(e.data);
      this.recorder.onstop = () => this.finishRecording();
      screen.getVideoTracks()[0].addEventListener('ended', () => this.stopRecording()); // user hit the browser's "Stop sharing"
      this.recorder.start(1000);
      this.startedAt = Date.now();
      this.elapsed.set(0);
      this.tick = setInterval(() => {
        if (!this.paused()) this.elapsed.set(Math.floor((Date.now() - this.startedAt) / 1000));
        if (this.elapsed() >= LIMITS.seconds) this.stopRecording();
      }, 500);
      this.mode.set('recording');
    } catch {
      this.error.set('Your browser blocked screen sharing. Enable it in site settings, or upload a file instead.');
    }
  }

  togglePause(): void {
    if (!this.recorder) return;
    if (this.paused()) {
      this.recorder.resume();
      this.startedAt = Date.now() - this.elapsed() * 1000;
      this.paused.set(false);
    } else {
      this.recorder.pause();
      this.paused.set(true);
    }
  }

  stopRecording(): void {
    if (this.recorder && this.recorder.state !== 'inactive') this.recorder.stop();
    else this.finishRecording();
  }

  private finishRecording(): void {
    if (this.tick) clearInterval(this.tick);
    this.tick = null;
    this.stopStreams();
    if (this.chunks.length) {
      const blob = new Blob(this.chunks, { type: 'video/webm' });
      this.file.set(blob);
      this.mimeType.set('video/webm');
      this.fileName.set(`recording-${new Date().toISOString().slice(0, 16).replace(/[:T]/g, '-')}.webm`);
      this.durationSec.set(this.elapsed());
      if (!this.title()) this.title.set(new Date().toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' }));
    }
    this.recorder = null;
    this.mode.set(this.file() ? 'details' : 'choose');
  }

  private stopStreams(): void {
    this.streams.forEach((s) => s.getTracks().forEach((t) => t.stop()));
    this.streams = [];
  }

  // ---- upload path ----------------------------------------------------
  onFile(ev: Event): void {
    const f = (ev.target as HTMLInputElement).files?.[0];
    if (!f) return;
    this.error.set(null);
    const mime = f.type || (f.name.endsWith('.mov') ? 'video/quicktime' : f.name.endsWith('.mp4') ? 'video/mp4' : 'video/webm');
    if (!['video/webm', 'video/mp4', 'video/quicktime'].includes(mime)) {
      this.error.set('Accepted formats are .webm, .mp4 and .mov.');
      return;
    }
    if (f.size > LIMITS.bytes) {
      this.error.set(`That file is ${(f.size / 1024 ** 3).toFixed(1)} GB; the limit is 2 GB.`);
      return;
    }
    this.file.set(f);
    this.fileName.set(f.name);
    this.mimeType.set(mime);
    if (!this.title()) this.title.set(f.name.replace(/\.[^.]+$/, ''));
    void this.probeDuration(f);
    this.mode.set('details');
  }

  private probeDuration(f: Blob): Promise<void> {
    return new Promise((resolve) => {
      const v = document.createElement('video');
      v.preload = 'metadata';
      v.onloadedmetadata = () => {
        if (isFinite(v.duration)) this.durationSec.set(Math.round(v.duration));
        URL.revokeObjectURL(v.src);
        resolve();
      };
      v.onerror = () => resolve();
      v.src = URL.createObjectURL(f);
    });
  }

  // ---- common ---------------------------------------------------------
  async startProcessing(): Promise<void> {
    const blob = this.file();
    const spaceId = this.spaceId();
    if (!blob || !spaceId) return;
    if ((this.durationSec() ?? 0) > LIMITS.seconds) {
      this.error.set('Recordings can be at most 60 minutes.');
      return;
    }
    this.error.set(null);
    this.mode.set('uploading');
    try {
      const targets = await this.api.uploadUrl({
        filename: this.fileName(), mime_type: this.mimeType(), size_bytes: blob.size,
        duration_sec: this.durationSec() ?? undefined, space_id: spaceId, title: this.title().trim() || undefined,
      });
      const parts = await uploadParts(blob, targets, (p) => this.progress.set(p));
      const rec = await this.api.register(targets.recording_id, parts, this.durationSec() ?? undefined);
      this.result.set(rec);
      this.mode.set('done');
    } catch (e: unknown) {
      const err = e as { status?: number; error?: { error?: { message?: string } } };
      this.error.set(err.error?.error?.message ?? 'The upload could not be completed. Your recording is still here — try again.');
      this.mode.set('details');
    }
  }

  fmt(sec: number): string {
    const m = Math.floor(sec / 60);
    const s = sec % 60;
    return `${m}:${s.toString().padStart(2, '0')}`;
  }
}
