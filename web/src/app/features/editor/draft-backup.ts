import { Content } from '../../core/api.types';

/**
 * B2-T3 — mid-edit tab-close recovery. Every local change is mirrored to
 * localStorage (per viewer, per document) before the 2 s autosave fires. If
 * the tab closes first, the next open offers to restore — but only when the
 * server copy has not moved since the backup was taken, so a recovery can
 * never overwrite someone else's later save (the same rule as the 409).
 * Storage can be unavailable (private windows): every call is best-effort.
 */
export interface DraftBackupEntry {
  documentId: string;
  /** updated_at of the server copy the edits were made against. */
  baseUpdatedAt: string;
  savedAt: string;
  title?: string;
  content?: Partial<Content>;
}

export type Recovery = 'none' | 'restore' | 'conflict';

export class DraftBackup {
  static readonly PREFIX = 'flowzapp.draft.';

  constructor(
    private readonly store: Pick<
      Storage,
      'getItem' | 'setItem' | 'removeItem'
    > | null = DraftBackup.localStorageOrNull(),
  ) {}

  static localStorageOrNull(): Storage | null {
    try {
      return typeof localStorage === 'undefined' ? null : localStorage;
    } catch {
      return null;
    }
  }

  write(
    documentId: string,
    baseUpdatedAt: string,
    pending: { title?: string; content?: Partial<Content> },
    now = new Date(),
  ): void {
    if (!this.store || (pending.title === undefined && pending.content === undefined)) return;
    const prev = this.read(documentId);
    // Keep accumulating on the same base; a new base means the earlier edits were saved.
    const merged: DraftBackupEntry = {
      documentId,
      baseUpdatedAt,
      savedAt: now.toISOString(),
      title: pending.title ?? (prev?.baseUpdatedAt === baseUpdatedAt ? prev.title : undefined),
      content: {
        ...(prev?.baseUpdatedAt === baseUpdatedAt ? prev.content : {}),
        ...(pending.content ?? {}),
      },
    };
    try {
      this.store.setItem(DraftBackup.PREFIX + documentId, JSON.stringify(merged));
    } catch {
      /* quota or disabled storage: the server autosave is still the primary path */
    }
  }

  read(documentId: string): DraftBackupEntry | null {
    if (!this.store) return null;
    try {
      const raw = this.store.getItem(DraftBackup.PREFIX + documentId);
      const e = raw ? (JSON.parse(raw) as DraftBackupEntry) : null;
      return e && e.documentId === documentId ? e : null;
    } catch {
      return null;
    }
  }

  clear(documentId: string): void {
    try {
      this.store?.removeItem(DraftBackup.PREFIX + documentId);
    } catch {
      /* ignore */
    }
  }

  /** restore: the server copy is the one the edits were made on. conflict: it has moved since — never auto-apply. */
  static recovery(entry: DraftBackupEntry | null, serverUpdatedAt: string): Recovery {
    if (!entry || (entry.title === undefined && !entry.content)) return 'none';
    return entry.baseUpdatedAt === serverUpdatedAt ? 'restore' : 'conflict';
  }
}
