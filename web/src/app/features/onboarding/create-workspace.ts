import { Component, effect, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Router } from '@angular/router';
import { ButtonModule } from 'primeng/button';
import { InputTextModule } from 'primeng/inputtext';
import { MessageModule } from 'primeng/message';
import { SessionStore } from '../../core/session.store';
import { WorkspaceApi } from '../../core/workspace.api';
import { WorkspaceStore } from '../../core/workspace.store';

/** S2 — Create workspace. Slug derived from the name, editable, checked live with a debounce. */
@Component({
  selector: 'app-create-workspace',
  imports: [FormsModule, ButtonModule, InputTextModule, MessageModule],
  templateUrl: './create-workspace.html',
  styleUrl: './onboarding.scss',
})
export class CreateWorkspace {
  private readonly api = inject(WorkspaceApi);
  private readonly session = inject(SessionStore);
  private readonly workspace = inject(WorkspaceStore);
  private readonly router = inject(Router);

  readonly name = signal('');
  readonly slug = signal('');
  readonly slugTouched = signal(false);
  readonly slugState = signal<'idle' | 'checking' | 'available' | 'taken' | 'invalid'>('idle');
  readonly submitting = signal(false);
  readonly error = signal<string | null>(null);

  private timer: ReturnType<typeof setTimeout> | null = null;

  constructor() {
    effect(() => {
      const n = this.name();
      if (!this.slugTouched()) this.slug.set(CreateWorkspace.slugify(n));
    });
    effect(() => {
      const s = this.slug();
      if (this.timer) clearTimeout(this.timer);
      if (!s) {
        this.slugState.set('idle');
        return;
      }
      if (!/^[a-z0-9]+(?:-[a-z0-9]+)*$/.test(s)) {
        this.slugState.set('invalid');
        return;
      }
      this.slugState.set('checking');
      this.timer = setTimeout(() => void this.check(s), 350);
    });
  }

  static slugify(v: string): string {
    return v.normalize('NFKD').replace(/[\u0300-\u036f]/g, '').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 80);
  }

  nameValid(): boolean {
    const n = this.name().trim();
    return n.length >= 2 && n.length <= 60;
  }

  canSubmit(): boolean {
    return this.nameValid() && this.slugState() === 'available' && !this.submitting();
  }

  onSlugInput(v: string): void {
    this.slugTouched.set(true);
    this.slug.set(v.toLowerCase());
  }

  async submit(): Promise<void> {
    if (!this.canSubmit()) return;
    this.submitting.set(true);
    this.error.set(null);
    try {
      const ws = await this.api.create(this.name().trim(), this.slug());
      await this.session.load();
      this.workspace.select(ws.id);
      await this.router.navigate(['/onboarding/invite']);
    } catch {
      this.error.set('The workspace could not be created. Check the name and address and try again.');
    } finally {
      this.submitting.set(false);
    }
  }

  private async check(s: string): Promise<void> {
    try {
      const r = await this.api.slugAvailable(s);
      if (this.slug() === s) this.slugState.set(r.available ? 'available' : 'taken');
    } catch {
      if (this.slug() === s) this.slugState.set('idle');
    }
  }
}
