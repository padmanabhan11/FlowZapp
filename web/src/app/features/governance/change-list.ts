import { Component, input } from '@angular/core';
import { Change } from '../../core/api.types';

/** Block-level diff rendering shared by S12 and S13: added, removed, modified, moved — each labelled (moved is moved, not delete+add). */
@Component({
  selector: 'app-change-list',
  template: `
    @if (changes().length === 0) {
      <p class="cl__none">No differences.</p>
    }
    @for (c of changes(); track c.ref + c.kind) {
      <div class="cl__row cl__row--{{ c.kind }}">
        <span class="cl__kind">{{ label(c) }}</span>
        <span class="cl__ref control-value">{{ c.ref }}</span>
        <div class="cl__body">
          @if (c.kind === 'removed' || c.kind === 'modified' || c.kind === 'moved') {
            <div class="cl__from"><s>{{ text(c.from) }}</s></div>
          }
          @if (c.kind !== 'removed') {
            <div class="cl__to">{{ text(c.to) }}</div>
          }
        </div>
      </div>
    }
  `,
  styles: `
    .cl__none { color: var(--ink-soft); }
    .cl__row { display: grid; grid-template-columns: 84px 90px 1fr; gap: 10px; padding: 8px 0; border-top: 1px solid var(--line); }
    .cl__kind { font-size: 0.8rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.03em; }
    .cl__row--added .cl__kind { color: var(--state-approved); }
    .cl__row--removed .cl__kind { color: var(--danger); }
    .cl__row--modified .cl__kind { color: var(--state-draft); }
    .cl__row--moved .cl__kind { color: var(--state-review); }
    .cl__ref { color: var(--ink-soft); font-size: 0.85rem; }
    .cl__from { color: var(--ink-soft); }
  `,
})
export class ChangeList {
  readonly changes = input<Change[]>([]);

  label(c: Change): string {
    if (c.kind === 'moved') return `moved ${c.from_position}→${c.to_position}${c.also_modified ? ' + edited' : ''}`;
    return c.kind;
  }

  text(v: unknown): string {
    if (v === null || v === undefined) return '';
    if (typeof v === 'string') return v;
    if (Array.isArray(v)) return v.map((x) => this.text(x)).join(' · ');
    const o = v as Record<string, unknown>;
    return (o['instruction'] ?? o['text'] ?? JSON.stringify(v)) as string;
  }
}
