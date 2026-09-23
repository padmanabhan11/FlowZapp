import { UpperCasePipe } from '@angular/common';
import { Component, computed, inject, input, output, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Router } from '@angular/router';
import { ButtonModule } from 'primeng/button';
import { SelectModule } from 'primeng/select';
import { AiApi, RewriteProposal, RewriteScope } from '../../core/ai.api';
import { Block, DocumentFull, Step } from '../../core/api.types';

export interface Accepted {
  key: string;
  text: string;
}

/**
 * S8 AI proposal panel (FR-801/802/803/805). Rewrites appear side by side
 * with the original and are accepted per item; nothing changes in the
 * document until the person accepts, and the editor applies accepted items
 * through its normal save path. Translate creates a linked draft and opens
 * it. Suggest-title offers titles the person picks from.
 */
@Component({
  selector: 'app-assist-panel',
  imports: [FormsModule, UpperCasePipe, ButtonModule, SelectModule],
  templateUrl: './assist-panel.html',
  styleUrl: './assist-panel.scss',
})
export class AssistPanel {
  private readonly ai = inject(AiApi);
  private readonly router = inject(Router);

  readonly doc = input.required<DocumentFull>();
  readonly steps = input.required<Step[]>();
  readonly accepted = output<Accepted[]>();
  readonly titlePicked = output<string>();
  readonly closed = output<void>();

  readonly proposal = signal<RewriteProposal | null>(null);
  readonly taken = signal<Set<string>>(new Set());
  readonly busy = signal<string | null>(null);
  readonly error = signal<string | null>(null);
  readonly titles = signal<string[]>([]);
  readonly language = signal('de');
  readonly languages = [
    { label: 'Deutsch', value: 'de' },
    { label: 'Français', value: 'fr' },
    { label: 'Español', value: 'es' },
    { label: 'Português', value: 'pt' },
    { label: 'Nederlands', value: 'nl' },
    { label: 'Italiano', value: 'it' },
    { label: 'English', value: 'en' },
  ];

  /** I2-T3: one translation per language; the server answers 409 for a second one. */
  hasTranslation(language: string): boolean {
    return (this.doc().translations ?? []).some((t) => t.language === language);
  }

  readonly pending = computed(() => {
    const p = this.proposal();
    if (!p) return [];
    return p.changed
      .filter((k) => !this.taken().has(k))
      .map((k) => ({
        key: k,
        label: this.label(k),
        original: p.original[k],
        proposed: p.proposed[k],
      }));
  });
  readonly unchanged = computed(() => {
    const p = this.proposal();
    return p ? Object.keys(p.original).length - p.changed.length : 0;
  });

  async rewrite(scope: RewriteScope): Promise<void> {
    this.busy.set('rewrite');
    this.error.set(null);
    try {
      this.proposal.set(await this.ai.rewrite(this.doc().id, scope));
      this.taken.set(new Set());
    } catch (e: unknown) {
      this.error.set(
        (e as { error?: { error?: { message?: string } } }).error?.error?.message ??
          'The rewrite could not be produced.',
      );
    } finally {
      this.busy.set(null);
    }
  }

  accept(key: string): void {
    const p = this.proposal();
    if (!p) return;
    this.taken.update((s) => new Set(s).add(key));
    this.accepted.emit([{ key, text: p.proposed[key] }]);
  }

  acceptAll(): void {
    const items = this.pending().map((x) => ({ key: x.key, text: x.proposed }));
    this.taken.update((s) => {
      const n = new Set(s);
      items.forEach((i) => n.add(i.key));
      return n;
    });
    this.accepted.emit(items);
  }

  reject(key: string): void {
    this.taken.update((s) => new Set(s).add(key));
  }

  async translate(): Promise<void> {
    this.busy.set('translate');
    this.error.set(null);
    try {
      const r = await this.ai.translate(this.doc().id, this.language());
      await this.router.navigate(['/d', r.id, 'edit']);
    } catch (e: unknown) {
      this.error.set(
        (e as { error?: { error?: { message?: string } } }).error?.error?.message ??
          'The translation could not be created.',
      );
    } finally {
      this.busy.set(null);
    }
  }

  async suggestTitles(): Promise<void> {
    this.busy.set('title');
    this.error.set(null);
    try {
      this.titles.set(await this.ai.suggestTitle(this.doc().id));
    } catch {
      this.error.set('No title suggestions right now.');
    } finally {
      this.busy.set(null);
    }
  }

  /** Human label for a proposal key: section:purpose → Purpose; step:<id>:instruction → Step 3; block:<id> → Paragraph. */
  label(key: string): string {
    const [kind, a, b] = key.split(':');
    if (kind === 'section') return a.charAt(0).toUpperCase() + a.slice(1);
    if (kind === 'prereq') return `Prerequisite ${Number(a) + 1}`;
    if (kind === 'selection') return 'Selection';
    if (kind === 'step') {
      const s = this.steps().find((x) => x.id === a);
      const part = b === 'instruction' ? '' : ` — ${b.replace('_', ' ')}`;
      return `Step ${s?.position ?? '?'}${part}`;
    }
    if (kind === 'block') {
      const blk = this.doc().content.blocks.find((x: Block) => x.id === a);
      const t = blk ? blk.type.replace('_', ' ') : 'block';
      return b !== undefined
        ? `${t} item ${Number(b) + 1}`
        : t.charAt(0).toUpperCase() + t.slice(1);
    }
    return key;
  }
}
