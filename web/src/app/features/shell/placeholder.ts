import { Component, input } from '@angular/core';

/** Routed placeholder for screens that arrive in later milestones. */
@Component({
  selector: 'app-placeholder',
  template: `<section class="document-surface" style="padding: 32px"><h1 style="margin-top:0">{{ title() }}</h1><p style="color: var(--ink-soft)">Arrives in {{ milestone() }}.</p></section>`,
})
export class Placeholder {
  readonly title = input('Coming soon');
  readonly milestone = input('a later milestone');
}
