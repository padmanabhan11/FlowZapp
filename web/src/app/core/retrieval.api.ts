import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import {
  ChatMessage,
  ChatSessionMeta,
  Citation,
  DocType,
  KnowledgeGap,
  SearchResponse,
} from './api.types';
import { SessionStore } from './session.store';
import { WorkspaceStore } from './workspace.store';

export interface SearchParams {
  query: string;
  space_ids?: string[];
  filters?: { doc_type?: DocType | null; owner_id?: string | null };
  limit?: number;
  instant?: boolean;
}

/** Streaming callbacks for POST /chat/sessions/{id}/messages with Accept: text/event-stream. */
export interface AskStream {
  onRetrieval?: (citations: Citation[]) => void;
  onToken: (text: string) => void;
  onDone: (done: {
    message_id: string;
    refused: boolean;
    latency_ms: number;
    citations: Citation[];
  }) => void;
}

/** Turns a citation's section_ref into the reader fragment (reader.html gives steps id="step-N"). */
export function citationFragment(c: Pick<Citation, 'section_ref'>): string | undefined {
  const [kind, ref] = c.section_ref.split(':', 2);
  if (kind === 'step') return `step-${ref}`;
  if (kind === 'section') return `section-${ref}`;
  if (kind === 'block' && ref) return `block-${ref}`;
  return undefined;
}

@Injectable({ providedIn: 'root' })
export class SearchApi {
  private readonly http = inject(HttpClient);
  private readonly session = inject(SessionStore);

  async search(params: SearchParams): Promise<SearchResponse> {
    await this.session.ensureCsrf();
    return (
      await firstValueFrom(this.http.post<{ data: SearchResponse }>('/api/v1/search', params))
    ).data;
  }
}

@Injectable({ providedIn: 'root' })
export class ChatApi {
  private readonly http = inject(HttpClient);
  private readonly session = inject(SessionStore);
  private readonly workspace = inject(WorkspaceStore);

  async sessions(): Promise<ChatSessionMeta[]> {
    return (
      await firstValueFrom(this.http.get<{ data: ChatSessionMeta[] }>('/api/v1/chat/sessions'))
    ).data;
  }

  async createSession(
    body: { scope_document_id?: string | null; title?: string | null } = {},
  ): Promise<ChatSessionMeta> {
    await this.session.ensureCsrf();
    return (
      await firstValueFrom(this.http.post<{ data: ChatSessionMeta }>('/api/v1/chat/sessions', body))
    ).data;
  }

  async messages(sessionId: string): Promise<ChatMessage[]> {
    return (
      await firstValueFrom(
        this.http.get<{ data: ChatMessage[] }>(`/api/v1/chat/sessions/${sessionId}/messages`),
      )
    ).data;
  }

  /** Non-streaming ask (used by tests and as the fallback when SSE is unavailable). */
  async ask(sessionId: string, content: string): Promise<ChatMessage> {
    await this.session.ensureCsrf();
    return (
      await firstValueFrom(
        this.http.post<{ data: ChatMessage }>(`/api/v1/chat/sessions/${sessionId}/messages`, {
          content,
        }),
      )
    ).data;
  }

  /**
   * Streaming ask over SSE. HttpClient cannot consume a stream progressively,
   * so this uses fetch with the same credentials the interceptor would attach:
   * cookies, X-Workspace-Id and the XSRF token. Falls back to the JSON path
   * when the server answers without an event stream.
   */
  async askStream(
    sessionId: string,
    content: string,
    handlers: AskStream,
    signal?: AbortSignal,
  ): Promise<void> {
    await this.session.ensureCsrf();
    const wsId = this.workspace.id();
    const res = await fetch(`/api/v1/chat/sessions/${sessionId}/messages`, {
      method: 'POST',
      credentials: 'include',
      signal,
      headers: {
        Accept: 'text/event-stream',
        'Content-Type': 'application/json',
        'X-XSRF-TOKEN': ChatApi.xsrfToken(),
        ...(wsId ? { 'X-Workspace-Id': wsId } : {}),
      },
      body: JSON.stringify({ content }),
    });
    if (!res.ok) throw Object.assign(new Error('ask failed'), { status: res.status });

    if (!res.headers.get('content-type')?.includes('text/event-stream') || !res.body) {
      const json = (await res.json()) as { data: ChatMessage };
      handlers.onRetrieval?.(json.data.citations ?? []);
      handlers.onToken(json.data.content);
      handlers.onDone({
        message_id: json.data.id,
        refused: json.data.refused,
        latency_ms: json.data.latency_ms ?? 0,
        citations: json.data.citations ?? [],
      });
      return;
    }

    const reader = res.body.getReader();
    const decoder = new TextDecoder();
    let buffer = '';
    for (;;) {
      const { value, done } = await reader.read();
      if (done) break;
      buffer += decoder.decode(value, { stream: true });
      let idx: number;
      while ((idx = buffer.indexOf('\n\n')) >= 0) {
        const frame = buffer.slice(0, idx);
        buffer = buffer.slice(idx + 2);
        ChatApi.dispatch(frame, handlers);
      }
    }
    if (buffer.trim()) ChatApi.dispatch(buffer, handlers);
  }

  async rate(messageId: string, helpful: boolean): Promise<void> {
    await this.session.ensureCsrf();
    await firstValueFrom(this.http.post(`/api/v1/chat/messages/${messageId}/rating`, { helpful }));
  }

  async knowledgeGaps(): Promise<KnowledgeGap[]> {
    return (
      await firstValueFrom(
        this.http.get<{ data: KnowledgeGap[] }>('/api/v1/analytics/knowledge-gaps'),
      )
    ).data;
  }

  /** Parses one SSE frame ("event: x\ndata: {...}") and routes it. Exported for tests. */
  static dispatch(frame: string, handlers: AskStream): void {
    let event = 'message';
    let data = '';
    for (const line of frame.split('\n')) {
      if (line.startsWith('event:')) event = line.slice(6).trim();
      else if (line.startsWith('data:')) data += line.slice(5).trim();
    }
    if (!data) return;
    const payload = JSON.parse(data) as Record<string, unknown>;
    if (event === 'retrieval') handlers.onRetrieval?.((payload['citations'] as Citation[]) ?? []);
    else if (event === 'token') handlers.onToken(String(payload['text'] ?? ''));
    else if (event === 'done')
      handlers.onDone(payload as unknown as Parameters<AskStream['onDone']>[0]);
  }

  private static xsrfToken(): string {
    const m = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    return m ? decodeURIComponent(m[1]) : '';
  }
}
