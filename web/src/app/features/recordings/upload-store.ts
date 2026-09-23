/**
 * C3-T1 — what an upload needs to survive a page reload, kept in IndexedDB
 * (per browser, per person). A browser recording keeps its video Blob here,
 * because after a reload nothing else holds it; an uploaded file does not
 * (it can be gigabytes and is still on disk) — the person picks it again and
 * it is matched by name, size and modified time before anything is sent.
 */
export interface UploadSession {
  recording_id: string;
  workspace_id: string;
  title: string;
  filename: string;
  mime_type: string;
  size_bytes: number;
  duration_sec: number | null;
  source: 'recording' | 'file';
  /** name|size|lastModified of the picked file (file source only). */
  fingerprint: string | null;
  /** The recorded video (recording source only). */
  blob: Blob | null;
  /** part_number → etag for parts known to have landed. */
  done: Record<number, string>;
  updated_at: string;
}

export interface UploadSessionStore {
  get(id: string): Promise<UploadSession | null>;
  put(s: UploadSession): Promise<void>;
  delete(id: string): Promise<void>;
  list(workspaceId: string): Promise<UploadSession[]>;
}

export function fingerprint(f: File): string {
  return `${f.name}|${f.size}|${f.lastModified}`;
}

export class MemoryUploadStore implements UploadSessionStore {
  readonly data = new Map<string, UploadSession>();
  async get(id: string) {
    return this.data.get(id) ?? null;
  }
  async put(s: UploadSession) {
    this.data.set(s.recording_id, { ...s, done: { ...s.done } });
  }
  async delete(id: string) {
    this.data.delete(id);
  }
  async list(ws: string) {
    return [...this.data.values()].filter((s) => s.workspace_id === ws);
  }
}

const DB = 'flowzapp-uploads';
const STORE = 'sessions';

/** IndexedDB-backed store. Every call fails soft: if IndexedDB is unavailable, uploads still work, only without reload survival. */
export class IdbUploadStore implements UploadSessionStore {
  private db: Promise<IDBDatabase | null> | null = null;

  private open(): Promise<IDBDatabase | null> {
    if (this.db) return this.db;
    this.db = new Promise((resolve) => {
      try {
        if (typeof indexedDB === 'undefined') return resolve(null);
        const req = indexedDB.open(DB, 1);
        req.onupgradeneeded = () => {
          const s = req.result.createObjectStore(STORE, { keyPath: 'recording_id' });
          s.createIndex('workspace', 'workspace_id');
        };
        req.onsuccess = () => resolve(req.result);
        req.onerror = () => resolve(null);
      } catch {
        resolve(null);
      }
    });
    return this.db;
  }

  private async run<T>(
    mode: IDBTransactionMode,
    fn: (s: IDBObjectStore) => IDBRequest<T>,
  ): Promise<T | null> {
    const db = await this.open();
    if (!db) return null;
    return new Promise((resolve) => {
      try {
        const req = fn(db.transaction(STORE, mode).objectStore(STORE));
        req.onsuccess = () => resolve(req.result);
        req.onerror = () => resolve(null);
      } catch {
        resolve(null);
      }
    });
  }

  async get(id: string): Promise<UploadSession | null> {
    return ((await this.run('readonly', (s) => s.get(id))) as UploadSession | undefined) ?? null;
  }

  async put(session: UploadSession): Promise<void> {
    await this.run('readwrite', (s) => s.put(session));
  }

  async delete(id: string): Promise<void> {
    await this.run('readwrite', (s) => s.delete(id));
  }

  async list(workspaceId: string): Promise<UploadSession[]> {
    return (
      ((await this.run('readonly', (s) => s.index('workspace').getAll(workspaceId))) as
        UploadSession[] | null) ?? []
    );
  }
}
