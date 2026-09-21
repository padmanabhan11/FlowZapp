import { UploadTargets } from '../../core/api.types';

export interface UploadProgress {
  sent: number;
  total: number;
  retrying: boolean;
}

/**
 * Direct-to-storage multipart upload (03 §8: "the Laravel API never proxies
 * media bytes"). Each part is PUT to its presigned URL with retries and
 * backoff, so one network interruption resumes instead of restarting (F8,
 * C3). Returns the ETags the API needs to complete the upload.
 */
export async function uploadParts(
  blob: Blob,
  targets: UploadTargets,
  onProgress: (p: UploadProgress) => void,
  fetchImpl: typeof fetch = fetch,
): Promise<{ part_number: number; etag: string }[]> {
  const out: { part_number: number; etag: string }[] = [];
  let sent = 0;
  for (const part of targets.parts) {
    const start = (part.part_number - 1) * targets.part_size;
    const chunk = blob.slice(start, Math.min(start + targets.part_size, blob.size));
    let attempt = 0;
    for (;;) {
      try {
        const res = await fetchImpl(part.url, { method: 'PUT', body: chunk });
        if (!res.ok) throw new Error(`part ${part.part_number}: HTTP ${res.status}`);
        const etag = (res.headers.get('ETag') ?? '').replace(/"/g, '');
        if (!etag) throw new Error(`part ${part.part_number}: no ETag (check the bucket CORS ExposeHeaders)`);
        out.push({ part_number: part.part_number, etag });
        sent += chunk.size;
        onProgress({ sent, total: blob.size, retrying: false });
        break;
      } catch (e) {
        attempt++;
        if (attempt > 5) throw e;
        onProgress({ sent, total: blob.size, retrying: true });
        await new Promise((r) => setTimeout(r, Math.min(30000, 1000 * 2 ** attempt)));
      }
    }
  }
  return out;
}
