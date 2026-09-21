import { Component, OnChanges, computed, inject, input, signal } from '@angular/core';
import { DatePipe } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Router, RouterLink } from '@angular/router';
import { ButtonModule } from 'primeng/button';
import { SelectModule } from 'primeng/select';
import { InputTextModule } from 'primeng/inputtext';
import { DocumentSummary, Folder, Space, Template } from '../../core/api.types';
import { DocumentApi } from '../../core/document.api';
import { SessionStore } from '../../core/session.store';
import { SpaceApi } from '../../core/workspace.api';

/**
 * S6 — Space browser (M0 slice: folders). The document list arrives with Epic B;
 * for now the screen shows the folder tree with create/rename, and the S6 empty
 * state copy. Deleting a folder always asks for a contents strategy.
 */
@Component({
  selector: 'app-space-browser',
  imports: [DatePipe, FormsModule, ButtonModule, InputTextModule, SelectModule, RouterLink],
  templateUrl: './space-browser.html',
  styleUrl: './space-browser.scss',
})
export class SpaceBrowser implements OnChanges {
  private readonly api = inject(SpaceApi);
  private readonly session = inject(SessionStore);
  private readonly docs = inject(DocumentApi);
  private readonly router = inject(Router);

  readonly spaceId = input.required<string>();
  readonly folderId = input<string | null>(null);

  readonly space = signal<Space | null>(null);
  readonly tree = signal<Folder[]>([]);
  readonly error = signal<string | null>(null);
  readonly documents = signal<DocumentSummary[]>([]);
  readonly templates = signal<Template[]>([]);
  readonly newDocTitle = signal('');
  readonly newDocTemplate = signal<string>('blank-sop');
  readonly stateFilter = signal<string>('');
  readonly stateOptions = [
    { label: 'Draft', value: 'draft' },
    { label: 'In review', value: 'in_review' },
    { label: 'Approved', value: 'approved' },
    { label: 'Archived', value: 'archived' },
  ];
  readonly newName = signal('');
  readonly renaming = signal<{ id: string; name: string } | null>(null);
  readonly isAdmin = computed(() => this.session.current()?.role === 'admin');
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

  private lastFolder: string | null | undefined = undefined;

  ngOnChanges(): void {
    if (this.spaceId() !== this.lastLoaded) {
      this.lastLoaded = this.spaceId();
      void this.load();
    }
    if (this.folderId() !== this.lastFolder) {
      this.lastFolder = this.folderId();
      void this.loadDocuments();
    }
  }

  async loadDocuments(): Promise<void> {
    try {
      this.documents.set(await this.docs.list({ space_id: this.spaceId(), folder_id: this.folderId() ?? undefined, state: this.stateFilter() || undefined }));
    } catch {
      this.documents.set([]);
    }
  }

  setStateFilter(v: string): void {
    this.stateFilter.set(v);
    void this.loadDocuments();
  }

  async createDocument(): Promise<void> {
    const title = this.newDocTitle().trim();
    if (!title) return;
    try {
      const d = await this.docs.create({ space_id: this.spaceId(), folder_id: this.folderId(), title, template_id: this.newDocTemplate() });
      this.newDocTitle.set('');
      await this.router.navigate(['/d', d.id, 'edit']);
    } catch {
      this.error.set('The document could not be created.');
    }
  }

  stateLabel(state: string): string {
    return { draft: 'Draft', in_review: 'In review', approved: 'Approved', archived: 'Archived' }[state] ?? state;
  }

  async load(): Promise<void> {
    this.error.set(null);
    try {
      const [space, tree, templates] = await Promise.all([this.api.get(this.spaceId()), this.api.folders(this.spaceId()), this.docs.templates()]);
      this.space.set(space);
      this.tree.set(tree);
      this.templates.set(templates);
      await this.loadDocuments();
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
