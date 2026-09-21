import { Component, OnInit, computed, inject, input, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { ButtonModule } from 'primeng/button';
import { SelectModule } from 'primeng/select';
import { AdminApi, FolderAcl, Member, Resolution, SpaceMemberRow } from '../../core/admin.api';
import { Folder, ROLES, Role, Space } from '../../core/api.types';
import { SessionStore } from '../../core/session.store';
import { SpaceApi } from '../../core/workspace.api';

/**
 * S19 — Space permissions: the screen that prevents the product's worst
 * failure mode, so it optimises for comprehension over density. Member list
 * with the source of each role, an explicit folder-override tree, and an
 * effective-permission inspector that shows the resolution chain.
 */
@Component({
  selector: 'app-space-permissions',
  imports: [FormsModule, ButtonModule, SelectModule, RouterLink],
  templateUrl: './space-permissions.html',
  styleUrl: './admin.scss',
})
export class SpacePermissions implements OnInit {
  private readonly api = inject(AdminApi);
  private readonly spaces = inject(SpaceApi);
  private readonly session = inject(SessionStore);

  readonly id = input.required<string>();
  readonly space = signal<Space | null>(null);
  readonly members = signal<SpaceMemberRow[]>([]);
  readonly allMembers = signal<Member[]>([]);
  readonly tree = signal<Folder[]>([]);
  readonly acl = signal<Record<string, FolderAcl>>({});
  readonly selectedFolder = signal<string | null>(null);
  readonly inspectUser = signal<string | null>(null);
  readonly resolution = signal<Resolution | null>(null);
  readonly error = signal<string | null>(null);
  readonly grantUser = signal<string | null>(null);
  readonly grantRole = signal<Role>('reader');
  readonly overrideUser = signal<string | null>(null);
  readonly overrideRole = signal<string>('reader');

  readonly roles = ROLES.map((r) => ({ label: r[0].toUpperCase() + r.slice(1), value: r }));
  readonly overrideRoles = [{ label: 'No access', value: 'none' }, ...this.roles.filter((r) => r.value !== 'admin')];
  readonly candidates = computed(() => this.allMembers().filter((m) => !this.members().some((s) => s.user_id === m.user_id)).map((m) => ({ label: `${m.name ?? m.email}`, value: m.user_id })));
  readonly people = computed(() => this.allMembers().map((m) => ({ label: `${m.name ?? m.email}`, value: m.user_id })));
  readonly flat = computed(() => this.flatten(this.tree()));
  readonly selectedAcl = computed(() => (this.selectedFolder() ? this.acl()[this.selectedFolder()!] ?? null : null));

  async ngOnInit(): Promise<void> {
    const ws = this.session.current();
    try {
      const [space, members, all, tree] = await Promise.all([this.spaces.get(this.id()), this.api.spaceMembers(this.id()), ws ? this.api.members(ws.id) : Promise.resolve([]), this.spaces.folders(this.id())]);
      this.space.set(space);
      this.members.set(members);
      this.allMembers.set(all);
      this.tree.set(tree);
      for (const f of this.flatten(tree)) void this.loadAcl(f.id);
    } catch {
      this.error.set('This space could not be loaded.');
    }
  }

  private flatten(list: Folder[]): Folder[] {
    return list.flatMap((f) => [f, ...this.flatten(f.children ?? [])]);
  }

  async loadAcl(folderId: string): Promise<void> {
    try {
      const a = await this.api.folderAcl(folderId);
      this.acl.update((m) => ({ ...m, [folderId]: a }));
    } catch {
      /* folder not visible */
    }
  }

  overridesCount(folderId: string): number {
    return this.acl()[folderId]?.overrides.length ?? 0;
  }

  async grant(): Promise<void> {
    const u = this.grantUser();
    if (!u) return;
    try {
      await this.api.grantSpace(this.id(), u, this.grantRole());
      this.members.set(await this.api.spaceMembers(this.id()));
      this.grantUser.set(null);
    } catch {
      this.error.set('The role could not be granted.');
    }
  }

  async changeSpaceRole(m: SpaceMemberRow, role: Role): Promise<void> {
    if (m.source === 'workspace') return;
    try {
      await this.api.grantSpace(this.id(), m.user_id, role);
      this.members.update((l) => l.map((x) => (x.user_id === m.user_id ? { ...x, role } : x)));
    } catch {
      this.error.set('The role could not be changed.');
    }
  }

  async revoke(m: SpaceMemberRow): Promise<void> {
    if (!confirm(`${m.name ?? m.email} will immediately lose access to this space's documents and any answers drawn from them.`)) return;
    try {
      await this.api.revokeSpace(this.id(), m.user_id);
      this.members.update((l) => l.filter((x) => x.user_id !== m.user_id));
    } catch {
      this.error.set('Access could not be revoked.');
    }
  }

  async setOverride(): Promise<void> {
    const f = this.selectedFolder();
    const u = this.overrideUser();
    if (!f || !u) return;
    try {
      await this.api.setFolderOverride(f, u, this.overrideRole());
      await this.loadAcl(f);
      if (this.inspectUser() === u) await this.inspect();
    } catch {
      this.error.set('The override could not be set.');
    }
  }

  async removeOverride(userId: string): Promise<void> {
    const f = this.selectedFolder();
    if (!f) return;
    try {
      await this.api.removeFolderOverride(f, userId);
      await this.loadAcl(f);
      if (this.inspectUser() === userId) await this.inspect();
    } catch {
      this.error.set('The override could not be removed.');
    }
  }

  async inspect(): Promise<void> {
    const u = this.inspectUser();
    if (!u) return;
    try {
      const f = this.selectedFolder();
      this.resolution.set(f ? await this.api.folderChain(f, u) : await this.api.spaceAccess(this.id(), u));
    } catch {
      this.error.set('The inspector could not resolve that person.');
    }
  }

  levelLabel(level: string): string {
    return { workspace: 'Workspace', space: 'Space', folder: 'Folder', result: 'Result' }[level] ?? level;
  }
}
