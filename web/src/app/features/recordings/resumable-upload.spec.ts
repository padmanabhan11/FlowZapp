import { Recording, ResumeTargets, UploadTargets } from '../../core/api.types';
import { uploadParts } from './multipart-upload';
import { FileMismatchError, ResumableUpload, UploadApi } from './resumable-upload';
import { MemoryUploadStore } from './upload-store';

/** A pretend object store + API: parts land only while "online"; ListParts reports what landed. */
class FakeBackend implements UploadApi {
  online = true;
  stored = new Map<number, string>();
  puts: number[] = [];
  registered: { part_number: number; etag: string }[] | null = null;
  readonly partSize = 4;
  constructor(private readonly size: number) {}

  private url(n: number) {
    return `https://store/rec1?partNumber=${n}`;
  }

  fetch = (async (url: string) => {
    if (!this.online) throw new TypeError('Failed to fetch');
    const n = Number(/partNumber=(\d+)/.exec(url)![1]);
    this.puts.push(n);
    this.stored.set(n, `etag-${n}`);
    return new Response(null, { status: 200, headers: { ETag: `"etag-${n}"` } });
  }) as unknown as typeof fetch;

  async uploadUrl(): Promise<UploadTargets> {
    const count = Math.ceil(this.size / this.partSize);
    return {
      recording_id: 'rec1',
      upload_id: 'u1',
      part_size: this.partSize,
      parts: Array.from({ length: count }, (_, i) => ({
        part_number: i + 1,
        url: this.url(i + 1),
      })),
    };
  }

  async resume(): Promise<ResumeTargets> {
    const count = Math.ceil(this.size / this.partSize);
    const done = [...this.stored.entries()]
      .map(([n, etag]) => ({ part_number: n, etag, size: this.partSize }))
      .sort((a, b) => a.part_number - b.part_number);
    const missing = Array.from({ length: count }, (_, i) => i + 1).filter(
      (n) => !this.stored.has(n),
    );
    return {
      recording_id: 'rec1',
      upload_id: 'u1',
      part_size: this.partSize,
      size_bytes: this.size,
      mime_type: 'video/webm',
      title: 't',
      parts_done: done,
      parts: missing.map((n) => ({ part_number: n, url: this.url(n) })),
    };
  }

  async register(_id: string, parts: { part_number: number; etag: string }[]): Promise<Recording> {
    this.registered = parts;
    return { id: 'rec1', state: 'uploaded' } as Recording;
  }
}

const input = (blob: Blob, source: 'recording' | 'file' = 'recording') => ({
  blob,
  filename: 'r.webm',
  mime_type: 'video/webm',
  duration_sec: 60,
  space_id: 's1',
  workspace_id: 'w1',
  source,
});

