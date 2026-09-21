import { DatePipe } from '@angular/common';
import { Component, OnInit, inject, input, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Router, RouterLink } from '@angular/router';
import { ButtonModule } from 'primeng/button';
import { TextareaModule } from 'primeng/textarea';
import { ReviewPayload } from '../../core/api.types';
import { GovernanceApi } from '../../core/document.api';
import { ChangeList } from './change-list';

/**
 * S12 — Approval review. Optimised for deciding, not reading: the diff is the
 * default view; approving states its consequence before the click; request
 * changes requires a comment; a stale document returns a conflict, never a
 * silent overwrite.
 */
@Component({
  selector: 'app-approval-review',
  imports: [DatePipe, FormsModule, ButtonModule, TextareaModule, RouterLink, ChangeList],
  templateUrl: './approval-review.html',
  styleUrl: './approval-review.scss',
})
export class ApprovalReview implements OnInit {
  private readonly gov = inject(GovernanceApi);
  private readonly router = inject(Router);

  readonly id = input.required<string>();
  readonly r = signal<ReviewPayload | null>(null);
  readonly view = signal<'diff' | 'full'>('diff');
  readonly comment = signal('');
  readonly busy = signal(false);
  readonly error = signal<string | null>(null);
  readonly done = signal<string | null>(null);

  async ngOnInit(): Promise<void> {
    await this.load();
  }

  async load(): Promise<void> {
    try {
      const r = await this.gov.review(this.id());
      this.r.set(r);
      if (!r.base_version) this.view.set('full');
    } catch {
      this.error.set('This document could not be loaded.');
    }
  }

  async approve(): Promise<void> {
    const r = this.r();
    if (!r || this.busy()) return;
    this.busy.set(true);
    this.error.set(null);
    try {
      const res = await this.gov.approve(r.id, this.comment().trim() || null, r.updated_at);
      this.done.set(`Published as v${res.version_number}. This is now the answer the assistant gives.`);
    } catch (e: unknown) {
      const err = e as { status?: number; error?: { error?: { message?: string } } };
      this.error.set(err.status === 409 ? 'This document changed while you were reviewing it. Reload to see the current version before approving.' : (err.error?.error?.message ?? 'Approval failed.'));
    } finally {
      this.busy.set(false);
    }
  }

  async requestChanges(): Promise<void> {
    const r = this.r();
    if (!r || this.comment().trim().length < 3 || this.busy()) return;
    this.busy.set(true);
    try {
      await this.gov.requestChanges(r.id, this.comment().trim());
      await this.router.navigate(['/d', r.id, 'edit']);
    } catch {
      this.error.set('The request could not be sent.');
    } finally {
      this.busy.set(false);
    }
  }
}
