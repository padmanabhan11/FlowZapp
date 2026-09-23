import { captureSupport, containerOf, pickRecorderFormat } from './capture-support';

/** Support tables as the browsers report them (MediaRecorder.isTypeSupported), per C1-T5. */
const BROWSERS: Record<string, (t: string) => boolean> = {
  chrome: (t) => t.startsWith('video/webm') || t === 'video/mp4', // Chrome 126+ also reports plain mp4
  edge: (t) => t.startsWith('video/webm'),
  firefox: (t) => t === 'video/webm' || t === 'video/webm;codecs=vp8,opus',
  safari: (t) => t.startsWith('video/mp4'),
  none: () => false,
};

describe('capture support across browsers (C1-T5)', () => {
  it('Chrome and Edge record VP9 WebM', () => {
    for (const b of ['chrome', 'edge']) {
      const f = pickRecorderFormat(BROWSERS[b]);
      expect(f.mimeType).toBe('video/webm;codecs=vp9,opus');
      expect(f.container).toBe('video/webm');
    }
  });

  it('Firefox falls back to VP8 WebM', () => {
    const f = pickRecorderFormat(BROWSERS['firefox']);
    expect(f.mimeType).toBe('video/webm;codecs=vp8,opus');
    expect(f.ext).toBe('webm');
  });

  it('Safari records MP4 and the upload is labelled MP4, not WebM', () => {
    const f = pickRecorderFormat(BROWSERS['safari']);
    expect(f.container).toBe('video/mp4');
    expect(f.ext).toBe('mp4');
  });

  it('when nothing is declared supported, the real recorder type decides', () => {
    const f = pickRecorderFormat(BROWSERS['none']);
    expect(f.mimeType).toBe('');
    expect(containerOf('video/mp4; codecs="avc1.42E01E, mp4a.40.2"', f).container).toBe(
      'video/mp4',
    );
    expect(containerOf('video/x-matroska;codecs=avc1', f).container).toBe('video/webm');
  });

  it('isTypeSupported that throws is treated as unsupported', () => {
    const f = pickRecorderFormat((t) => {
      if (t.includes('vp9')) throw new Error('bad');
      return t.startsWith('video/webm');
    });
    expect(f.mimeType).toBe('video/webm;codecs=vp8,opus');
  });

  it('phones and browsers without screen sharing get the upload path with a reason', () => {
    const md = { getDisplayMedia: () => Promise.resolve() } as unknown as MediaDevices;
    expect(
      captureSupport(
        { userAgent: 'Mozilla/5.0 (Macintosh) Safari/605', mediaDevices: md },
        class {},
      ).supported,
    ).toBe(true);
    const ios = captureSupport(
      { userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0) Safari', mediaDevices: md },
      class {},
    );
    expect(ios.supported).toBe(false);
    expect(ios.reason).toContain('upload');
    expect(
      captureSupport({ userAgent: 'x', mediaDevices: {} as MediaDevices }, class {}).supported,
    ).toBe(false);
    expect(captureSupport({ userAgent: 'x', mediaDevices: md }, undefined).supported).toBe(false);
  });
});