describe('interrupted-network upload (C3-T3)', () => {
  it('network drops mid-upload, the page reloads, and the resume sends only the missing parts', async () => {
    const backend = new FakeBackend(10); // parts: 1..3
    const store = new MemoryUploadStore();
    const blob = new Blob([new Uint8Array(10)], { type: 'video/webm' });

    // Part 1 lands, then the connection dies for good in this tab.
    const origFetch = backend.fetch;
    backend.fetch = (async (url: string, init?: RequestInit) => {
      const r = await origFetch(url, init);
      backend.online = false;
      return r;
    }) as unknown as typeof fetch;
    const first = new ResumableUpload(backend, store, {
      fetchImpl: (u, i) => backend.fetch(u as string, i),
      backoffMs: 1,
      isOnline: () => true,
    });
    await expect(first.start(input(blob), () => undefined)).rejects.toThrow('Failed to fetch');
    expect(backend.registered).toBeNull();
    const saved = await store.get('rec1');
    expect(saved?.done).toEqual({ 1: 'etag-1' });
    expect(saved?.blob?.size).toBe(10); // a browser recording keeps its video for the resume

    // "Reload": a new uploader over the same persisted store, connection back.
    backend.fetch = origFetch;
    backend.online = true;
    backend.puts = [];
    const second = new ResumableUpload(backend, store, {
      fetchImpl: (u, i) => backend.fetch(u as string, i),
      backoffMs: 1,
    });
    expect((await store.list('w1')).map((s) => s.recording_id)).toEqual(['rec1']);
    const progress: number[] = [];
    await second.resume('rec1', (p) => progress.push(p.sent));

    expect(backend.puts).toEqual([2, 3]);
    expect(backend.registered).toEqual([
      { part_number: 1, etag: 'etag-1' },
      { part_number: 2, etag: 'etag-2' },
      { part_number: 3, etag: 'etag-3' },
    ]);
    expect(progress[0]).toBe(4); // resumed at the bytes already stored
    expect(progress.at(-1)).toBe(10);
    expect(await store.get('rec1')).toBeNull(); // cleared only after register
  });

  it('the server is authoritative: a part this browser thought was sent but storage lacks is re-sent', async () => {
    const backend = new FakeBackend(8);
    const store = new MemoryUploadStore();
    const blob = new Blob([new Uint8Array(8)]);
    await store.put({
      recording_id: 'rec1',
      workspace_id: 'w1',
      title: 't',
      filename: 'r.webm',
      mime_type: 'video/webm',
      size_bytes: 8,
      duration_sec: null,
      source: 'recording',
      fingerprint: null,
      blob,
      done: { 1: 'etag-1', 2: 'stale' },
      updated_at: '',
    });
    backend.stored.set(1, 'etag-1');
    await new ResumableUpload(backend, store, { fetchImpl: backend.fetch, backoffMs: 1 }).resume(
      'rec1',
      () => undefined,
    );
    expect(backend.puts).toEqual([2]);
    expect(backend.registered?.map((p) => p.etag)).toEqual(['etag-1', 'etag-2']);
  });

  it('a file upload resumes only with the same file picked again', async () => {
    const backend = new FakeBackend(8);
    const store = new MemoryUploadStore();
    const file = new File([new Uint8Array(8)], 'demo.webm', {
      type: 'video/webm',
      lastModified: 1000,
    });
    backend.online = false;
    const up = new ResumableUpload(backend, store, {
      fetchImpl: backend.fetch,
      backoffMs: 1,
      isOnline: () => true,
    });
    await expect(
      up.start({ ...input(file, 'file'), filename: 'demo.webm' }, () => undefined),
    ).rejects.toThrow();
    expect((await store.get('rec1'))?.blob).toBeNull(); // the file itself is not copied into the browser store
    backend.online = true;
    const other = new File([new Uint8Array(8)], 'other.webm', {
      type: 'video/webm',
      lastModified: 1000,
    });
    await expect(up.resume('rec1', () => undefined, other)).rejects.toBeInstanceOf(
      FileMismatchError,
    );
    await expect(up.resume('rec1', () => undefined)).rejects.toBeInstanceOf(FileMismatchError);
    await up.resume('rec1', () => undefined, file);
    expect(backend.registered?.length).toBe(2);
  });

  it('while offline it waits for the connection instead of spending retries', async () => {
    const backend = new FakeBackend(8);
    const targets = await backend.uploadUrl();
    let online = false;
    let waited = 0;
    const offlineSeen: boolean[] = [];
    const parts = await uploadParts(
      new Blob([new Uint8Array(8)]),
      targets,
      (p) => offlineSeen.push(!!p.offline),
      {
        fetchImpl: backend.fetch,
        isOnline: () => online,
        waitForOnline: async () => {
          waited++;
          online = true;
        },
        backoffMs: 1,
      },
    );
    expect(waited).toBe(1);
    expect(offlineSeen).toContain(true);
    expect(parts.map((p) => p.part_number)).toEqual([1, 2]);
    expect(backend.puts).toEqual([1, 2]); // no failed attempts were made while offline
  });

  it('discard deletes on the server and forgets locally even if the server call fails', async () => {
    const store = new MemoryUploadStore();
    await store.put({
      recording_id: 'rec9',
      workspace_id: 'w1',
      title: 't',
      filename: 'f',
      mime_type: 'video/webm',
      size_bytes: 1,
      duration_sec: null,
      source: 'recording',
      fingerprint: null,
      blob: null,
      done: {},
      updated_at: '',
    });
    const up = new ResumableUpload(new FakeBackend(1), store);
    await expect(
      up.discard('rec9', async () => {
        throw new Error('gone');
      }),
    ).rejects.toThrow('gone');
    expect(await store.get('rec9')).toBeNull();
  });
});
