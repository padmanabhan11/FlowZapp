import { Recording, ResumeTargets, UploadTargets } from '../../core/api.types';
import { UploadOptions, UploadProgress, uploadParts } from './multipart-upload';
import { UploadSession, UploadSessionStore, fingerprint } from './upload-store';

/** The API calls the upload needs; RecordingApi satisfies it. */
export interface UploadApi {
  uploadUrl(body: {
    filename: string;
    mime_type: string;
    size_bytes: number;
    duration_sec?: number;
    space_id: string;
    title?: string;
  }): Promise<UploadTargets>;
  resume(recordingId: string): Promise<ResumeTargets>;
  register(
    recordingId: string,
    parts: { part_number: number; etag: string }[],
    durationSec?: number,
  ): Promise<Recording>;
}

export class FileMismatchError extends Error {
  constructor() {
    super(
      'That is not the same file. Choose the file you were uploading, or discard the unfinished upload.',
    );
  }
}

/**
 * C3 — an upload that survives network drops and page reloads.
 *
 * start(): create the recording, persist a session, upload, register.
 * resume(): ask the server which parts it already holds (authoritative),
 * merge with what this browser recorded, send only the rest, register.
 * The session is deleted only after register succeeds, so any failure
 * before that leaves something to resume.
 */
export class ResumableUpload {
  constructor(
    private readonly api: UploadApi,
    private readonly store: UploadSessionStore,
    private readonly uploadOpts: Omit<UploadOptions, 'done' | 'onPart'> = {},
  ) {}

  async start(
    input: {
      blob: Blob;
      filename: string;
      mime_type: string;
      title?: string;
      duration_sec: number | null;
      space_id: string;
      workspace_id: string;
      source: 'recording' | 'file';
    },
    onProgress: (p: UploadProgress) => void,
    onCreated?: (recordingId: string) => void,
  ): Promise<Recording> {
    const targets = await this.api.uploadUrl({
      filename: input.filename,
      mime_type: input.mime_type,
      size_bytes: input.blob.size,
      duration_sec: input.duration_sec ?? undefined,
      space_id: input.space_id,
      title: input.title,
    });
    onCreated?.(targets.recording_id);
    const session: UploadSession = {
      recording_id: targets.recording_id,
      workspace_id: input.workspace_id,
      title: input.title ?? input.filename,
      filename: input.filename,
      mime_type: input.mime_type,
      size_bytes: input.blob.size,
      duration_sec: input.duration_sec,
      source: input.source,
      fingerprint: input.blob instanceof File ? fingerprint(input.blob) : null,
      blob: input.source === 'recording' ? input.blob : null,
      done: {},
      updated_at: new Date().toISOString(),
    };
    await this.store.put(session);
    return this.send(session, input.blob, targets, onProgress);
  }

  /** Resume an unfinished upload. For a file upload, pass the file the person picked again. */
  async resume(
    recordingId: string,
    onProgress: (p: UploadProgress) => void,
    pickedFile?: File,
  ): Promise<Recording> {
    const session = await this.store.get(recordingId);
    if (!session) throw new Error('No unfinished upload with that id on this browser.');
    const blob = session.source === 'recording' ? session.blob : (pickedFile ?? null);
    if (!blob) throw new FileMismatchError();
    if (
      session.source === 'file' &&
      (!(blob instanceof File) || fingerprint(blob) !== session.fingerprint)
    )
      throw new FileMismatchError();
    if (blob.size !== session.size_bytes) throw new FileMismatchError();

    const t = await this.api.resume(recordingId);
    // The server is authoritative for what landed; local entries fill in only if it agrees on the part.
    const done: Record<number, string> = {};
    for (const p of t.parts_done) done[p.part_number] = p.etag;
    session.done = done;
    await this.store.put(session);
    const allParts = [
      ...t.parts_done.map((p) => ({ part_number: p.part_number, url: '' })),
      ...t.parts,
    ].sort((a, b) => a.part_number - b.part_number);
    return this.send(session, blob, { ...t, parts: allParts }, onProgress);
  }

  async discard(recordingId: string, deleteOnServer: (id: string) => Promise<void>): Promise<void> {
    try {
      await deleteOnServer(recordingId);
    } finally {
      await this.store.delete(recordingId);
    }
  }

  private async send(
    session: UploadSession,
    blob: Blob,
    targets: UploadTargets,
    onProgress: (p: UploadProgress) => void,
  ): Promise<Recording> {
    const parts = await uploadParts(blob, targets, onProgress, {
      ...this.uploadOpts,
      done: session.done,
      onPart: async (n, etag) => {
        session.done[n] = etag;
        session.updated_at = new Date().toISOString();
        await this.store.put(session);
      },
    });
    const rec = await this.api.register(
      session.recording_id,
      parts,
      session.duration_sec ?? undefined,
    );
    await this.store.delete(session.recording_id);
    return rec;
  }
}
