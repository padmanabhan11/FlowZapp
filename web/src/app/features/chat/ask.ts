import { DatePipe } from '@angular/common';
import {
  Component,
  OnDestroy,
  OnInit,
  computed,
  effect,
  inject,
  input,
  signal,
} from '@angular/core';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { ButtonModule } from 'primeng/button';
import { InputTextModule } from 'primeng/inputtext';
import { ChatMessage, ChatSessionMeta, Citation, DocumentFull } from '../../core/api.types';
import { DocumentApi } from '../../core/document.api';
import { ChatApi, citationFragment } from '../../core/retrieval.api';
import { SessionStore } from '../../core/session.store';

/** A message being streamed in; becomes a ChatMessage on "done". */
interface Pending {
  content: string;
  citations: Citation[];
}

/**
 * S15 — Ask. Sessions rail on the left, the conversation in the middle, and a
 * scope panel on the right when the session is pinned to one document.
 * Answers stream token by token; citation chips open the source at the step
 * or section; a refusal is rendered as its own block with Record / Request
 * actions rather than as an apologetic paragraph (F5, non-negotiable 4).
 */
@Component({
  selector: 'app-ask',
  imports: [DatePipe, FormsModule, RouterLink, ButtonModule, InputTextModule],
  templateUrl: './ask.html',
  styleUrl: './ask.scss',
})
export class Ask implements OnInit, OnDestroy {
  private readonly api = inject(ChatApi);
  private readonly docs = inject(DocumentApi);
  private readonly sessionStore = inject(SessionStore);
  private abort: AbortController | null = null;

  /** ?q= from the omnibox, ?doc= to open scoped to a document. */
  readonly q = input<string>('');
  readonly doc = input<string>('');

  readonly sessions = signal<ChatSessionMeta[]>([]);
  readonly active = signal<ChatSessionMeta | null>(null);
  readonly messages = signal<ChatMessage[]>([]);
  readonly pending = signal<Pending | null>(null);
  readonly draft = signal('');
  readonly busy = signal(false);
  readonly error = signal<string | null>(null);
  readonly scopeDoc = signal<DocumentFull | null>(null);
  readonly requested = signal<Set<string>>(new Set());
  readonly canRecord = computed(() =>
    ['admin', 'approver', 'editor'].includes(this.sessionStore.current()?.role ?? ''),
  );

  constructor() {
    effect(() => {
      const q = this.q();
      if (q) this.draft.set(q);
    });
  }

  async ngOnInit(): Promise<void> {
    await this.loadSessions();
    const docId = this.doc();
    if (docId) {
      await this.newSession(docId);
    } else if (this.sessions().length) {
      await this.open(this.sessions()[0]);
    }
    if (this.q()) await this.send();
  }

  ngOnDestroy(): void {
    this.abort?.abort();
  }

  async loadSessions(): Promise<void> {
    try {
      this.sessions.set(await this.api.sessions());
    } catch {
      this.sessions.set([]);
    }
  }

  async newSession(scopeDocumentId: string | null = null): Promise<void> {
    try {
      const s = await this.api.createSession({ scope_document_id: scopeDocumentId });
      this.sessions.update((list) => [s, ...list]);
      await this.open(s);
    } catch {
      this.error.set('A new conversation could not be started.');
    }
  }

  async open(s: ChatSessionMeta): Promise<void> {
    this.abort?.abort();
    this.active.set(s);
    this.pending.set(null);
    this.error.set(null);
    this.scopeDoc.set(null);
    try {
      this.messages.set(await this.api.messages(s.id));
    } catch {
      this.messages.set([]);
      this.error.set('This conversation could not be loaded.');
    }
    if (s.scope_document_id) {
      this.docs
        .get(s.scope_document_id)
        .then((d) => this.scopeDoc.set(d))
        .catch(() => this.scopeDoc.set(null));
    }
  }

  clearScope(): void {
    void this.newSession(null);
  }

  async send(): Promise<void> {
    const content = this.draft().trim();
    if (!content || this.busy()) return;
    if (!this.active()) await this.newSession(null);
    const s = this.active();
    if (!s) return;

    this.busy.set(true);
    this.error.set(null);
    this.draft.set('');
    this.messages.update((m) => [
      ...m,
      {
        id: `local-${Date.now()}`,
        role: 'user',
        content,
        citations: null,
        refused: false,
        latency_ms: null,
        rated_helpful: null,
        created_at: new Date().toISOString(),
      },
    ]);
    this.pending.set({ content: '', citations: [] });
    this.abort = new AbortController();

    try {
      await this.api.askStream(
        s.id,
        content,
        {
          onRetrieval: (citations) => this.pending.update((p) => (p ? { ...p, citations } : p)),
          onToken: (text) =>
            this.pending.update((p) => (p ? { ...p, content: p.content + text } : p)),
          onDone: (done) => {
            const p = this.pending();
            this.messages.update((m) => [
              ...m,
              {
                id: done.message_id,
                role: 'assistant',
                content: p?.content ?? '',
                citations: done.citations,
                refused: done.refused,
                latency_ms: done.latency_ms,
                rated_helpful: null,
                created_at: new Date().toISOString(),
              },
            ]);
            this.pending.set(null);
          },
        },
        this.abort.signal,
      );
      if (s.title === null) {
        const title = content.slice(0, 80);
        this.active.set({ ...s, title });
        this.sessions.update((list) => list.map((x) => (x.id === s.id ? { ...x, title } : x)));
      }
    } catch (e: unknown) {
      if ((e as { name?: string }).name !== 'AbortError') {
        this.error.set(
          (e as { status?: number }).status === 402
            ? 'This workspace’s plan does not include the assistant.'
            : 'The assistant could not answer. Try again.',
        );
      }
      this.pending.set(null);
    } finally {
      this.busy.set(false);
      this.abort = null;
    }
  }

  async rate(m: ChatMessage, helpful: boolean): Promise<void> {
    if (m.id.startsWith('local-')) return;
    try {
      await this.api.rate(m.id, helpful);
      this.messages.update((list) =>
        list.map((x) => (x.id === m.id ? { ...x, rated_helpful: helpful } : x)),
      );
    } catch {
      this.error.set('The rating could not be saved.');
    }
  }

  /** Refusals are already recorded as knowledge gaps server-side (S20); this just tells the asker so. */
  request(m: ChatMessage): void {
    this.requested.update((set) => new Set(set).add(m.id));
  }

  /** The question a refusal answered — used to pre-fill "Record it" (S20 acceptance). */
  questionBefore(m: ChatMessage): string {
    const list = this.messages();
    const i = list.findIndex((x) => x.id === m.id);
    for (let j = i - 1; j >= 0; j--) if (list[j].role === 'user') return list[j].content;
    return '';
  }

  fragment(c: Citation): string | undefined {
    return citationFragment(c);
  }

  /** Enter sends; Shift+Enter inserts a newline. */
  onKey(ev: KeyboardEvent): void {
    if (ev.key === 'Enter' && !ev.shiftKey) {
      ev.preventDefault();
      void this.send();
    }
  }
}
