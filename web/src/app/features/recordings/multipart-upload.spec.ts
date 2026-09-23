import { uploadParts } from './multipart-upload';

describe('uploadParts', () => {
  it('PUTs each part to its presigned URL, retries a failed part, and returns ETags in order', async () => {
    const blob = new Blob([new Uint8Array(10)]);
    const targets = {
      recording_id: 'r',
      upload_id: 'u',
      part_size: 4,
      parts: [1, 2, 3].map((n) => ({ part_number: n, url: `https://s/${n}` })),
    };
    let calls = 0;
    const fetchImpl = (async (url: string) => {
      calls++;
      if (url.endsWith('/2') && calls === 2) return new Response(null, { status: 500 });
      return new Response(null, { status: 200, headers: { ETag: `"etag-${url.slice(-1)}"` } });
    }) as unknown as typeof fetch;
    const progress: number[] = [];
    const out = await uploadParts(blob, targets, (p) => progress.push(p.sent), fetchImpl);
    expect(out).toEqual([
      { part_number: 1, etag: 'etag-1' },
      { part_number: 2, etag: 'etag-2' },
      { part_number: 3, etag: 'etag-3' },
    ]);
    expect(calls).toBe(4);
    expect(progress.at(-1)).toBe(10);
  }, 15000);
});
