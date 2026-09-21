import { DatePipe } from '@angular/common';
import { Component, OnInit, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ButtonModule } from 'primeng/button';
import { InputTextModule } from 'primeng/inputtext';
import { SelectModule } from 'primeng/select';
import { ToggleSwitchModule } from 'primeng/toggleswitch';
import { AccountApi, ActiveSession, NotificationPrefs, PrefKey } from '../../core/account.api';
import { SessionStore } from '../../core/session.store';

/**
 * S24 — Account & notifications. Preferences are per workspace (a person may
 * be an approver in one and a reader in another); acknowledgement-due cannot
 * be turned off while something is assigned, and the toggle says why.
 */
@Component({
  selector: 'app-account',
  imports: [DatePipe, FormsModule, ButtonModule, InputTextModule, SelectModule, ToggleSwitchModule],
  templateUrl: './account.html',
  styleUrl: './account.scss',
})
export class Account implements OnInit {
  private readonly api = inject(AccountApi);
  readonly session = inject(SessionStore);

  readonly name = signal('');
  readonly locale = signal('en');
  readonly prefs = signal<NotificationPrefs | null>(null);
  readonly sessions = signal<ActiveSession[]>([]);
  readonly saved = signal<string | null>(null);
  readonly error = signal<string | null>(null);

  readonly locales = [
    { label: 'English', value: 'en' },
    { label: 'Deutsch', value: 'de' },
    { label: 'Français', value: 'fr' },
    { label: 'Español', value: 'es' },
    { label: 'Português', value: 'pt' },
    { label: 'Nederlands', value: 'nl' },
    { label: 'Italiano', value: 'it' },
  ];

  readonly prefRows: { key: PrefKey; label: string; hint: string }[] = [
    {
      key: 'review_requested',
      label: 'Review requested',
      hint: 'A document in a space you approve for was submitted.',
    },
    {
      key: 'changes_requested',
      label: 'Changes requested',
      hint: 'A reviewer sent your submission back with a comment.',
    },
    {
      key: 'document_approved',
      label: 'Document approved',
      hint: 'Your submission was published. Always shown in-app.',
    },
    {
      key: 'acknowledgement_due',
      label: 'Acknowledgement due',
      hint: 'A document you must confirm reading has a new version.',
    },
    {
      key: 'recording_failed',
      label: 'Recording failed',
      hint: 'A recording could not be processed, with the reason.',
    },
    {
      key: 'weekly_digest',
      label: 'Weekly digest',
      hint: 'Documents due for review and acknowledgements you owe, once a week.',
    },
  ];

  async ngOnInit(): Promise<void> {
    const u = this.session.user();
    this.name.set(u?.name ?? '');
    this.locale.set(u?.locale ?? 'en');
    try {
      this.prefs.set(await this.api.notificationPrefs());
    } catch {
      this.error.set('Notification preferences could not be loaded.');
    }
    try {
      this.sessions.set(await this.api.sessions());
    } catch {
      this.sessions.set([]);
    }
  }

  async saveProfile(): Promise<void> {
    try {
      await this.api.updateProfile({ name: this.name().trim(), locale: this.locale() });
      await this.session.load();
      this.flash('Profile saved.');
    } catch {
      this.error.set('The profile could not be saved.');
    }
  }

  async setPref(key: PrefKey, value: boolean): Promise<void> {
    try {
      this.prefs.set(await this.api.updateNotificationPrefs({ [key]: value }));
      this.flash('Preference saved.');
    } catch {
      this.error.set('The preference could not be saved.');
    }
  }

  locked(key: PrefKey): boolean {
    return this.prefs()?.locked.includes(key) ?? false;
  }

  async revoke(s: ActiveSession): Promise<void> {
    try {
      await this.api.revokeSession(s.id);
      this.sessions.update((list) => list.filter((x) => x.id !== s.id));
    } catch {
      this.error.set('The session could not be signed out.');
    }
  }

  private flash(msg: string): void {
    this.saved.set(msg);
    setTimeout(() => this.saved.set(null), 2500);
  }
}
