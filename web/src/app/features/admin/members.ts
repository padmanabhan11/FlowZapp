import { DatePipe } from '@angular/common';
import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { ButtonModule } from 'primeng/button';
import { SelectModule } from 'primeng/select';
import { AdminApi, Member, PendingInvite, SEATS } from '../../core/admin.api';
import { ROLES, Role } from '../../core/api.types';
import { SessionStore } from '../../core/session.store';

/**
 * S18 — Members & roles. Confirmations name the consequence rather than
 * asking "are you sure"; the last admin cannot be removed or demoted (the
 * server enforces it, the UI explains it); removal revokes immediately.
 */
@Component({
  selector: 'app-members',
  imports: [DatePipe, FormsModule, ButtonModule, SelectModule, RouterLink],
  templateUrl: './members.html',
  styleUrl: './admin.scss',
})
export class Members implements OnInit {
  private readonly api = inject(AdminApi);
  private readonly session = inject(SessionStore);

  readonly ws = this.session.current;
  readonly members = signal<Member[]>([]);
  readonly invites = signal<PendingInvite[]>([]);
  readonly error = signal<string | null>(null);
  readonly roles = ROLES.map((r) => ({ label: r[0].toUpperCase() + r.slice(1), value: r }));
  readonly seats = computed(() => SEATS[this.ws()?.plan ?? 'free'] ?? null);
  readonly used = computed(() => this.members().length + this.invites().length);
  readonly admins = computed(() => this.members().filter((m) => m.role === 'admin').length);

  async ngOnInit(): Promise<void> {
    await this.load();
  }

  async load(): Promise<void> {
    const ws = this.ws();
    if (!ws) return;
    try {
      const [m, i] = await Promise.all([this.api.members(ws.id), this.api.invites(ws.id)]);
      this.members.set(m);
      this.invites.set(i.filter((x) => x.status === 'pending'));
    } catch {
      this.error.set('Members could not be loaded.');
    }
  }

  consequence(m: Member, role: Role): string {
    const name = m.name ?? m.email ?? 'This person';
    return {
      admin: `${name} will manage members, billing and every space.`,
      approver: `${name} will be able to approve and publish documents in their spaces.`,
      editor: `${name} will be able to record, draft and submit, but not approve.`,
      reader: `${name} will only read approved documents and ask questions.`,
      guest: `${name} will only see folders explicitly shared with them.`,
    }[role];
  }

  async changeRole(m: Member, role: Role): Promise<void> {
    const ws = this.ws();
    if (!ws || role === m.role) return;
    if (m.role === 'admin' && this.admins() <= 1) {
      this.error.set('This is the only administrator. Make someone else an admin first.');
      return;
    }
    if (!confirm(this.consequence(m, role))) return;
    try {
      await this.api.setRole(ws.id, m.user_id, role);
      this.members.update((l) => l.map((x) => (x.user_id === m.user_id ? { ...x, role } : x)));
    } catch (e: unknown) {
      this.error.set((e as { error?: { error?: { message?: string } } }).error?.error?.message ?? 'The role could not be changed.');
    }
  }

  async remove(m: Member): Promise<void> {
    const ws = this.ws();
    if (!ws) return;
    const name = m.name ?? m.email ?? 'This person';
    if (!confirm(`${name} will immediately lose access to every document in this workspace and any answers drawn from them.`)) return;
    try {
      await this.api.removeMember(ws.id, m.user_id);
      this.members.update((l) => l.filter((x) => x.user_id !== m.user_id));
    } catch (e: unknown) {
      this.error.set((e as { error?: { error?: { message?: string } } }).error?.error?.message ?? 'The member could not be removed.');
    }
  }

  async resend(i: PendingInvite): Promise<void> {
    const ws = this.ws();
    if (!ws) return;
    try {
      await this.api.resendInvite(ws.id, i.id);
      await this.load();
    } catch {
      this.error.set('The invite could not be resent.');
    }
  }

  async revoke(i: PendingInvite): Promise<void> {
    const ws = this.ws();
    if (!ws) return;
    try {
      await this.api.revokeInvite(ws.id, i.id);
      this.invites.update((l) => l.filter((x) => x.id !== i.id));
    } catch {
      this.error.set('The invite could not be revoked.');
    }
  }
}
