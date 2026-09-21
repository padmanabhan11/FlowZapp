import { DatePipe } from '@angular/common';
import { Component, OnInit, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { ButtonModule } from 'primeng/button';
import { InputTextModule } from 'primeng/inputtext';
import { SelectModule } from 'primeng/select';
import { ToggleSwitchModule } from 'primeng/toggleswitch';
import { AccountApi, WorkspaceDetail } from '../../core/account.api';
import { SessionStore } from '../../core/session.store';

/**
 * S23 — Workspace settings. Self-approval is off by default and the toggle
 * states what turning it on permits; retention changes are not retroactive;
 * deletion is scheduled with a grace period behind a typed confirmation, and
 * is the only place --danger appears.
 */
@Component({
  selector: 'app-settings',
  imports: [
    DatePipe,
    FormsModule,
    RouterLink,
    ButtonModule,
    InputTextModule,
    SelectModule,
    ToggleSwitchModule,
  ],
  templateUrl: './settings.html',
  styleUrl: './admin.scss',
})
export class Settings implements OnInit {
  private readonly api = inject(AccountApi);
  private readonly session = inject(SessionStore);

  readonly ws = signal<WorkspaceDetail | null>(null);
  readonly name = signal('');
  readonly selfApproval = signal(false);
  readonly cadence = signal<number | null>(null);
  readonly retain = signal(true);
  readonly language = signal('en');
  readonly confirmName = signal('');
  readonly saved = signal<string | null>(null);
  readonly error = signal<string | null>(null);

  readonly cadences = [
    { label: 'No review cycle', value: null },
    { label: 'Every 3 months', value: 3 },
    { label: 'Every 6 months', value: 6 },
    { label: 'Every 12 months', value: 12 },
    { label: 'Every 24 months', value: 24 },
  ];
  readonly retentions = [
    { label: 'Keep recordings', value: true },
    { label: 'Discard video after processing (keep transcript and screenshots)', value: false },
  ];
  readonly languages = [
    { label: 'English', value: 'en' },
    { label: 'Deutsch', value: 'de' },
    { label: 'Français', value: 'fr' },
    { label: 'Español', value: 'es' },
    { label: 'Português', value: 'pt' },
    { label: 'Nederlands', value: 'nl' },
    { label: 'Italiano', value: 'it' },
  ];

  async ngOnInit(): Promise<void> {
    const id = this.session.current()?.id;
    if (!id) return;
    try {
      const w = await this.api.workspace(id);
      this.ws.set(w);
      this.name.set(w.name);
      this.selfApproval.set(!!w.settings.self_approval);
      this.cadence.set(w.settings.review_cadence_months ?? null);
      this.retain.set(w.settings.retain_recordings !== false);
      this.language.set(w.settings.default_language ?? 'en');
    } catch {
      this.error.set('Settings could not be loaded.');
    }
  }

  async save(): Promise<void> {
    const w = this.ws();
    if (!w) return;
    try {
      const next = await this.api.updateWorkspace(w.id, {
        name: this.name().trim(),
        settings: {
          self_approval: this.selfApproval(),
          review_cadence_months: this.cadence(),
          retain_recordings: this.retain(),
          default_language: this.language(),
        },
      });
      this.ws.set(next);
      await this.session.load();
      this.flash('Settings saved.');
    } catch {
      this.error.set('Settings could not be saved.');
    }
  }

  async scheduleDeletion(): Promise<void> {
    const w = this.ws();
    if (!w || this.confirmName() !== w.name) return;
    try {
      const r = await this.api.scheduleDeletion(w.id, this.confirmName());
      this.ws.set({ ...w, deletion_scheduled_at: r.deletion_scheduled_at });
      this.confirmName.set('');
    } catch {
      this.error.set('Deletion could not be scheduled.');
    }
  }

  async cancelDeletion(): Promise<void> {
    const w = this.ws();
    if (!w) return;
    try {
      await this.api.cancelDeletion(w.id);
      this.ws.set({ ...w, deletion_scheduled_at: null });
    } catch {
      this.error.set('Deletion could not be cancelled.');
    }
  }

  private flash(msg: string): void {
    this.saved.set(msg);
    setTimeout(() => this.saved.set(null), 2500);
  }
}
