import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import { Recording, ResumeTargets, UploadTargets } from './api.types';
import { SessionStore } from './session.store';

@Injectable({ providedIn: 'root' })
export class RecordingApi {
  private readonly http = inject(HttpClient);
  private readonly session = inject(SessionStore);

  async uploadUrl(body: {
    filename: string;
    mime_type: string;
    size_bytes: number;
    duration_sec?: number;
    space_id: string;
    title?: string;
  }): Promise<UploadTargets> {
    await this.session.ensureCsrf();
    const res = await firstValueFrom(
      this.http.post<{ data: UploadTargets }>('/api/v1/recordings/upload-url', body),
    );
    return res.data;
  }

  /** C3: which parts storage already holds, plus fresh URLs for the rest. */
  async resume(recordingId: string): Promise<ResumeTargets> {
    const res = await firstValueFrom(
      this.http.get<{ data: ResumeTargets }>(`/api/v1/recordings/${recordingId}/upload`),
    );
    return res.data;
  }

  async register(
    recordingId: string,
    parts: { part_number: number; etag: string }[],
    durationSec?: number,
  ): Promise<Recording> {
    await this.session.ensureCsrf();
    const res = await firstValueFrom(
      this.http.post<{ data: Recording }>('/api/v1/recordings', {
        recording_id: recordingId,
        parts,
        duration_sec: durationSec,
      }),
    );
    return res.data;
  }

  async list(): Promise<Recording[]> {
    const res = await firstValueFrom(this.http.get<{ data: Recording[] }>('/api/v1/recordings'));
    return res.data;
  }

  async get(id: string): Promise<Recording> {
    const res = await firstValueFrom(
      this.http.get<{ data: Recording }>(`/api/v1/recordings/${id}`),
    );
    return res.data;
  }

  async rename(id: string, title: string): Promise<Recording> {
    await this.session.ensureCsrf();
    const res = await firstValueFrom(
      this.http.patch<{ data: Recording }>(`/api/v1/recordings/${id}`, { title }),
    );
    return res.data;
  }

  async playbackUrl(id: string): Promise<string> {
    const res = await firstValueFrom(
      this.http.get<{ data: { url: string } }>(`/api/v1/recordings/${id}/playback-url`),
    );
    return res.data.url;
  }

  async retry(id: string): Promise<Recording> {
    await this.session.ensureCsrf();
    const res = await firstValueFrom(
      this.http.post<{ data: Recording }>(`/api/v1/recordings/${id}/retry`, {}),
    );
    return res.data;
  }

  async generate(id: string): Promise<void> {
    await this.session.ensureCsrf();
    await firstValueFrom(this.http.post(`/api/v1/recordings/${id}/generate`, {}));
  }

  async delete(id: string): Promise<void> {
    await this.session.ensureCsrf();
    await firstValueFrom(this.http.delete(`/api/v1/recordings/${id}`));
  }
}

@Injectable({ providedIn: 'root' })
export class AssetApi {
  private readonly http = inject(HttpClient);
  private readonly cache = new Map<string, { url: string; at: number }>();

  /** Signed frame URL, cached for 10 minutes (server TTL is 15). */
  async url(assetId: string): Promise<string> {
    const hit = this.cache.get(assetId);
    if (hit && Date.now() - hit.at < 10 * 60 * 1000) return hit.url;
    const res = await firstValueFrom(
      this.http.get<{ data: { url: string } }>(`/api/v1/assets/${assetId}/url`),
    );
    this.cache.set(assetId, { url: res.data.url, at: Date.now() });
    return res.data.url;
  }
}
