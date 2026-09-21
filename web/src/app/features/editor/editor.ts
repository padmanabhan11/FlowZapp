import { HttpErrorResponse } from '@angular/common/http';
import { Component, OnDestroy, OnInit, computed, inject, input, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { ButtonModule } from 'primeng/button';
import { CheckboxModule } from 'primeng/checkbox';
import { InputTextModule } from 'primeng/inputtext';
import { TextareaModule } from 'primeng/textarea';
import { Block, DocumentFull, Step } from '../../core/api.types';
import { BlockEditor } from './block-editor';
import { DocumentApi, GovernanceApi } from '../../core/document.api';
import { Router } from '@angular/router';

/**
 * S8 — SOP editor (M0 core). 720px canvas; structural sections are fixed
 * slots; steps are an ordered, renumbering list; autosave after 2 s of
 * inactivity with an explicit "Saved hh:mm" indicator, never a spinner.
 * Every save carries expected_updated_at — a 409 shows the conflict and
 * offers reload, it never overwrites. Editing an approved document shows the
 * draft-revision banner (the most misunderstood behaviour in the product).
 *
 * Free-form content is a structured block list (B1: every F2 block type,
 * slash menu, paste fidelity) handled by BlockEditor; this component owns
 * the fixed slots, steps and autosave.
 */
@Component({
  selector: 'app-editor',
  imports: [
    FormsModule,
    ButtonModule,
    CheckboxModule,
    InputTextModule,
    TextareaModule,
    RouterLink,
    BlockEditor,
  ],
  templateUrl: './editor.html',
  styleUrl: './editor.scss',
})
export class Editor implements OnInit, OnDestroy {
  private readonly api = inject(DocumentApi);
  private readonly gov = inject(GovernanceApi);
  private readonly router = inject(Router);
  readonly submitting = signal(false);

  readonly id = input.required<string>();

  readonly doc = signal<DocumentFull | null>(null);
  readonly steps = signal<Step[]>([]);
  readonly savedAt = signal<Date | null>(null);
  readonly dirty = signal(false);
  readonly saving = signal(false);
  readonly conflict = signal<DocumentFull | null>(null);
  readonly error = signal<string | null>(null);
  readonly liveVersionBanner = computed(() => {
    const d = this.doc();
    return d?.approved_version_id && d.state === 'draft';
  });
  readonly isApproved = computed(() => this.doc()?.state === 'approved');
  readonly prereqText = signal('');
  readonly newStep = signal('');

  /** Submit is blocked when: zero steps, missing Purpose, or missing Owner (S8 rules). */
  readonly submitBlockers = computed<string[]>(() => {
    const d = this.doc();
    if (!d) return [];
    const out: string[] = [];
    if (d.doc_type === 'sop' && this.steps().length === 0) out.push('at least one step');
    if (!d.content.purpose?.trim()) out.push('a purpose');
    if (!d.owner) out.push('an owner');
    return out;
  });

  private timer: ReturnType<typeof setTimeout> | null = null;
  private pending: { title?: string; content?: Partial<DocumentFull['content']> } = {};

  async ngOnInit(): Promise<void> {
    await this.load();
  }

  ngOnDestroy(): void {
    if (this.timer) clearTimeout(this.timer);
    if (this.dirty()) void this.flush();
  }

  async load(): Promise<void> {
    try {
      const d = await this.api.get(this.id());
      this.doc.set(d);
      this.steps.set(d.steps);
      this.prereqText.set(d.content.prerequisites.join('\n'));
      this.conflict.set(null);
      this.dirty.set(false);
      this.pending = {};
    } catch {
      this.error.set('This document could not be loaded.');
    }
  }

  // ---- document fields -------------------------------------------------
  setTitle(v: string): void {
    this.doc.update((d) => (d ? { ...d, title: v } : d));
    this.pending.title = v;
    this.schedule();
  }

  setSection(key: 'purpose' | 'scope' | 'outcome', v: string): void {
    this.doc.update((d) => (d ? { ...d, content: { ...d.content, [key]: v } } : d));
    this.pending.content = { ...(this.pending.content ?? {}), [key]: v };
    this.schedule();
  }

  setPrereqs(v: string): void {
    this.prereqText.set(v);
    const list = v
      .split('\n')
      .map((s) => s.trim())
      .filter(Boolean);
    this.doc.update((d) => (d ? { ...d, content: { ...d.content, prerequisites: list } } : d));
    this.pending.content = { ...(this.pending.content ?? {}), prerequisites: list };
    this.schedule();
  }

  setBlocks(blocks: Block[]): void {
    this.doc.update((d) => (d ? { ...d, content: { ...d.content, blocks } } : d));
    this.pending.content = { ...(this.pending.content ?? {}), blocks };
    this.schedule();
  }

  private schedule(): void {
    this.dirty.set(true);
    if (this.timer) clearTimeout(this.timer);
    this.timer = setTimeout(() => void this.flush(), 2000);
  }

  async flush(): Promise<void> {
    const d = this.doc();
    if (!d || this.saving() || this.conflict()) return;
    const body = { ...this.pending, expected_updated_at: d.updated_at };
    this.pending = {};
    this.saving.set(true);
    try {
      const saved = await this.api.patch(d.id, body);
      // Keep local edits made while the request was in flight; take server metadata.
      this.doc.update((cur) =>
        cur
          ? { ...cur, updated_at: saved.updated_at, state: saved.state, owner: saved.owner }
          : saved,
      );
      this.savedAt.set(new Date());
      this.dirty.set(Object.keys(this.pending).length > 0);
      if (this.dirty()) this.schedule();
    } catch (e: unknown) {
      if (e instanceof HttpErrorResponse && e.status === 409) {
        this.conflict.set((e.error?.error?.details?.current as DocumentFull) ?? null);
      } else {
        this.error.set('Not saved. Your changes are kept here — retry in a moment.');
        this.pending = { ...body, ...this.pending };
        delete (this.pending as { expected_updated_at?: string }).expected_updated_at;
        this.dirty.set(true);
      }
    } finally {
      this.saving.set(false);
    }
  }

  // ---- steps -----------------------------------------------------------
  async addStep(): Promise<void> {
    const text = this.newStep().trim();
    const d = this.doc();
    if (!text || !d) return;
    try {
      const s = await this.api.addStep(d.id, { instruction: text });
      this.steps.update((list) => [...list, s]);
      this.newStep.set('');
      this.afterStepWrite();
    } catch {
      this.error.set('The step could not be added.');
    }
  }

  async patchStep(step: Step, patch: Partial<Step>): Promise<void> {
    const d = this.doc();
    if (!d) return;
    this.steps.update((list) => list.map((s) => (s.id === step.id ? { ...s, ...patch } : s)));
    try {
      const saved = await this.api.patchStep(d.id, step.id, patch);
      this.steps.update((list) => list.map((s) => (s.id === step.id ? saved : s)));
      this.afterStepWrite();
    } catch {
      this.error.set('The step could not be saved.');
    }
  }

  async moveStep(step: Step, dir: -1 | 1): Promise<void> {
    const d = this.doc();
    if (!d) return;
    const list = this.steps();
    const i = list.findIndex((s) => s.id === step.id);
    const j = i + dir;
    if (i < 0 || j < 0 || j >= list.length) return;
    const order = list.map((s) => s.id);
    [order[i], order[j]] = [order[j], order[i]];
    try {
      this.steps.set(await this.api.reorderSteps(d.id, order));
      this.afterStepWrite();
    } catch {
      this.error.set('The steps could not be reordered.');
    }
  }

  async deleteStep(step: Step): Promise<void> {
    const d = this.doc();
    if (!d) return;
    try {
      await this.api.deleteStep(d.id, step.id);
      this.steps.update((list) =>
        list.filter((s) => s.id !== step.id).map((s, k) => ({ ...s, position: k + 1 })),
      );
      this.afterStepWrite();
    } catch {
      this.error.set('The step could not be removed.');
    }
  }

  /** Step writes touch the document server-side (updated_at, state); refresh metadata so the next autosave's expected_updated_at is right. */
  private async afterStepWrite(): Promise<void> {
    const d = this.doc();
    if (!d) return;
    try {
      const fresh = await this.api.get(d.id);
      this.doc.update((cur) =>
        cur ? { ...cur, updated_at: fresh.updated_at, state: fresh.state } : cur,
      );
      this.savedAt.set(new Date());
    } catch {
      /* next autosave will surface a conflict if needed */
    }
  }

  reloadAfterConflict(): void {
    void this.load();
  }

  async submit(): Promise<void> {
    const d = this.doc();
    if (!d || this.submitting()) return;
    if (this.dirty()) await this.flush();
    this.submitting.set(true);
    this.error.set(null);
    try {
      const blockers = await this.gov.submitCheck(d.id);
      if (blockers.length) {
        this.error.set('Cannot submit yet: ' + blockers.join('; ') + '.');
        return;
      }
      await this.gov.submit(d.id);
      await this.router.navigate(['/d', d.id, 'review']);
    } catch (e: unknown) {
      const err = e as { error?: { error?: { message?: string } } };
      this.error.set(err.error?.error?.message ?? 'The document could not be submitted.');
    } finally {
      this.submitting.set(false);
    }
  }

  stateLabel(state: string): string {
    return (
      { draft: 'Draft', in_review: 'In review', approved: 'Approved', archived: 'Archived' }[
        state
      ] ?? state
    );
  }

  savedLabel(): string {
    const t = this.savedAt();
    return t ? `Saved ${t.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}` : '';
  }
}
