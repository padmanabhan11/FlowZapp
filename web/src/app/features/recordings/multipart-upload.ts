import { UploadTargets } from '../../core/api.types';

export interface UploadProgress {
  sent: number;
  total: number;
  retrying: boolean;
  offline?: boolean;
}

export interface UploadOptions {
  /** Parts already stored (from a previous attempt): part_number → etag. They are not re-sent. */
  done?: Record<number, string>;
  /** Called after each part lands, so progress survives a reload. */
  onPart?: (partNumber: number, etag: string) => void | Promise<void>;
  /** Waits for connectivity instead of spending retries while offline. */
  waitForOnline?: () => Promise<void>;
  isOnline?: () => boolean;
  fetchImpl?: typeof fetch;
  /** Backoff base in ms (tests shorten it). */
  backoffMs?: number;
  signal?: AbortSignal;
}

function defaultIsOnline(): boolean {
  return typeof navigator === 'undefined' || navigator.onLine !== false;
}

function defaultWaitForOnline(): Promise<void> {
  if (typeof window === 'undefined' || defaultIsOnline()) return Promise.resolve();
  return new Promise((resolve) =>
    window.addEventListener('online', () => resolve(), { once: true }),
  );
}

/**
 * Direct-to-storage multipart upload (03 §8: "the Laravel API never proxies
 * media bytes"). Each part is PUT to its presigned URL with retries and
 * backoff, so a network interruption resumes instead of restarting (C3).
 * While the browser reports offline it waits for the connection rather than
 * burning retries. Parts already stored are skipped. Returns every part's
 * ETag in part order, including previously stored ones.
 */
export async function uploadParts(
  blob: Blob,
  targets: UploadTargets,
  onProgress: (p: UploadProgress) => void,
  opts: UploadOptions | typeof fetch = {},
): Promise<{ part_number: number; etag: string }[]> {
  const o: UploadOptions = typeof opts === 'function' ? { fetchImpl: opts } : opts;
  const fetchImpl = o.fetchImpl ?? fetch;
  const isOnline = o.isOnline ?? defaultIsOnline;
  const waitForOnline = o.waitForOnline ?? defaultWaitForOnline;
  const backoff = o.backoffMs ?? 1000;
  const done: Record<number, string> = { ...(o.done ?? {}) };

  const partBytes = (n: number) =>
    Math.max(0, Math.min(targets.part_size, blob.size - (n - 1) * targets.part_size));
  let sent = Object.keys(done).reduce((acc, n) => acc + partBytes(Number(n)), 0);
  onProgress({ sent, total: blob.size, retrying: false });

  for (const part of targets.parts) {
    if (done[part.part_number]) continue;
    const start = (part.part_number - 1) * targets.part_size;
    const chunk = blob.slice(start, Math.min(start + targets.part_size, blob.size));
    let attempt = 0;
    for (;;) {
      if (o.signal?.aborted) throw new DOMException('Upload cancelled', 'AbortError');
      if (!isOnline()) {
        onProgress({ sent, total: blob.size, retrying: true, offline: true });
        await waitForOnline();
      }
      try {
        const res = await fetchImpl(part.url, { method: 'PUT', body: chunk, signal: o.signal });
        if (!res.ok) throw new Error(`part ${part.part_number}: HTTP ${res.status}`);
        const etag = (res.headers.get('ETag') ?? '').replace(/"/g, '');
        if (!etag)
          throw new Error(
            `part ${part.part_number}: no ETag (check the bucket CORS ExposeHeaders)`,
          );
        done[part.part_number] = etag;
        await o.onPart?.(part.part_number, etag);
        sent += chunk.size;
        onProgress({ sent, total: blob.size, retrying: false });
        break;
      } catch (e) {
        if ((e as { name?: string }).name === 'AbortError') throw e;
        attempt++;
        if (attempt > 5) throw e;
        onProgress({ sent, total: blob.size, retrying: true, offline: !isOnline() });
        await new Promise((r) => setTimeout(r, Math.min(30000, backoff * 2 ** attempt)));
      }
    }
  }
  return Object.keys(done)
    .map(Number)
    .sort((a, b) => a - b)
    .map((n) => ({ part_number: n, etag: done[n] }));
}
