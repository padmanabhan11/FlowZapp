import { Component, OnChanges, computed, inject, input, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { ButtonModule } from 'primeng/button';
import { InputTextModule } from 'primeng/inputtext';
import { Folder, Space } from '../../core/api.types';
import { SessionStore } from '../../core/session.store';
import { SpaceApi } from '../../core/workspace.api';

/**
 * S6 — Space browser (M0 slice: folders). The document list arrives with Epic B;
 * for now the screen shows the folder tree with create/rename, and the S6 empty
 * state copy. Deleting a folder always asks for a contents strategy.
 */
@Component({
  selector: 'app-space-browser',
  imports: [FormsModule, ButtonModule, InputTextModule, RouterLink],
  templateUrl: './space-browser.html',
  styleUrl: './space-browser.scss',
})
export class SpaceBrowser implements OnChanges {
  private readonly api = inject(SpaceApi);
  private readonly session = inject(SessionStore);

  readonly spaceId = input.required<string>();
  readonly folderId = input<string | null>(null);

  readonly space = signal<Space | null>(null);
  readonly tree = signal<Folder[]>([]);
  readonly error = signal<string | null>(null);
  readonly newName = signal('');
  readonly renaming = signal<{ id: string; name: string } | null>(null);
  readonly canEdit = computed(() => ['admin', 'approver', 'editor'].includes(this.session.current()?.role ?? ''));

  private lastLoaded: string | null = null;

  readonly current = computed<Folder | null>(() => (this.folderId() ? this.find(this.tree(), this.folderId()!) : null));
  readonly crumbs = computed<Folder[]>(() => {
    const out: Folder[] = [];
    let f = this.current();
    while (f) {
      out.unshift(f);
      f = f.parent_id ? this.find(this.tree(), f.parent_id) : null;
    }
    return out;
  });
  readonly children = computed<Folder[]>(() => (this.current() ? (this.current()!.children ?? []) : this.tree()));

  ngOnChanges(): void {
    if (this.spaceId() !== this.lastLoaded) {
      this.lastLoaded = this.spaceId();
      void this.load();
    }
  }

  async load(): Promise<void> {
    this.error.set(null);
    try {
      const [space, tree] = await Promise.all([this.api.get(this.spaceId()), this.api.folders(this.spaceId())]);
      this.space.set(space);
      this.tree.set(tree);
    } catch {
      this.error.set('This space could not be loaded.');
    }
  }

  async createFolder(): Promise<void> {
    const name = this.newName().trim();
    if (!name) return;
    try {
      await this.api.createFolder(this.spaceId(), name, this.folderId());
      this.newName.set('');
      this.tree.set(await this.api.folders(this.spaceId()));
    } catch (e: unknown) {
      const err = e as { error?: { error?: { message?: string } } };
      this.error.set(err.error?.error?.message ?? 'The folder could not be created.');
    }
  }

  startRename(f: Folder): void {
    this.renaming.set({ id: f.id, name: f.name });
  }

  async commitRename(): Promise<void> {
    const r = this.renaming();
    if (!r || !r.name.trim()) return;
    try {
      await this.api.renameFolder(r.id, r.name.trim());
      this.renaming.set(null);
      this.tree.set(await this.api.folders(this.spaceId()));
    } catch {
      this.error.set('The folder could not be renamed.');
    }
  }

  private find(list: Folder[], id: string): Folder | null {
    for (const f of list) {
      if (f.id === id) return f;
      const hit = this.find(f.children ?? [], id);
      if (hit) return hit;
    }
    return null;
  }
}
