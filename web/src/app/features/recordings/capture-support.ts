/**
 * C1-T5 — cross-browser capture. What each target browser supports today:
 *
 * | Browser        | getDisplayMedia | MediaRecorder container            |
 * |----------------|-----------------|------------------------------------|
 * | Chrome, Edge   | yes             | WebM (VP9/VP8 + Opus)              |
 * | Firefox        | yes             | WebM (VP8 + Opus)                  |
 * | Safari 14.1+   | yes (macOS)     | MP4 (H.264 + AAC) — no WebM         |
 * | iOS / Android  | no screen share | —                                   |
 *
 * So the container is chosen from what the recorder actually supports, and the
 * uploaded file is labelled with that container — never assumed to be WebM.
 * Mobile browsers cannot capture a screen; they get the upload path with the
 * reason stated, not a button that fails.
 */
export interface RecorderFormat {
  /** Passed to MediaRecorder; '' lets the browser choose. */
  mimeType: string;
  /** What the upload is labelled as (the API accepts video/webm and video/mp4). */
  container: 'video/webm' | 'video/mp4';
  ext: 'webm' | 'mp4';
}

export const RECORDER_CANDIDATES = [
  'video/webm;codecs=vp9,opus',
  'video/webm;codecs=vp8,opus',
  'video/webm',
  'video/mp4;codecs=avc1.42E01E,mp4a.40.2',
  'video/mp4;codecs=avc1,mp4a',
  'video/mp4',
] as const;

export function pickRecorderFormat(isTypeSupported: (t: string) => boolean): RecorderFormat {
  const mime = RECORDER_CANDIDATES.find((m) => {
    try {
      return isTypeSupported(m);
    } catch {
      return false;
    }
  });
  if (!mime) return { mimeType: '', container: 'video/webm', ext: 'webm' }; // resolved from the recorder once it starts
  return mime.startsWith('video/mp4')
    ? { mimeType: mime, container: 'video/mp4', ext: 'mp4' }
    : { mimeType: mime, container: 'video/webm', ext: 'webm' };
}

/** The container the recorder actually produced (MediaRecorder.mimeType after start, or a chunk's type). */
export function containerOf(
  actual: string | undefined | null,
  fallback: RecorderFormat,
): RecorderFormat {
  const t = (actual ?? '').toLowerCase();
  if (t.startsWith('video/mp4') || t.startsWith('audio/mp4'))
    return { mimeType: t, container: 'video/mp4', ext: 'mp4' };
  if (t.startsWith('video/webm') || t.startsWith('audio/webm'))
    return { mimeType: t, container: 'video/webm', ext: 'webm' };
  return fallback;
}

export interface CaptureSupport {
  supported: boolean;
  reason: string | null;
}

export function captureSupport(
  nav: Partial<Navigator> | undefined,
  recorder: unknown,
): CaptureSupport {
  if (!nav) return { supported: false, reason: 'Recording needs a browser.' };
  const ua = nav.userAgent ?? '';
  if (/iPhone|iPad|iPod|Android/i.test(ua)) {
    return {
      supported: false,
      reason:
        'Phones and tablets cannot share their screen from the browser. Record on a computer, or upload a screen recording from this device.',
    };
  }
  if (!nav.mediaDevices?.getDisplayMedia) {
    return {
      supported: false,
      reason:
        'This browser cannot share its screen. Use a current version of Chrome, Edge, Firefox or Safari, or upload a file.',
    };
  }
  if (typeof recorder === 'undefined' || recorder === null) {
    return {
      supported: false,
      reason:
        'This browser cannot record video. Use a current version of Chrome, Edge, Firefox or Safari, or upload a file.',
    };
  }
  return { supported: true, reason: null };
}
