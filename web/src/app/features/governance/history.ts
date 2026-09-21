import { DatePipe } from '@angular/common';
import { Component, OnInit, computed, inject, input, signal } from '@angular/core';
import { Router, RouterLink } from '@angular/router';
import { ButtonModule } from 'primeng/button';
import { Change, VersionMeta } from '../../core/api.types';
import { GovernanceApi } from '../../core/document.api';
import { SessionStore } from '../../core/session.store';
import { ChangeList } from './change-list';

/** S13 — Version history & diff: version list left, block-level diff between any two on the right; restore creates a draft and says so. */
@Component({
  selector: 'app-history',
  imports: [DatePipe, ButtonModule, RouterLink, ChangeList],
  templateUrl: './history.html',
  styleUrl: './history.scss',
})
export class History implements OnInit {
  private readonly gov = inject(GovernanceApi);
  private readonly session = inject(SessionStore);
  private readonly router = inject(Router);

  readonly id = input.required<string>();
  readonly versions = signal<VersionMeta[]>([]);
  readonly from = signal<string | null>(null);
  readonly to = signal<string>('working');
  readonly changes = signal<Change[] | null>(null);
  readonly error = signal<string | null>(null);
  readonly canEdit = computed(() => ['admin', 'approver', 'editor'].includes(this.session.current()?.role ?? ''));

  async ngOnInit(): Promise<void> {
    try {
      const v = await this.gov.versions(this.id());
      this.versions.set(v);
      if (v.length) {
        this.from.set(v[0].id);
        this.to.set(v.length > 1 ? v[0].id : 'working');
        if (v.length > 1) this.from.set(v[1].id);
        await this.compare();
      }
    } catch {
      this.error.set('History could not be loaded.');
    }
  }

  pick(v: VersionMeta | 'working'): void {
    const id = v === 'working' ? 'working' : v.id;
    // Two-pick: the first click sets "from", the second sets "to".
    if (this.from() === null || (this.to() !== null && this.from() !== null && this.to() !== 'pending')) {
      this.from.set(id);
      this.to.set('pending');
      this.changes.set(null);
      return;
    }
    this.to.set(id);
    void this.compare();
  }

  async compare(): Promise<void> {
    const f = this.from();
    const t = this.to();
    if (!f || !t || t === 'pending') return;
    try {
      this.changes.set(await this.gov.diff(this.id(), f, t));
    } catch {
      this.error.set('The comparison could not be loaded.');
    }
  }

  async restore(v: VersionMeta): Promise<void> {
    if (!confirm(`Restore v${v.version_number} as a new draft? The live approved version stays published until the restored draft is itself approved.`)) return;
    try {
      await this.gov.restore(this.id(), v.id);
      await this.router.navigate(['/d', this.id(), 'edit']);
    } catch {
      this.error.set('The version could not be restored.');
    }
  }

  label(id: string | null): string {
    if (id === 'working') return 'working copy';
    const v = this.versions().find((x) => x.id === id);
    return v ? `v${v.version_number}` : '…';
  }
}
