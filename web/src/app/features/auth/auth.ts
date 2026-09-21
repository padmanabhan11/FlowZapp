import { Component, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { ButtonModule } from 'primeng/button';
import { InputTextModule } from 'primeng/inputtext';
import { MessageModule } from 'primeng/message';
import { SessionStore } from '../../core/session.store';

/**
 * S1 — Sign in / Sign up (11-Screen-Functional-Specification).
 * One form for both. Requesting a link never discloses whether the address is
 * registered: the confirmation copy is identical either way (FR-102).
 * Arriving with ?invite=TOKEN keeps the token and accepts it after sign-in.
 */
@Component({
  selector: 'app-auth',
  imports: [FormsModule, ButtonModule, InputTextModule, MessageModule, RouterLink],
  templateUrl: './auth.html',
  styleUrl: './auth.scss',
})
export class Auth {
  private readonly session = inject(SessionStore);
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);

  readonly email = signal('');
  readonly sending = signal(false);
  readonly sent = signal(false);
  readonly error = signal<string | null>(null);
  readonly mode = signal<'signin' | 'signup'>('signin');
  readonly inviteToken = signal<string | null>(null);

  constructor() {
    const q = this.route.snapshot.queryParamMap;
    this.inviteToken.set(q.get('invite'));
    if (q.get('error') === 'link_expired') {
      this.error.set('That link has expired or was already used. Request a new one.');
    }
    // Already signed in? Route by state (S1 rules), accepting a pending invite first.
    void this.session.load().then(async (me) => {
      if (!me?.user) return;
      const token = this.inviteToken();
      if (token) {
        try {
          await this.session.acceptInvite(token);
        } catch {
          this.error.set('That invitation is no longer valid. Ask for a new one.');
          return;
        }
      }
      await this.routeByState(q.get('next'));
    });
  }

  emailValid(): boolean {
    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(this.email().trim());
  }

  async requestLink(): Promise<void> {
    if (!this.emailValid() || this.sending()) return;
    this.sending.set(true);
    this.error.set(null);
    try {
      const token = this.inviteToken();
      const next = token ? `/auth?invite=${encodeURIComponent(token)}` : (this.route.snapshot.queryParamMap.get('next') ?? undefined);
      await this.session.requestMagicLink(this.email().trim(), next);
      this.sent.set(true);
    } catch {
      this.error.set('We could not send a link just now. Try again in a minute.');
    } finally {
      this.sending.set(false);
    }
  }

  toggleMode(): void {
    this.mode.set(this.mode() === 'signin' ? 'signup' : 'signin');
  }

  private async routeByState(next: string | null): Promise<void> {
    if (next && next.startsWith('/') && !next.startsWith('/auth')) {
      await this.router.navigateByUrl(next);
      return;
    }
    if (this.session.workspaces().length === 0) {
      await this.router.navigate(['/onboarding/workspace']);
      return;
    }
    await this.router.navigate(['/']);
  }
}
