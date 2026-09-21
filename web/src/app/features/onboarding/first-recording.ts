import { Component, inject } from '@angular/core';
import { Router } from '@angular/router';
import { ButtonModule } from 'primeng/button';

/**
 * S4 — First run. One action, no template gallery (FR-109). Persists as the
 * landing state until a recording exists (workspaceGuard + shell redirect).
 */
@Component({
  selector: 'app-first-recording',
  imports: [ButtonModule],
  template: `
    <section class="first document-surface">
      <h1>What do you explain most often?</h1>
      <button pButton type="button" class="first__record" (click)="record()">Record a process</button>
      <p class="first__examples">
        For example:
        <a href="#" (click)="record('Onboarding a new client'); $event.preventDefault()">onboarding a new client</a>,
        <a href="#" (click)="record('Closing the month'); $event.preventDefault()">closing the month</a>,
        <a href="#" (click)="record('Handling a refund request'); $event.preventDefault()">handling a refund request</a>.
      </p>
      <a href="#" class="first__upload" (click)="upload(); $event.preventDefault()">Upload an existing recording</a>
    </section>
  `,
  styles: `
    .first { max-width: 560px; margin: 64px auto; padding: 40px; text-align: center; display: grid; gap: 16px; }
    .first h1 { margin: 0; }
    .first__record { justify-self: center; font-size: 1.1rem; padding: 12px 28px; }
    .first__examples { color: var(--ink-soft); margin: 0; }
    .first__upload { color: var(--ink-soft); font-size: 0.9rem; }
  `,
})
export class FirstRecording {
  private readonly router = inject(Router);

  record(title = ''): void {
    void this.router.navigate(['/recordings'], { queryParams: { record: 1, title: title || null } });
  }

  upload(): void {
    void this.router.navigate(['/recordings'], { queryParams: { record: 1, mode: 'upload' } });
  }
}
